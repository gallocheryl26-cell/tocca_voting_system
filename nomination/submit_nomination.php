<?php
declare(strict_types=1);
ob_start();
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

require_once __DIR__ . '/nomination_media_helpers.php';

/* ---------- helpers ---------- */
function json_ok(array $payload = []) {
  if (ob_get_level()) ob_clean(); echo json_encode(['status'=>'success'] + $payload); exit;
}
function json_err(string $msg, int $code = 400, array $extra = []) {
  http_response_code($code); if (ob_get_level()) ob_clean(); echo json_encode(['status'=>'error','message'=>$msg] + $extra); exit;
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
  json_err('Please select at least one nature of business.', 422);
}
if (!et_types_belong_to_event($conn, $establishment_type_ids, $event_id)) {
  json_err('Please select a valid nature of business for this event.', 422);
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

function generate_nomination_reference(mysqli $conn, bool $ensureUnique = false, int $length = 6): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $alphabetLength = strlen($alphabet);
  $length = max(1, $length);
  $year = date('Y');

  if ($alphabetLength === 0) {
    $fallback = strtoupper(bin2hex(random_bytes((int)ceil($length / 2))));
    return sprintf('REG-%s-%s', $year, substr($fallback, 0, $length));
  }

  for ($attempt = 0; $attempt < 5; $attempt++) {
    $suffix = '';
    for ($i = 0; $i < $length; $i++) {
      $suffix .= $alphabet[random_int(0, $alphabetLength - 1)];
    }

    $reference = sprintf('REG-%s-%s', $year, $suffix);

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
  return sprintf('REG-%s-%s', $year, substr($fallback, 0, $length));
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
  json_err('One or more selected awards are not allowed for your nature of business.', 422);
}

require_once __DIR__ . '/../tocca_admin/includes/award_entry_helpers.php';
award_entry_ensure_schema($conn);
$awardEntriesByQuestion = award_entry_parse_payload($_POST['award_entries_json'] ?? '{}');
$kindByQuestion = [];
if ($selected_awards !== []) {
  $ph = implode(',', array_fill(0, count($selected_awards), '?'));
  $stMeta = $conn->prepare("SELECT question_id, question_name, answer_fields FROM tbl_questions WHERE question_id IN ($ph)");
  if ($stMeta) {
    $types = str_repeat('i', count($selected_awards));
    $stMeta->bind_param($types, ...$selected_awards);
    $stMeta->execute();
    $resMeta = $stMeta->get_result();
    while ($row = $resMeta->fetch_assoc()) {
      $qid = (int) $row['question_id'];
      $kind = award_entry_kind_for_question((string) $row['question_name'], (string) ($row['answer_fields'] ?? ''));
      if ($kind !== null) {
        $kindByQuestion[$qid] = $kind;
      }
    }
    $stMeta->close();
  }
}
$entryErrors = award_entry_validate_for_awards($conn, $selected_awards, $awardEntriesByQuestion);
if ($entryErrors !== []) {
  json_err(implode(' ', $entryErrors), 422);
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
    $errors[] = $fieldLabel . ' must be a valid mobile number (11 digits, starts with 09).';
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
    $errors[] = $fieldLabel . " must use the format MP-YYYY-ORM-123456 (example: MP-2026-ORM-123456).";
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

  // 2) Save dynamic field answers (update existing row; never insert a second copy)
  nf_ensure_answers_unique($conn);

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
        nf_upsert_nomination_answer($conn, $nomination_id, $fid, $answer);
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
      nf_upsert_nomination_answer($conn, $nomination_id, $fid, $answer);
    }
  }

  /* If registrations table does NOT have establishment_type_id,
     try to store it as an answer using a field named 'establishment_type_id' */
  $estTypeStoredAsAnswer = false;
  if (!$hasNomEstTypeCol && $est_type_field_id !== null) {
    $val = (string)$establishment_type_id; // store the numeric ID; you can switch to the name if preferred
    nf_upsert_nomination_answer($conn, $nomination_id, (int) $est_type_field_id, $val);
    $estTypeStoredAsAnswer = true;
  }

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

  // 3b) Named entries (products / artist / stylist) per award
  $filteredEntries = [];
  foreach ($awardEntriesByQuestion as $qid => $names) {
    if (!in_array((int) $qid, $selected_awards, true)) {
      continue;
    }
    if (!isset($kindByQuestion[(int) $qid])) {
      continue;
    }
    $filteredEntries[(int) $qid] = $names;
  }
  award_entry_set_for_nomination($conn, $nomination_id, $filteredEntries, $kindByQuestion);

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
      if (!function_exists('qr_tracking_url_with_ref') && is_file(__DIR__ . '/../tocca_admin/qr_url.php')) {
        require_once __DIR__ . '/../tocca_admin/qr_url.php';
      }
      $trackUrl = null;
      if (function_exists('qr_tracking_url_with_ref')) {
        $trackUrl = qr_tracking_url_with_ref($conn, $reference_no);
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
