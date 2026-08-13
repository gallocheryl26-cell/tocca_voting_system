<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=UTF-8');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_api_errors.log');

define('DB_CONN', __DIR__ . '/../tocca_admin/db_connection.php');

define('TABLE_NOMINATIONS',        'tbl_nominations');
define('TABLE_ANSWERS',            'tbl_nomination_answers');
define('TABLE_FIELDS',             'tbl_nomination_fields');
define('TABLE_AWARD_LINKS',        'tbl_nomination_questions');

define('FINAL_UPLOAD_DIR_FS',      __DIR__ . '/uploads/nominations'); // filesystem
define('FINAL_UPLOAD_DIR_WEB',     'uploads/nominations');            // web path base (relative to site root or current dir)

// Gallery (multi-file images + videos) — uploaded along with the registration,
// later copied into tbl_choice_media when the registration is approved.
define('NOM_MEDIA_DIR_FS',         __DIR__ . '/uploads/nomination_media');
define('NOM_MEDIA_DIR_REL',        'uploads/nomination_media');
define('NOM_MEDIA_IMAGE_MAX',      10 * 1024 * 1024);   // 10 MB
define('NOM_MEDIA_VIDEO_MAX',      100 * 1024 * 1024);  // 100 MB
define('NOM_MEDIA_MAX_FILES',      8);                  // total per registration
define('TABLE_NOMINATION_MEDIA',   'tbl_nomination_media');

/* ---------- helpers ---------- */
function json_ok(array $payload = []) {
  ob_clean(); echo json_encode(['status'=>'success'] + $payload); exit;
}
function json_err(string $msg, int $code = 400, array $extra = []) {
  http_response_code($code); ob_clean(); echo json_encode(['status'=>'error','message'=>$msg] + $extra); exit;
}
set_exception_handler(function(Throwable $e){
  error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
  json_err('Unable to submit your registration. Please try again later.', 500);
});
set_error_handler(function($sev,$msg,$file,$line){ throw new ErrorException($msg,0,$sev,$file,$line); });

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
  json_err('Invalid session. Please refresh the page and try again.', 419);
}

/* ---------- DB ---------- */
if (!file_exists(DB_CONN)) json_err('DB connection file not found.', 500);
require_once DB_CONN;
if (!isset($conn) || !$conn instanceof mysqli) json_err('DB connection not available.', 500);
$conn->set_charset('utf8mb4');
require_once __DIR__ . '/../tocca_admin/includes/establishment_type_event_helpers.php';
require_once __DIR__ . '/nomination_field_helpers.php';
require_once __DIR__ . '/email_check.php';

et_ensure_m2m_schema($conn);

/* ---------- Read event_id ---------- */
$event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
if ($event_id <= 0) json_err('Missing event reference.', 422);

$evStmt = $conn->prepare('SELECT event_id FROM tbl_events WHERE event_id = ? LIMIT 1');
if (!$evStmt) json_err('Unable to verify event.', 500);
$evStmt->bind_param('i', $event_id);
$evStmt->execute();
$evStmt->store_result();
if ($evStmt->num_rows === 0) {
  $evStmt->close();
  json_err('Invalid event reference.', 422);
}
$evStmt->close();

/* ---------- Read establishment type IDs (many-to-many, require ≥1) ---------- */
$establishment_type_ids = [];
if (isset($_POST['establishment_type_ids'])) {
  $establishment_type_ids = et_parse_type_ids($_POST['establishment_type_ids']);
} elseif (isset($_POST['establishment_type_id'])) {
  // Backward compatible with older clients / drafts
  $establishment_type_ids = et_parse_type_ids($_POST['establishment_type_id']);
}
if ($establishment_type_ids === []) {
  json_err('Please select at least one establishment type.', 422);
}
if (!et_types_belong_to_event($conn, $establishment_type_ids, $event_id)) {
  json_err('Please select valid establishment types for this event.', 422);
}
$establishment_type_id = $establishment_type_ids[0]; // primary / legacy column

/* ---------- Small normalizers (optional) ---------- */
function normalize_phone_ph(?string $raw): ?string {
  if ($raw === null) return null;
  $s = trim($raw);
  $digits = preg_replace('/\D+/', '', $s);
  if (strpos($s, '+63') === 0 || strpos($digits, '63') === 0) {
    $digits = preg_replace('/^63/', '', $digits);
    if ($digits === '' || $digits[0] !== '9') return null;
    return '0' . $digits;
  }
  if (strlen($digits) === 10 && $digits[0] === '9') return '0' . $digits;
  if (strlen($digits) === 11 && strpos($digits, '09') === 0) return $digits;
  return null;
}
function is_valid_phone_ph(?string $raw): bool {
  return normalize_phone_ph($raw) !== null;
}
function is_email_field_def(array $def): bool {
  if (($def['type'] ?? '') === 'email') {
    return true;
  }
  if (($def['profile_role'] ?? '') === 'email') {
    return true;
  }
  $label = strtolower(trim((string)($def['name'] ?? '') . ' ' . (string)($def['label'] ?? '')));
  return (bool) preg_match('/\bemail\b/', $label);
}
function is_phone_field_def(array $def): bool {
  if (is_email_field_def($def)) {
    return false;
  }
  if (($def['type'] ?? '') === 'tel') {
    return true;
  }
  if (($def['profile_role'] ?? '') === 'mobile') {
    return true;
  }
  $label = strtolower(trim((string)($def['name'] ?? '') . ' ' . (string)($def['label'] ?? '')));
  if (preg_match('/\bemail\b/', $label)) {
    return false;
  }
  if (preg_match('/\b(mobile|phone|telephone|cell)\b/', $label)) {
    return true;
  }
  return (bool) (preg_match('/\bcontact\b/', $label) && preg_match('/\b(number|no\.?|num|mobile|phone)\b/', $label));
}
function is_mayor_permit_field_def(array $def): bool {
  if (($def['type'] ?? '') === 'file') {
    return false;
  }
  if (($def['profile_role'] ?? '') === 'mayor_permit') {
    return true;
  }
  $label = strtolower(trim((string)($def['name'] ?? '') . ' ' . (string)($def['label'] ?? '')));
  return (bool) preg_match('/mayor|business\s*permit|permit\s*no|permit\s*number/', $label);
}
function is_valid_mayors_permit(?string $raw): bool {
  $s = trim((string)($raw ?? ''));
  if ($s === '') {
    return true;
  }
  return (bool) preg_match('/^MP-\d{4}-ORM-\d{6}$/i', $s);
}
function is_url_field_def(array $def): bool {
  if (($def['type'] ?? '') === 'url') {
    return true;
  }
  if (($def['profile_role'] ?? '') === 'website') {
    return true;
  }
  $label = strtolower(trim((string)($def['name'] ?? '') . ' ' . (string)($def['label'] ?? '')));
  return (bool) preg_match('/\b(website|web\s*site|facebook|instagram|social\s*media|url)\b/', $label);
}
function is_valid_url(?string $raw): bool {
  $s = trim((string)($raw ?? ''));
  if ($s === '') {
    return true;
  }
  if (!preg_match('~^https?://~i', $s)) {
    $s = 'https://' . $s;
  }
  if (filter_var($s, FILTER_VALIDATE_URL) === false) {
    return false;
  }
  $host = parse_url($s, PHP_URL_HOST);
  return is_string($host) && $host !== '' && strpos($host, '.') !== false;
}
function email_field_confirmed(array $def): bool {
  $name = trim((string)($def['name'] ?? ''));
  $fid  = (int)($def['id'] ?? 0);
  if ($name !== '' && !empty($_POST['email_confirmed'][$name])) {
    return true;
  }
  if ($fid > 0 && !empty($_POST['email_confirmed']['fields[' . $fid . ']'])) {
    return true;
  }
  return false;
}
function normalize_mayors_permit(?string $raw): ?string {
  if ($raw === null) return null;
  $s = strtoupper(trim($raw));
  if (preg_match('/(\d{4}).*?(\d{6})/', $s, $m)) return "MP-{$m[1]}-ORM-{$m[2]}";
  return $s !== '' ? $s : null;
}

/* ---------- Load active fields ---------- */
$fieldDefs = []; // id => field metadata
foreach (nf_load_fields($conn, $event_id, ['active_only' => true]) as $r) {
  $fieldDefs[(int) $r['id']] = [
    'id'             => (int) $r['id'],
    'name'           => (string) ($r['name'] ?? ''),
    'label'          => (string) ($r['label'] ?? ''),
    'type'           => (string) ($r['type'] ?? 'text'),
    'is_required'    => !empty($r['is_required']),
    'profile_role'   => (string) ($r['profile_role'] ?? 'custom'),
    'validation_arr' => is_array($r['validation_arr'] ?? null) ? $r['validation_arr'] : [],
  ];
}
if (empty($fieldDefs)) {
  json_err('Registration form is not configured. Please contact the administrator.', 422);
}

/* Track if there is a field named 'establishment_type_id' so we can store as an answer if needed */
$est_type_field_id = null;
foreach ($fieldDefs as $def) {
  if (isset($def['name']) && trim((string)$def['name']) === 'establishment_type_id') {
    $est_type_field_id = (int)$def['id'];
    break;
  }
}

/* ---------- Helpers to get POST/FILES for a field ---------- */
function posted_value_for_field(array $def) {
  $fid   = (int)$def['id'];
  $name  = trim((string)($def['name'] ?? ''));
  $type  = (string)$def['type'];

  // FILE
  if ($type === 'file') {
    if ($name !== '' && !empty($_FILES[$name]) && $_FILES[$name]['error'] === UPLOAD_ERR_OK) {
      return ['file' => $_FILES[$name], 'temp' => $_POST[$name.'_temp'] ?? null];
    }
    if (!empty($_FILES['files']) && isset($_FILES['files']['error'][$fid]) && $_FILES['files']['error'][$fid] === UPLOAD_ERR_OK) {
      $f = [
        'name'     => $_FILES['files']['name'][$fid],
        'type'     => $_FILES['files']['type'][$fid],
        'tmp_name' => $_FILES['files']['tmp_name'][$fid],
        'error'    => $_FILES['files']['error'][$fid],
        'size'     => $_FILES['files']['size'][$fid],
      ];
      $temp = $_POST['files_temp'][$fid] ?? null;
      return ['file'=>$f, 'temp'=>$temp];
    }
    if ($name !== '' && isset($_POST[$name.'_temp'])) {
      return ['file'=>null, 'temp'=>$_POST[$name.'_temp']];
    }
    if (isset($_POST['files_temp'][$fid])) {
      return ['file'=>null, 'temp'=>$_POST['files_temp'][$fid]];
    }
    return ['file'=>null, 'temp'=>null];
  }

  // NON-FILE
  if ($name !== '') {
    return $_POST[$name] ?? null;
  }
  if (isset($_POST['fields'][$fid])) {
    return $_POST['fields'][$fid];
  }
  return null;
}

/* ---------- Save a single uploaded file; returns web path string ---------- */
function save_uploaded_file(array $file, int $nominationId): ?string {
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($file['tmp_name']);
  $extMap = ['image/png'=>'png', 'image/jpeg'=>'jpg', 'image/webp'=>'webp'];
  if (!isset($extMap[$mime])) return null;

  $yearDirFs = rtrim(FINAL_UPLOAD_DIR_FS, '/\\') . '/' . date('Y');
  if (!is_dir($yearDirFs) && !mkdir($yearDirFs, 0775, true) && !is_dir($yearDirFs)) {
    throw new RuntimeException('Failed to create upload folder.');
  }

  $safeBase = preg_replace('/[^a-z0-9]+/i','-', strtolower(pathinfo($file['name'], PATHINFO_FILENAME)));
  $newName  = sprintf('%s-%d-%s.%s', $safeBase ?: 'file', $nominationId, bin2hex(random_bytes(6)), $extMap[$mime]);
  $destFs   = $yearDirFs . '/' . $newName;

  if (!move_uploaded_file($file['tmp_name'], $destFs)) {
    throw new RuntimeException('Failed to move uploaded file.');
  }
  @chmod($destFs, 0644);

  $baseWeb = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
  $yearDirWeb = trim(FINAL_UPLOAD_DIR_WEB, '/').'/'.date('Y');
  $webPath = ($baseWeb ? $baseWeb.'/' : '/').$yearDirWeb.'/'.$newName;

  return $webPath;
}

/* ---------- Gallery (multi-file images + videos) ---------- */
function ensure_nomination_media_table(mysqli $conn): bool {
  $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_NOMINATION_MEDIA . " (
    id INT NOT NULL AUTO_INCREMENT,
    nomination_id INT NOT NULL,
    media_type ENUM('image','video') NOT NULL DEFAULT 'image',
    file_path VARCHAR(500) NOT NULL,
    caption VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_nom_media_nomination (nomination_id, sort_order)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  return $conn->query($sql) !== false;
}

function ensure_nomination_media_storage(int $nominationId): ?string {
  $root = NOM_MEDIA_DIR_FS;
  if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) return null;
  $ht = $root . DIRECTORY_SEPARATOR . '.htaccess';
  if (!file_exists($ht)) {
    @file_put_contents(
      $ht,
      "Options -ExecCGI -Indexes\n" .
      "RemoveHandler .php .phtml .php3 .php4 .php5 .php7\n" .
      "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n" .
      "  Require all denied\n" .
      "</FilesMatch>\n"
    );
  }
  $dir = $root . DIRECTORY_SEPARATOR . $nominationId;
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return null;
  return $dir;
}

function classify_nomination_media(string $mime): ?string {
  static $images = ['image/png','image/jpeg','image/webp','image/gif'];
  static $videos = ['video/mp4','video/webm','video/ogg','video/quicktime'];
  if (in_array($mime, $images, true)) return 'image';
  if (in_array($mime, $videos, true)) return 'video';
  return null;
}

function ext_for_nomination_media(string $mime): string {
  static $map = [
    'image/png'       => 'png',
    'image/jpeg'      => 'jpg',
    'image/webp'      => 'webp',
    'image/gif'       => 'gif',
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/ogg'       => 'ogv',
    'video/quicktime' => 'mov',
  ];
  return $map[$mime] ?? 'bin';
}

/**
 * Save the multi-file gallery uploaded with the registration. Returns the
 * number of successfully saved items. Throws on hard validation failure.
 */
function save_nomination_media(mysqli $conn, int $nominationId): int {
  if (empty($_FILES['nomination_media']) || !is_array($_FILES['nomination_media']['name'] ?? null)) {
    return 0;
  }
  if (!ensure_nomination_media_table($conn)) return 0;
  $dir = ensure_nomination_media_storage($nominationId);
  if (!$dir) throw new RuntimeException('Failed to prepare media storage.');

  $names    = (array)$_FILES['nomination_media']['name'];
  $tmps     = (array)$_FILES['nomination_media']['tmp_name'];
  $errs     = (array)$_FILES['nomination_media']['error'];
  $sizes    = (array)$_FILES['nomination_media']['size'];
  $captions = (array)($_POST['nomination_media_caption'] ?? []);

  $count = count($names);
  if ($count === 0) return 0;
  if ($count > NOM_MEDIA_MAX_FILES) {
    throw new RuntimeException('You can upload at most ' . NOM_MEDIA_MAX_FILES . ' media files.');
  }

  $finfo   = new finfo(FILEINFO_MIME_TYPE);
  $insert  = $conn->prepare(
    'INSERT INTO ' . TABLE_NOMINATION_MEDIA .
    ' (nomination_id, media_type, file_path, caption, sort_order) VALUES (?, ?, ?, ?, ?)'
  );
  if (!$insert) throw new RuntimeException('Prepare registration media insert failed.');

  $saved = 0;
  for ($i = 0; $i < $count; $i++) {
    $err = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) continue;
    if ($err !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Upload error for file #' . ($i + 1) . ' (code ' . $err . ').');
    }
    $tmp  = (string)($tmps[$i] ?? '');
    if (!is_uploaded_file($tmp)) continue;
    $mime = (string)$finfo->file($tmp);
    $kind = classify_nomination_media($mime);
    if (!$kind) {
      throw new RuntimeException('Unsupported media type for file #' . ($i + 1) . '. Allowed: PNG, JPG, WEBP, GIF, MP4, WebM, OGG, MOV.');
    }
    $size = (int)($sizes[$i] ?? 0);
    $max  = $kind === 'image' ? NOM_MEDIA_IMAGE_MAX : NOM_MEDIA_VIDEO_MAX;
    if ($size > $max) {
      $mb = (int)round($max / (1024 * 1024));
      throw new RuntimeException('File #' . ($i + 1) . ' is too large. Max is ' . $mb . ' MB for ' . $kind . 's.');
    }

    $rawName  = (string)($names[$i] ?? 'media');
    $base     = pathinfo($rawName, PATHINFO_FILENAME);
    $base     = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$base);
    $base     = substr(trim($base, '_'), 0, 60) ?: 'media';
    $ext      = ext_for_nomination_media($mime);
    $filename = $base . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $abs      = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $abs)) {
      throw new RuntimeException('Failed to save file #' . ($i + 1) . '.');
    }
    @chmod($abs, 0644);

    $relPath = NOM_MEDIA_DIR_REL . '/' . $nominationId . '/' . $filename;
    $capRaw  = isset($captions[$i]) ? trim((string)$captions[$i]) : '';
    $caption = $capRaw !== '' ? mb_substr($capRaw, 0, 255) : null;
    $sort    = $saved + 1;

    $insert->bind_param('isssi', $nominationId, $kind, $relPath, $caption, $sort);
    if (!$insert->execute()) {
      @unlink($abs);
      throw new RuntimeException('Failed to record file #' . ($i + 1) . ' in the database.');
    }
    $saved++;
  }
  $insert->close();
  return $saved;
}

function generate_nomination_reference(mysqli $conn, bool $ensureUnique = false, int $length = 6): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $alphabetLength = strlen($alphabet);
  $length = max(1, $length);
  $year = date('Y');

  if ($alphabetLength === 0) {
    $fallback = strtoupper(bin2hex(random_bytes((int)ceil($length / 2))));
    return sprintf('NOM-%s-%s', $year, substr($fallback, 0, $length));
  }

  for ($attempt = 0; $attempt < 5; $attempt++) {
    $suffix = '';
    for ($i = 0; $i < $length; $i++) {
      $suffix .= $alphabet[random_int(0, $alphabetLength - 1)];
    }

    $reference = sprintf('NOM-%s-%s', $year, $suffix);

    if (!$ensureUnique) {
      return $reference;
    }

    $exists = false;
    if ($stmt = $conn->prepare("SELECT 1 FROM ".TABLE_NOMINATIONS." WHERE reference_no = ? LIMIT 1")) {
      $stmt->bind_param('s', $reference);
      $stmt->execute();
      $stmt->store_result();
      $exists = $stmt->num_rows > 0;
      $stmt->close();
    }

    if (!$exists) {
      return $reference;
    }
  }

  $fallback = strtoupper(bin2hex(random_bytes((int)ceil($length / 2))));
  return sprintf('NOM-%s-%s', $year, substr($fallback, 0, $length));
}

/* ---------- Parse selected awards ---------- */
$selected_awards = [];
if (!empty($_POST['selected_awards_json'])) {
  $arr = json_decode((string)$_POST['selected_awards_json'], true);
  if (is_array($arr)) $selected_awards = array_values(array_filter(array_map('intval', $arr)));
}
if (empty($selected_awards)) json_err('Please select at least one award.', 422);
if (!et_awards_belong_to_event($conn, $selected_awards, $event_id)) {
  json_err('One or more selected awards are not valid for this event.', 422);
}
if (!et_awards_match_establishment_types($conn, $selected_awards, $establishment_type_ids, $event_id)) {
  json_err('One or more selected awards are not allowed for your establishment type(s).', 422);
}

/* ---------- Validate required dynamic fields ---------- */
$errors = [];
foreach ($fieldDefs as $def) {
  if (!$def['is_required']) continue;
  $val = posted_value_for_field($def);
  if ($def['type'] === 'file') {
    $hasUpload = is_array($val) && isset($val['file']) && is_array($val['file']) && ($val['file']['error'] === UPLOAD_ERR_OK);
    $hasTemp   = is_array($val) && !empty($val['temp']);
    if (!$hasUpload && !$hasTemp) $errors[] = "{$def['label']} is required.";
  } else {
    if (is_array($val)) {
      if (count(array_filter($val, fn($v)=>trim((string)$v) !== '')) === 0) $errors[] = "{$def['label']} is required.";
    } else {
      if (trim((string)$val) === '') $errors[] = "{$def['label']} is required.";
    }
  }
}

// Strict phone validation for mobile/phone fields (required or optional when filled).
foreach ($fieldDefs as $def) {
  if (!is_phone_field_def($def)) continue;
  $rawVal = posted_value_for_field($def);
  if (is_array($rawVal)) continue;
  $raw = trim((string)($rawVal ?? ''));
  if ($raw === '') continue;
  if (!is_valid_phone_ph($raw)) {
    $fieldLabel = trim((string)($def['label'] ?? $def['name'] ?? 'Mobile Number'));
    $errors[] = $fieldLabel . ' must be a valid Philippine mobile number (11 digits, starts with 09).';
  }
}

// Uploaded files must be images (Mayor's Permit, logo, and other file fields).
foreach ($fieldDefs as $def) {
  if (($def['type'] ?? '') !== 'file') continue;
  $val = posted_value_for_field($def);
  if (!is_array($val) || empty($val['file']) || !is_array($val['file'])) continue;
  if (($val['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
  $tmp = (string) ($val['file']['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) continue;
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string) $finfo->file($tmp);
  if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
    $fieldLabel = trim((string) ($def['label'] ?? $def['name'] ?? 'File'));
    $errors[] = $fieldLabel . ' must be an image file (PNG, JPG, or WEBP).';
  }
}

// Mayor's permit format (text fields only, when filled).
foreach ($fieldDefs as $def) {
  if (!is_mayor_permit_field_def($def)) continue;
  $rawVal = posted_value_for_field($def);
  if (is_array($rawVal)) continue;
  $raw = trim((string)($rawVal ?? ''));
  if ($raw === '') continue;
  if (!is_valid_mayors_permit($raw)) {
    $fieldLabel = trim((string)($def['label'] ?? $def['name'] ?? "Mayor's Permit Number"));
    $errors[] = $fieldLabel . " must use the format MP-YYYY-ORM-123456 (example: MP-2024-ORM-123456).";
  }
}

// URL / website fields (when filled).
foreach ($fieldDefs as $def) {
  if (!is_url_field_def($def)) continue;
  $rawVal = posted_value_for_field($def);
  if (is_array($rawVal)) continue;
  $raw = trim((string)($rawVal ?? ''));
  if ($raw === '') continue;
  if (!is_valid_url($raw)) {
    $fieldLabel = trim((string)($def['label'] ?? $def['name'] ?? 'Website URL'));
    $errors[] = $fieldLabel . ' must be a valid URL (e.g. https://example.com).';
  }
}

// Admin-configured pattern and max length.
foreach ($fieldDefs as $def) {
  if (($def['type'] ?? '') === 'file') continue;
  $rawVal = posted_value_for_field($def);
  if (is_array($rawVal)) continue;
  $raw = trim((string)($rawVal ?? ''));
  if ($raw === '') continue;
  $fieldLabel = trim((string)($def['label'] ?? $def['name'] ?? 'Field'));
  $validation = is_array($def['validation_arr'] ?? null) ? $def['validation_arr'] : [];
  if (!empty($validation['max_length']) && mb_strlen($raw) > (int) $validation['max_length']) {
    $errors[] = $fieldLabel . ' must be at most ' . (int) $validation['max_length'] . ' characters.';
  }
  if (!empty($validation['pattern'])) {
    $pattern = (string) $validation['pattern'];
    if (@preg_match('/^(?:' . str_replace('/', '\/', $pattern) . ')$/u', $raw) !== 1) {
      $errors[] = $fieldLabel . ' format is invalid.';
    }
  }
}

// Email format + common typo suggestions (required or optional when filled).
foreach ($fieldDefs as $def) {
  if (!is_email_field_def($def)) continue;
  $rawVal = posted_value_for_field($def);
  if (is_array($rawVal)) continue;
  $raw = trim((string)($rawVal ?? ''));
  $fieldLabel = trim((string)($def['label'] ?? $def['name'] ?? 'Email'));
  if ($raw === '') {
    if (!empty($def['is_required'])) {
      $errors[] = $fieldLabel . ' is required.';
    }
    continue;
  }
  $check = email_check($raw);
  if (!$check['valid']) {
    $errors[] = $fieldLabel . ': ' . ($check['error'] ?? 'Enter a valid email address.');
    continue;
  }
  if ($check['suggestion'] !== null && !email_field_confirmed($def)) {
    $typedNorm = strtolower((string)(email_parse_address($raw)['full'] ?? $raw));
    $suggested = strtolower((string)$check['suggestion']['email']);
    if ($typedNorm !== $suggested) {
      $errors[] = $fieldLabel . ': Did you mean ' . $check['suggestion']['email'] . '?';
    }
  }
}
if (!empty($errors)) json_err('Validation failed.', 422, ['errors'=>$errors]);

/* ---------- Schema detection for establishment_type_id on registrations ---------- */
$hasNomEstTypeCol = false;
if ($r = $conn->query("SHOW COLUMNS FROM `".TABLE_NOMINATIONS."` LIKE 'establishment_type_id'")) {
  $hasNomEstTypeCol = $r->num_rows > 0;
  $r->free();
}

/* ---------- Start transaction ---------- */
$conn->begin_transaction();
try {
  // 1) Create registration row (include establishment_type_id if column exists)
  if ($hasNomEstTypeCol) {
    $ins = $conn->prepare("INSERT INTO ".TABLE_NOMINATIONS." (event_id, establishment_type_id) VALUES (?, ?)");
    if (!$ins) throw new RuntimeException('Prepare failed: '.$conn->error);
    $ins->bind_param('ii', $event_id, $establishment_type_id);
  } else {
    $ins = $conn->prepare("INSERT INTO ".TABLE_NOMINATIONS." (event_id) VALUES (?)");
    if (!$ins) throw new RuntimeException('Prepare failed: '.$conn->error);
    $ins->bind_param('i', $event_id);
  }

  if (!$ins->execute()) throw new RuntimeException('Insert registration failed: '.$ins->error);
  $nomination_id = (int)$conn->insert_id;
  $ins->close();

  // Persist many-to-many establishment types (also syncs legacy primary column).
  et_set_nomination_types($conn, $nomination_id, $establishment_type_ids);

  // 2) Save dynamic field answers
  $stmtUpsert = $conn->prepare("
    INSERT INTO ".TABLE_ANSWERS." (nomination_id, field_id, answer)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE answer = VALUES(answer)
  ");
  if (!$stmtUpsert) throw new RuntimeException('Prepare answers failed: '.$conn->error);

  foreach ($fieldDefs as $def) {
    $fid  = (int)$def['id'];
    $type = (string)$def['type'];
    $val = posted_value_for_field($def);

    if ($type === 'file') {
      $answer = null;
      if (is_array($val) && isset($val['file']) && is_array($val['file']) && $val['file']['error'] === UPLOAD_ERR_OK) {
        $answer = save_uploaded_file($val['file'], $nomination_id);
      } elseif (is_array($val) && !empty($val['temp'])) {
        $answer = trim((string)$val['temp']);
      }
      if ($answer !== null) {
        $stmtUpsert->bind_param('iis', $nomination_id, $fid, $answer);
        if (!$stmtUpsert->execute()) throw new RuntimeException('Save file answer failed: '.$stmtUpsert->error);
      }
      continue;
    }

    if (is_array($val)) {
      $clean = array_values(array_filter(array_map(fn($v)=>trim((string)$v), $val), fn($s)=>$s!==''));
      $answer = json_encode($clean, JSON_UNESCAPED_UNICODE);
    } else {
      $answer = trim((string)($val ?? ''));
      // light normalizations
      if (is_mayor_permit_field_def($def)) {
        $answer = normalize_mayors_permit($answer) ?? $answer;
      } elseif (is_phone_field_def($def)) {
        $normalized = normalize_phone_ph($answer);
        if ($normalized) $answer = $normalized;
      }
    }

    if ($answer !== '' && $answer !== null) {
      $stmtUpsert->bind_param('iis', $nomination_id, $fid, $answer);
      if (!$stmtUpsert->execute()) throw new RuntimeException('Save answer failed: '.$stmtUpsert->error);
    }
  }

  /* If registrations table does NOT have establishment_type_id,
     try to store it as an answer using a field named 'establishment_type_id' */
  $estTypeStoredAsAnswer = false;
  if (!$hasNomEstTypeCol && $est_type_field_id !== null) {
    $val = (string)$establishment_type_id; // store the numeric ID; you can switch to the name if preferred
    $stmtUpsert->bind_param('iis', $nomination_id, $est_type_field_id, $val);
    if (!$stmtUpsert->execute()) throw new RuntimeException('Save establishment_type answer failed: '.$stmtUpsert->error);
    $estTypeStoredAsAnswer = true;
  }
  $stmtUpsert->close();

  // 3) Link awards
  if (!empty($selected_awards)) {
    $iq = $conn->prepare("INSERT INTO ".TABLE_AWARD_LINKS." (nomination_id, question_id) VALUES (?, ?)");
    if (!$iq) throw new RuntimeException('Prepare awards failed: '.$conn->error);
    foreach ($selected_awards as $qid) {
      $qid = (int)$qid;
      $iq->bind_param('ii', $nomination_id, $qid);
      if (!$iq->execute()) throw new RuntimeException('Insert award link failed: '.$iq->error);
    }
    $iq->close();
  }

  // 4) Save uploaded photos & videos (gallery)
  $nomination_media_count = save_nomination_media($conn, $nomination_id);

  // 5) Reference number (if column exists)
  $hasReferenceColumn = false;
  if ($r = $conn->query("SHOW COLUMNS FROM `".TABLE_NOMINATIONS."` LIKE 'reference_no'")) {
    $hasReferenceColumn = $r->num_rows > 0;
    $r->free();
  }

  $reference_no = generate_nomination_reference($conn, $hasReferenceColumn);

  if ($hasReferenceColumn) {
    $upd = $conn->prepare("UPDATE ".TABLE_NOMINATIONS." SET reference_no=? WHERE nomination_id=?");
    if ($upd) { $upd->bind_param('si', $reference_no, $nomination_id); $upd->execute(); $upd->close(); }
  }

  $conn->commit();

  /* ✅ PERSIST for the thank-you/feedback flow */
  $_SESSION['last_nomination_id'] = $nomination_id;
  $_SESSION['last_nom_ref']       = $reference_no;
  $_SESSION['last_event_id']      = $event_id;

  // Receipt email with reference (non-blocking for the submit response).
  try {
    $receiptEmail = '';
    $receiptName  = '';
    $businessName = '';
    foreach ($fieldDefs as $def) {
      $rawVal = posted_value_for_field($def);
      if (is_array($rawVal)) continue;
      $raw = trim((string)($rawVal ?? ''));
      if ($raw === '') continue;

      $role = strtolower((string)($def['profile_role'] ?? ''));
      $name = strtolower((string)($def['name'] ?? ''));
      $label = strtolower((string)($def['label'] ?? ''));

      if ($receiptEmail === '' && is_email_field_def($def) && filter_var($raw, FILTER_VALIDATE_EMAIL)) {
        $receiptEmail = strtolower($raw);
      }
      if ($businessName === '' && (
        $role === 'business_name'
        || in_array($name, ['official_business_name', 'business_name', 'name_of_business', 'company_name'], true)
        || (bool) preg_match('/official.*business.*name|^business\s*name$|company\s*name/', $label)
      )) {
        $businessName = $raw;
      }
      if ($receiptName === '' && (
        $role === 'contact_name'
        || in_array($name, ['contact_person', 'contact_name', 'full_name', 'authorized_representative'], true)
        || (bool) preg_match('/contact\s*(person|name)|authorized\s*representative|full\s*name/', $label)
      )) {
        $receiptName = $raw;
      }
    }

    if ($receiptEmail !== '' && $reference_no !== '') {
      require_once __DIR__ . '/../tocca_admin/mailer_helper.php';
      if (!function_exists('qr_tracking_url') && is_file(__DIR__ . '/../tocca_admin/qr_url.php')) {
        require_once __DIR__ . '/../tocca_admin/qr_url.php';
      }
      $trackUrl = null;
      if (function_exists('qr_tracking_url')) {
        $base = qr_tracking_url($conn);
        $trackUrl = $base . (str_contains($base, '?') ? '&' : '?') . 'ref=' . rawurlencode($reference_no);
      }
      $send = send_nomination_receipt_email(
        $conn,
        $event_id,
        $receiptEmail,
        $receiptName !== '' ? $receiptName : $businessName,
        $businessName,
        $reference_no,
        $trackUrl
      );
      if (empty($send['ok'])) {
        error_log('nomination receipt email failed: ' . ($send['error'] ?? 'unknown'));
      }
    }
  } catch (Throwable $mailErr) {
    error_log('nomination receipt email exception: ' . $mailErr->getMessage());
  }

  // Response details about where we stored the type
  $storage_note = 'Saved to tbl_nomination_establishment_types'
    . ($hasNomEstTypeCol ? ' (primary also on tbl_nominations.establishment_type_id)' : '');

  json_ok([
    'message'             => 'Registration submitted successfully.',
    'reference_no'        => $reference_no,
    'nomination_id'       => $nomination_id,
    'media_uploaded'      => $nomination_media_count,
    'establishment_types' => [
      'ids'   => $establishment_type_ids,
      'where' => $storage_note,
    ],
    'establishment_type'  => [
      'id'    => $establishment_type_id,
      'where' => $storage_note,
    ],
  ]);

} catch (Throwable $e) {
  $conn->rollback();
  error_log("submit_nomination error: " . $e->getMessage());
  json_err('Unable to submit your registration. Please try again later.', 500);
}
