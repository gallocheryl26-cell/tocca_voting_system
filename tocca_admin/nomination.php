<?php
declare(strict_types=1);

/* ======== harden output: JSON only ======== */
ob_start();
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/php_api_errors.log');

require_once __DIR__ . '/require_admin_session.php';
tocca_admin_require_login(true);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Manila');

/* ======== includes ======== */
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/includes/award_removal_reasons.php';

/* ======== helpers ======== */
function fail(string $m, int $c=400){
  http_response_code($c);
  if (ob_get_length()) ob_clean();
  echo json_encode(['status'=>'error','message'=>$m], JSON_UNESCAPED_UNICODE);
  exit;
}
function ok(array $p=[]){
  if (ob_get_length()) ob_clean();
  echo json_encode(['status'=>'success'] + $p, JSON_UNESCAPED_UNICODE);
  exit;
}

function nomination_is_final_status(?string $status): bool
{
  return in_array(strtolower(trim((string) $status)), ['approved', 'rejected', 'merged'], true);
}

function nomination_require_open_status(mysqli $conn, int $id): string
{
  $st = $conn->prepare('SELECT status FROM tbl_nominations WHERE nomination_id=? LIMIT 1');
  if (!$st) fail('DB error');
  $st->bind_param('i', $id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$row) fail('Not found', 404);
  $status = strtolower((string) ($row['status'] ?? ''));
  if (nomination_is_final_status($status)) {
    fail('This registration is already ' . $status . '. Status can no longer be changed.', 409);
  }
  return $status;
}

set_exception_handler(function(Throwable $e){
  error_log($e->getMessage().' in '.$e->getFile().':'.$e->getLine());
  if (ob_get_length()) ob_clean();
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'Server error.'], JSON_UNESCAPED_UNICODE);
  exit;
});
set_error_handler(function($sev,$msg,$file,$line){
  throw new ErrorException($msg,0,$sev,$file,$line);
});

function is_admin(): bool {
  return isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
}
function normalize_name(string $s): string {
  $s = trim($s);
  $lower = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
  return preg_replace('/\s+/', ' ', $lower);
}
function has_col(mysqli $conn, string $table, string $col): bool {
  $tbl = $conn->real_escape_string($table);
  $c   = $conn->real_escape_string($col);
  $rs  = $conn->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$c}'");
  if ($rs) { $ok = $rs->num_rows > 0; $rs->close(); return $ok; }
  return false;
}
function log_audit(mysqli $conn, int $nomination_id, string $action, string $details=''): void {
  $sql = "INSERT INTO tbl_nomination_audit (nomination_id, action, details) VALUES (?,?,?)";
  if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('iss', $nomination_id, $action, $details);
    $stmt->execute();
    $stmt->close();
  } else {
    error_log('audit prepare failed: '.$conn->error);
  }
}

/**
 * On approval/merge, copy a registration's submitted gallery (tbl_nomination_media)
 * into the establishment's voter-facing gallery (tbl_choice_media). Files are
 * copied so the originals remain in the registration archive.
 *
 * Returns the number of media items successfully promoted.
 */
function promote_nomination_media_to_choice(mysqli $conn, int $nomination_id, int $choice_id): int {
  // Verify both tables exist before doing anything.
  $exists = function(string $t) use ($conn): bool {
    $rs = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
    if (!$rs) return false;
    $ok = $rs->num_rows > 0;
    $rs->close();
    return $ok;
  };
  if (!$exists('tbl_nomination_media')) return 0;
  // Auto-create choice media table so this works even if no admin has uploaded yet.
  $conn->query("CREATE TABLE IF NOT EXISTS tbl_choice_media (
      id INT NOT NULL AUTO_INCREMENT,
      choice_id INT NOT NULL,
      media_type ENUM('image','video') NOT NULL DEFAULT 'image',
      file_path VARCHAR(500) NOT NULL,
      caption VARCHAR(255) NULL,
      sort_order INT NOT NULL DEFAULT 0,
      uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_choice_media_choice (choice_id, sort_order)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $st = $conn->prepare(
    'SELECT id, media_type, file_path, caption, sort_order
     FROM tbl_nomination_media
     WHERE nomination_id = ?
     ORDER BY sort_order ASC, id ASC'
  );
  if (!$st) return 0;
  $st->bind_param('i', $nomination_id);
  $st->execute();
  $res = $st->get_result();
  $items = [];
  while ($row = $res->fetch_assoc()) { $items[] = $row; }
  $st->close();
  if (empty($items)) return 0;

  // Where the choice gallery lives, mirroring choice_media.php conventions.
  $choiceRoot   = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'choice_media';
  $choiceFolder = $choiceRoot . DIRECTORY_SEPARATOR . $choice_id;
  if (!is_dir($choiceFolder) && !mkdir($choiceFolder, 0775, true) && !is_dir($choiceFolder)) {
    error_log("promote_nomination_media: could not create $choiceFolder");
    return 0;
  }
  // Safety .htaccess in case admin hasn't uploaded yet
  $ht = $choiceRoot . DIRECTORY_SEPARATOR . '.htaccess';
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

  // Continue numbering after any existing media for this choice.
  $next = 1;
  if ($q = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tbl_choice_media WHERE choice_id = ?')) {
    $q->bind_param('i', $choice_id);
    $q->execute();
    $next = (int)($q->get_result()->fetch_row()[0] ?? 1);
    $q->close();
  }

  $ins = $conn->prepare(
    'INSERT INTO tbl_choice_media (choice_id, media_type, file_path, caption, sort_order)
     VALUES (?, ?, ?, ?, ?)'
  );
  if (!$ins) return 0;

  $promoted = 0;
  foreach ($items as $row) {
    $srcRel = (string)$row['file_path'];
    // Registration paths are stored relative to the nomination/ directory.
    $srcAbs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'nomination'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $srcRel);
    if (!is_file($srcAbs)) {
      error_log("promote_nomination_media: missing source $srcAbs");
      continue;
    }
    $filename = basename($srcAbs);
    // Avoid name clashes with existing choice media.
    $destAbs  = $choiceFolder . DIRECTORY_SEPARATOR . $filename;
    $i = 1;
    while (is_file($destAbs)) {
      $info = pathinfo($filename);
      $alt  = ($info['filename'] ?? 'media') . '_' . $i . (isset($info['extension']) ? '.' . $info['extension'] : '');
      $destAbs = $choiceFolder . DIRECTORY_SEPARATOR . $alt;
      $filename = $alt;
      $i++;
      if ($i > 50) break;
    }
    if (!copy($srcAbs, $destAbs)) {
      error_log("promote_nomination_media: copy failed $srcAbs -> $destAbs");
      continue;
    }
    @chmod($destAbs, 0644);

    $destRel = 'uploads/choice_media/' . $choice_id . '/' . $filename;
    $kind    = (string)$row['media_type'];
    $caption = $row['caption'] !== null ? (string)$row['caption'] : null;
    $sort    = $next++;
    $ins->bind_param('isssi', $choice_id, $kind, $destRel, $caption, $sort);
    if ($ins->execute()) $promoted++;
  }
  $ins->close();
  return $promoted;
}

/* ======== event helpers ======== */
function fetch_event(mysqli $conn, int $event_id){
  $st = $conn->prepare("SELECT * FROM tbl_events WHERE event_id=? LIMIT 1");
  $st->bind_param('i',$event_id);
  $st->execute();
  $res = $st->get_result();
  $row = $res->fetch_assoc();
  $st->close();
  return $row ?: null;
}
function fetch_active_or_latest_event(mysqli $conn){
  $res = $conn->query("SELECT * FROM tbl_events WHERE is_active=1 ORDER BY year DESC, event_id DESC LIMIT 1");
  if ($res && $res->num_rows) return $res->fetch_assoc();
  $res = $conn->query("SELECT * FROM tbl_events ORDER BY year DESC, event_id DESC LIMIT 1");
  return ($res && $res->num_rows) ? $res->fetch_assoc() : null;
}
function is_nomination_open_for_event($event): bool {
  if (!$event) return false;
  $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
  $ns = !empty($event['nomination_start']) ? new DateTime($event['nomination_start']) : null;
  $ne = !empty($event['nomination_end'])   ? new DateTime($event['nomination_end'])   : null;
  return ($ns && $ne && $now >= $ns && $now <= $ne);
}
function is_voting_open_for_event($event): bool {
  if (!$event) return false;
  $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
  $vs = !empty($event['voting_start']) ? new DateTime($event['voting_start']) : null;
  $ve = !empty($event['voting_end'])   ? new DateTime($event['voting_end'])   : null;
  return ($vs && $now >= $vs && (!$ve || $now <= $ve));
}

/* ======== questions (awards) ======== */
function get_questions_for_nomination(mysqli $conn, int $nomination_id): array {
  $st=$conn->prepare("SELECT question_id FROM tbl_nomination_questions WHERE nomination_id=?");
  $st->bind_param('i',$nomination_id);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
  return array_map(static fn($r)=>(int)$r['question_id'], $rows);
}
function set_questions_for_nomination(mysqli $conn, int $nomination_id, array $question_ids): void {
  $del = $conn->prepare("DELETE FROM tbl_nomination_questions WHERE nomination_id=?");
  $del->bind_param('i',$nomination_id);
  $del->execute();
  $del->close();

  if (!empty($question_ids)){
    $ins = $conn->prepare("INSERT INTO tbl_nomination_questions (nomination_id, question_id) VALUES (?,?)");
    foreach($question_ids as $qid){
      $qid = (int)$qid; if (!$qid) continue;
      $ins->bind_param('ii', $nomination_id, $qid);
      $ins->execute();
    }
    $ins->close();
  }
}
function get_question_details_for_nomination(mysqli $conn, int $nomination_id): array {
  $sql = "
    SELECT
      q.question_id     AS question_id,
      q.question_name   AS question_name,
      c.category_id     AS category_id,
      c.category_name   AS category_name
    FROM tbl_nomination_questions nq
    JOIN tbl_questions q  ON q.question_id = nq.question_id
    LEFT JOIN tbl_categories c ON c.category_id = q.category_id
    WHERE nq.nomination_id = ?
    ORDER BY c.category_name ASC, q.question_name ASC
  ";
  $st = $conn->prepare($sql);
  $st->bind_param('i', $nomination_id);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
  return $rows ?: [];
}

/* ======== uploads (logo) ======== */
function handle_logo_upload(string $field_name='logo', string $save_dir='uploads/logos'): ?string {
  if (!isset($_FILES[$field_name]) || !is_array($_FILES[$field_name]) || $_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  $file = $_FILES[$field_name];
  if ($file['error'] !== UPLOAD_ERR_OK) {
    switch ($file['error']) {
      case UPLOAD_ERR_INI_SIZE:
      case UPLOAD_ERR_FORM_SIZE: fail('Logo exceeds maximum size (10MB).'); break;
      default: fail('Upload failed.');
    }
  }
  if ($file['size'] > 10 * 1024 * 1024) fail('Logo must be 10MB or less.');

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);

  $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
  if (!isset($allowed[$mime])) fail('Logo must be PNG, JPG/JPEG, or WEBP.');

  if (!is_dir($save_dir)) { @mkdir($save_dir, 0775, true); }

  $ext = $allowed[$mime];
  $basename = bin2hex(random_bytes(10)) . '.' . $ext;
  $destFs = rtrim($save_dir,'/') . '/' . $basename;
  if (!move_uploaded_file($file['tmp_name'], $destFs)) fail('Failed to save uploaded logo.');
  return rtrim($save_dir,'/') . '/' . $basename;
}

/* ======== dynamic answers ======== */
function load_active_fields(mysqli $conn): array {
  $rows = [];
  $q = $conn->query("
    SELECT id, name, label, type, is_required, is_active, sort_order
    FROM tbl_nomination_fields
    WHERE is_active = 1
    ORDER BY sort_order ASC, id ASC
  ");
  if ($q) { while ($r = $q->fetch_assoc()) $rows[(int)$r['id']] = $r; $q->free(); }
  return $rows;
}
function replace_answers_from_post(mysqli $conn, int $nomination_id, array $fields): void {
  $del = $conn->prepare("DELETE FROM tbl_nomination_answers WHERE nomination_id=?");
  $del->bind_param('i', $nomination_id); $del->execute(); $del->close();

  $ins = $conn->prepare("INSERT INTO tbl_nomination_answers (nomination_id, field_id, answer) VALUES (?,?,?)");
  if (!$ins) fail('Failed to prepare answers insert.');

  foreach ($fields as $fid => $f) {
    $fid = (int)$fid;
    $machine = trim((string)($f['name'] ?? ''));
    $keyId   = 'field_' . $fid;

    $raw = null;
    if (array_key_exists($keyId, $_POST)) {
      $raw = $_POST[$keyId];
    } elseif ($machine !== '' && array_key_exists($machine, $_POST)) {
      $raw = $_POST[$machine];
    } elseif ($machine !== '' && array_key_exists($machine . '[]', $_POST)) {
      $raw = $_POST[$machine . '[]'];
    }
    if ($raw === null) continue;

    $val = is_array($raw) ? json_encode(array_values(array_map('trim', $raw)), JSON_UNESCAPED_UNICODE)
                          : trim((string)$raw);
    $ins->bind_param('iis', $nomination_id, $fid, $val);
    $ins->execute();
  }
  $ins->close();
}
function fetch_answer_value(mysqli $conn, int $nomination_id, array $namePref = [], array $labelPref = []): ?string {
  $sql = "
    SELECT f.name, f.label, a.answer
    FROM tbl_nomination_answers a
    JOIN tbl_nomination_fields f ON f.id=a.field_id
    WHERE a.nomination_id=?
  ";
  $st = $conn->prepare($sql);
  $st->bind_param('i', $nomination_id);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  $byName  = [];
  $byLabel = [];
  foreach ($rows as $r) {
    $byName[strtolower((string)$r['name'])] = (string)$r['answer'];
    $clean = trim((string)$r['label']);
    if (substr($clean, -1) === '*') $clean = rtrim(substr($clean,0,-1));
    $byLabel[strtolower($clean)] = (string)$r['answer'];
  }
  foreach ($namePref as $n) {
    $k = strtolower($n);
    if (isset($byName[$k]) && $byName[$k] !== '') return $byName[$k];
  }
  foreach ($labelPref as $l) {
    $k = strtolower($l);
    if (isset($byLabel[$k]) && $byLabel[$k] !== '') return $byLabel[$k];
  }
  return null;
}
function notify_nomination_submission(mysqli $conn, int $nomination_id, array $event, string $source): void {
  if (!function_exists('system_notif_insert')) return;
  try {
    $eventId = (int)($event['event_id'] ?? $event['id'] ?? 0);
    $eventName = (string)($event['event_name'] ?? '');
    $nominee = fetch_answer_value($conn, $nomination_id,
      ['business_name', 'official_business_name', 'company', 'company_name', 'nominee_name', 'name'],
      ['Business Name', 'Official Business Name', 'Company Name', 'Nominee Name', 'Name']
    );

    $payload = [
      'marker'         => sprintf('nomination_submission:%d:%s', $nomination_id, $source),
      'event_id'       => $eventId,
      'event_name'     => $eventName,
      'nomination_id'  => $nomination_id,
      'nominee'        => $nominee ?? '',
      'status'         => 'pending',
      'status_label'   => 'Unapproved',
      'submitted_via'  => $source,
    ];
    system_notif_insert($conn, 'system_nomination_submission', $payload, $nomination_id);
  } catch (Throwable $e) {
    error_log('registration submission notification failed: '.$e->getMessage());
  }
}

function approve_nomination(mysqli $conn, int $nomination_id, ?int $target_choice_id = null): int {
  // load registration
  $st = $conn->prepare("SELECT * FROM tbl_nominations WHERE nomination_id=?");
  $st->bind_param('i', $nomination_id);
  $st->execute();
  $nom = $st->get_result()->fetch_assoc();
  $st->close();

  if(!$nom) fail('Registration not found.', 404);
  if(!in_array($nom['status'], ['pending','in_review','needs_info'], true)) fail('Registration not in approvable state.');

  $event_id = (int)$nom['event_id'];
  require_once __DIR__ . '/includes/establishment_type_event_helpers.php';
  et_ensure_m2m_schema($conn);
  $est_type_ids = et_get_nomination_type_ids($conn, $nomination_id);
  $est_type_id = $est_type_ids[0] ?? (isset($nom['establishment_type_id']) ? (int)$nom['establishment_type_id'] : null);
  if ($est_type_id !== null && $est_type_id <= 0) $est_type_id = null;

  $questionIds = get_questions_for_nomination($conn, $nomination_id);

  // pull business/email from answers if missing
  $fallback_business = fetch_answer_value($conn, $nomination_id,
    ['business_name'], ['official business name','business name','company name']
  );
  $fallback_email = fetch_answer_value($conn, $nomination_id,
    ['email','contact_email'], ['email']
  );
  $business_for_choice = (string)($nom['business_name'] ?? '');
  if ($business_for_choice === '' && $fallback_business) $business_for_choice = $fallback_business;
  $email_for_choice = (string)($nom['email'] ?? '');
  if ($email_for_choice === '' && $fallback_email) $email_for_choice = $fallback_email;

  $choice_id = null;
  $used_existing = false;

  if ($target_choice_id) {
    $choice_id = (int)$target_choice_id;
    $used_existing = true;
  } else {
    // find existing by normalized name within event
    $st = $conn->prepare("SELECT choice_id, choice_name FROM tbl_choices WHERE event_id=?");
    $st->bind_param('i', $event_id);
    $st->execute();
    $res = $st->get_result();
    while($row = $res->fetch_assoc()){
      if (normalize_name((string)$row['choice_name']) === normalize_name($business_for_choice)){
        $choice_id = (int)$row['choice_id'];
        $used_existing = true;
        break;
      }
    }
    $st->close();

    if (!$choice_id){
      // ✅ insert with establishment_type_id if that column exists
      $hasTypeCol = has_col($conn, 'tbl_choices', 'establishment_type_id');
      if ($hasTypeCol) {
        $st = $conn->prepare("
          INSERT INTO tbl_choices (choice_name, email, status, event_id, establishment_type_id, qr_sent)
          VALUES (?, ?, 1, ?, ?, 0)
        ");
        $nullType = $est_type_id ?: null;
        $st->bind_param('ssii', $business_for_choice, $email_for_choice, $event_id, $nullType);
      } else {
        $st = $conn->prepare("
          INSERT INTO tbl_choices (choice_name, email, status, event_id, qr_sent)
          VALUES (?, ?, 1, ?, 0)
        ");
        $st->bind_param('ssi', $business_for_choice, $email_for_choice, $event_id);
      }
      if(!$st->execute()) fail('Failed to create Choice.');
      $choice_id = (int)$st->insert_id;
      $st->close();
      $used_existing = false;
    }
  }

  // ensure choice is active + update its establishment_type_id if we have it and column exists
  $hasTypeCol = has_col($conn, 'tbl_choices', 'establishment_type_id');
  if ($hasTypeCol && $est_type_id) {
    $st = $conn->prepare("UPDATE tbl_choices SET status=1, establishment_type_id=? WHERE choice_id=?");
    $st->bind_param('ii', $est_type_id, $choice_id);
  } else {
    $st = $conn->prepare("UPDATE tbl_choices SET status=1 WHERE choice_id=?");
    $st->bind_param('i', $choice_id);
  }
  $st->execute(); $st->close();

  // Persist many-to-many establishment types on the choice.
  if (!empty($est_type_ids)) {
    et_set_choice_types($conn, $choice_id, $est_type_ids);
  } elseif ($est_type_id) {
    et_set_choice_types($conn, $choice_id, [$est_type_id]);
  }

  // link awards
  if (!empty($questionIds)) {
    $ins = $conn->prepare("INSERT IGNORE INTO tbl_question_choices (question_id, choice_id) VALUES (?,?)");
    foreach($questionIds as $qid){
      $qid = (int)$qid; if (!$qid) continue;
      $ins->bind_param('ii', $qid, $choice_id);
      $ins->execute();
    }
    $ins->close();
  }

  // update registration status (and merged_choice_id if present)
  $hasMerged = has_col($conn, 'tbl_nominations', 'merged_choice_id');
  if ($used_existing) {
    if ($hasMerged) {
      $st = $conn->prepare("UPDATE tbl_nominations SET status='merged', merged_choice_id=?, updated_at=NOW() WHERE nomination_id=?");
      $st->bind_param('ii', $choice_id, $nomination_id);
    } else {
      $st = $conn->prepare("UPDATE tbl_nominations SET status='merged', updated_at=NOW() WHERE nomination_id=?");
      $st->bind_param('i', $nomination_id);
    }
    $st->execute(); $st->close();
    log_audit($conn, $nomination_id, 'merge', "Merged into choice_id={$choice_id}");
  } else {
    if ($hasMerged) {
      $st = $conn->prepare("UPDATE tbl_nominations SET status='approved', merged_choice_id=?, updated_at=NOW() WHERE nomination_id=?");
      $st->bind_param('ii', $choice_id, $nomination_id);
    } else {
      $st = $conn->prepare("UPDATE tbl_nominations SET status='approved', updated_at=NOW() WHERE nomination_id=?");
      $st->bind_param('i', $nomination_id);
    }
    $st->execute(); $st->close();
    log_audit($conn, $nomination_id, 'approve', "Approved → choice_id={$choice_id}");
  }

  // Carry submitted photos/videos into the establishment's voter-facing gallery.
  try {
    $promoted = promote_nomination_media_to_choice($conn, $nomination_id, $choice_id);
    if ($promoted > 0) {
      log_audit($conn, $nomination_id, 'media_promote', "Promoted {$promoted} media file(s) to choice_id={$choice_id}");
    }
  } catch (Throwable $e) {
    error_log('promote_nomination_media_to_choice failed: ' . $e->getMessage());
  }

  return $choice_id;
}
function maybe_deactivate_choice(mysqli $conn, int $choice_id, int $nomination_id): void {
  if (!has_col($conn, 'tbl_nominations', 'merged_choice_id')) return;

  $q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM tbl_nominations
    WHERE merged_choice_id = ?
      AND nomination_id <> ?
      AND status IN ('approved','merged')
  ");
  $q->bind_param('ii', $choice_id, $nomination_id);
  $q->execute();
  $cnt = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
  $q->close();

  if ($cnt > 0) return;

  $u = $conn->prepare("UPDATE tbl_choices SET status = 0 WHERE choice_id = ?");
  $u->bind_param('i', $choice_id);
  $u->execute(); $u->close();
}

/* ======== router ======== */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;
if (!$action) fail('action is required');

/* --- PUBLIC --- */
if ($method === 'GET' && $action === 'window_status') {
  $event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
  $event = $event_id ? fetch_event($conn, $event_id) : fetch_active_or_latest_event($conn);
  if (!$event) fail('No event found', 404);
  $open = is_nomination_open_for_event($event);
  ok([
    'event_id' => (int)$event['event_id'],
    'event_name' => $event['event_name'],
    'nomination_start' => $event['nomination_start'],
    'nomination_end'   => $event['nomination_end'],
    'is_open' => $open ? 1 : 0
  ]);
}
if ($method === 'POST' && $action === 'create_public') {
  $event_id = (int)($_POST['event_id'] ?? 0);
  if (!$event_id) fail('event_id required');
  $event = fetch_event($conn, $event_id);
  if (!$event) fail('Event not found',404);
  if (!is_nomination_open_for_event($event)) fail('Registration is currently closed for this event.', 403);

  $st = $conn->prepare("INSERT INTO tbl_nominations (event_id, status) VALUES (?, 'pending')");
  $st->bind_param('i', $event_id);
  if (!$st->execute()) fail('Failed to save registration.');
  $nomination_id = (int)$st->insert_id;
  $st->close();

  $logo_path = handle_logo_upload('logo', 'uploads/logos');
  if ($logo_path) {
    $u = $conn->prepare("UPDATE tbl_nominations SET logo_path=?, updated_at=NOW() WHERE nomination_id=?");
    $u->bind_param('si', $logo_path, $nomination_id);
    $u->execute(); $u->close();
  }

  $fields = load_active_fields($conn);
  replace_answers_from_post($conn, $nomination_id, $fields);

  $question_ids = [];
  if (isset($_POST['question_ids'])) $question_ids = (array)$_POST['question_ids'];
  elseif (isset($_POST['selected_awards_json'])) {
    $json = json_decode((string)$_POST['selected_awards_json'], true);
    if (is_array($json)) $question_ids = $json;
  }
  set_questions_for_nomination($conn, $nomination_id, $question_ids);

  log_audit($conn, $nomination_id, 'create', 'Public create');
  notify_nomination_submission($conn, $nomination_id, $event, 'public_form');
  ok(['nomination_id'=>$nomination_id]);
}

/* --- ADMIN ONLY --- */
if (!is_admin()) fail('Unauthorized', 401);

require_once __DIR__ . '/includes/nominations_list.php';

/* LIST */
if ($method === 'GET' && $action === 'list') {
  $event_id  = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
  $status    = isset($_GET['status']) ? trim((string)$_GET['status']) : 'all';
  if (!$event_id) fail('event_id required');

  $page      = max(1, (int)($_GET['page'] ?? 1));
  $page_size = max(1, min(100, (int)($_GET['page_size'] ?? 10)));
  $q         = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

  $result = nominations_fetch_list($conn, $event_id, $status, $page, $page_size, $q);
  ok($result);
}

if ($method === 'GET' && $action === 'suggest') {
  $event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
  $q        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  if (!$event_id) fail('event_id required');
  $names = nominations_suggest_businesses($conn, $event_id, $q);
  ok(['suggestions' => $names]);
}

/* GET one */
if ($method === 'GET' && $action === 'get') {
  $id = (int)($_GET['id'] ?? 0);
  if(!$id) fail('id required');

  $st=$conn->prepare("SELECT * FROM tbl_nominations WHERE nomination_id=?");
  $st->bind_param('i',$id);
  $st->execute();
  $n=$st->get_result()->fetch_assoc();
  $st->close();
  if(!$n) fail('Not found',404);

  $qIds     = get_questions_for_nomination($conn, $id);
  $qDetails = get_question_details_for_nomination($conn, $id);
  $removed  = award_removal_fetch_for_nomination($conn, $id);

  $eventId = (int)($n['event_id'] ?? 0);
  $roleCol = '';
  $eventFilter = '';
  if ($chk = $conn->query("SHOW COLUMNS FROM tbl_nomination_fields LIKE 'profile_role'")) {
    if ($chk->num_rows > 0) {
      $roleCol = ', f.profile_role AS profile_role';
    }
    $chk->free();
  }
  if ($eventId > 0) {
    if ($chk = $conn->query("SHOW COLUMNS FROM tbl_nomination_fields LIKE 'event_id'")) {
      if ($chk->num_rows > 0) {
        $eventFilter = ' AND (f.event_id IS NULL OR f.event_id = ?)';
      }
      $chk->free();
    }
  }
  $ansSql = "
    SELECT f.id AS field_id, f.name AS name, f.label AS label, f.type AS type{$roleCol}, a.answer AS answer
    FROM tbl_nomination_fields f
    LEFT JOIN tbl_nomination_answers a ON a.field_id = f.id AND a.nomination_id = ?
    WHERE f.is_active = 1{$eventFilter}
    ORDER BY f.sort_order ASC, f.id ASC
  ";
  $ansSt = $conn->prepare($ansSql);
  if ($eventFilter !== '') {
    $ansSt->bind_param('ii', $id, $eventId);
  } else {
    $ansSt->bind_param('i', $id);
  }
  $ansSt->execute();
  $answers = $ansSt->get_result()->fetch_all(MYSQLI_ASSOC);
  $ansSt->close();

  ok(['nomination'=>$n, 'question_ids'=>$qIds, 'categories'=>$qDetails, 'removed_awards'=>$removed, 'answers'=>$answers]);
}

/* CREATE (admin) */
if ($method === 'POST' && $action === 'create') {
  $event_id = (int)($_POST['event_id'] ?? 0);
  if (!$event_id) fail('event_id required');

  $event = fetch_event($conn, $event_id);
  if (!$event) fail('Event not found', 404);

  $st = $conn->prepare("INSERT INTO tbl_nominations (event_id, status) VALUES (?, 'pending')");
  $st->bind_param('i', $event_id);
  if (!$st->execute()) fail('Failed to create registration.');
  $nomination_id = (int)$st->insert_id;
  $st->close();

  $logo_path = handle_logo_upload('logo', 'uploads/logos');
  if ($logo_path) {
    $u = $conn->prepare("UPDATE tbl_nominations SET logo_path=?, updated_at=NOW() WHERE nomination_id=?");
    $u->bind_param('si', $logo_path, $nomination_id);
    $u->execute(); $u->close();
  }

  $fields = load_active_fields($conn);
  replace_answers_from_post($conn, $nomination_id, $fields);

  $question_ids = isset($_POST['question_ids']) ? (array)$_POST['question_ids']
               : (isset($_POST['selected_awards_json']) ? (array)json_decode((string)$_POST['selected_awards_json'], true) : []);
  set_questions_for_nomination($conn, $nomination_id, $question_ids);

  log_audit($conn, $nomination_id, 'create', 'Admin create');
  notify_nomination_submission($conn, $nomination_id, $event, 'admin_panel');
  ok(['nomination_id'=>$nomination_id]);
}

/* UPDATE (admin) */
if ($method === 'POST' && $action === 'update') {
  $id = (int)($_POST['nomination_id'] ?? 0);
  if(!$id) fail('nomination_id required');

  $st=$conn->prepare("SELECT * FROM tbl_nominations WHERE nomination_id=?");
  $st->bind_param('i',$id);
  $st->execute();
  $cur=$st->get_result()->fetch_assoc();
  $st->close();
  if(!$cur) fail('Not found',404);

  $status     = trim((string)($_POST['status'] ?? $cur['status']));
  $admin_note = trim((string)($_POST['admin_note'] ?? ($cur['admin_note'] ?? '')));

  $new_logo = handle_logo_upload('logo', 'uploads/logos');
  $logo_path = $new_logo ? $new_logo : ($cur['logo_path'] ?? null);

  $st=$conn->prepare("UPDATE tbl_nominations SET admin_note=?, status=?, logo_path=?, updated_at=NOW() WHERE nomination_id=?");
  $st->bind_param('sssi', $admin_note, $status, $logo_path, $id);
  if(!$st->execute()) fail('Update failed');
  $st->close();

  $fields = load_active_fields($conn);
  replace_answers_from_post($conn, $id, $fields);

  if (isset($_POST['question_ids']) || isset($_POST['selected_awards_json'])) {
    $question_ids = isset($_POST['question_ids']) ? (array)$_POST['question_ids']
                 : (array)json_decode((string)$_POST['selected_awards_json'], true);
    set_questions_for_nomination($conn, $id, $question_ids);
  }

  log_audit($conn, $id, 'update', 'Admin update');
  ok();
}

/* APPROVE (admin) — creates/merges Choice and SETS establishment_type_id */
if ($method === 'POST' && $action === 'approve') {
  $evt = fetch_active_or_latest_event($conn);
  if ($evt && !empty($evt['voting_start']) && is_voting_open_for_event($evt)) {
    fail('Voting is already ongoing. You can no longer approve/accept registrations.', 409);
  }

  $id = (int)($_POST['nomination_id'] ?? 0);
  $target_choice_id = isset($_POST['target_choice_id']) && $_POST['target_choice_id'] !== '' ? (int)$_POST['target_choice_id'] : null;
  if(!$id) fail('nomination_id required');
  nomination_require_open_status($conn, $id);

  $choice_id = approve_nomination($conn, $id, $target_choice_id);
  ok(['choice_id'=>$choice_id]);
}

/* NEEDS INFO */
if ($method === 'POST' && $action === 'needs_info') {
  $id = (int)($_POST['nomination_id'] ?? 0);
  if(!$id) fail('nomination_id required');
  nomination_require_open_status($conn, $id);

  $st=$conn->prepare("UPDATE tbl_nominations SET status='needs_info', updated_at=NOW() WHERE nomination_id=?");
  $st->bind_param('i',$id);
  $st->execute();
  $st->close();

  if (has_col($conn, 'tbl_nominations', 'merged_choice_id')) {
    $st=$conn->prepare("SELECT merged_choice_id FROM tbl_nominations WHERE nomination_id=?");
    $st->bind_param('i',$id);
    $st->execute();
    $row=$st->get_result()->fetch_assoc();
    $st->close();
    if (!empty($row['merged_choice_id'])) {
      maybe_deactivate_choice($conn, (int)$row['merged_choice_id'], $id);
    }
  }

  ok();
}

/* REJECT */
if ($method === 'POST' && $action === 'reject') {
  $id = (int)($_POST['nomination_id'] ?? 0);
  if(!$id) fail('nomination_id required');
  nomination_require_open_status($conn, $id);

  $st=$conn->prepare("UPDATE tbl_nominations SET status='rejected', updated_at=NOW() WHERE nomination_id=?");
  $st->bind_param('i',$id);
  $st->execute();
  $st->close();

  if (has_col($conn, 'tbl_nominations', 'merged_choice_id')) {
    $st=$conn->prepare("SELECT merged_choice_id FROM tbl_nominations WHERE nomination_id=?");
    $st->bind_param('i',$id);
    $st->execute();
    $row=$st->get_result()->fetch_assoc();
    $st->close();
    if (!empty($row['merged_choice_id'])) {
      maybe_deactivate_choice($conn, (int)$row['merged_choice_id'], $id);
    }
  }

  ok();
}

/* DELETE */
if ($method === 'POST' && $action === 'delete') {
  $id = (int)($_POST['nomination_id'] ?? 0);
  if(!$id) fail('nomination_id required');

  $d1=$conn->prepare("DELETE FROM tbl_nomination_questions WHERE nomination_id=?");
  $d1->bind_param('i',$id); $d1->execute(); $d1->close();

  $d2=$conn->prepare("DELETE FROM tbl_nomination_answers WHERE nomination_id=?");
  $d2->bind_param('i',$id); $d2->execute(); $d2->close();

  $d3=$conn->prepare("DELETE FROM tbl_nominations WHERE nomination_id=?");
  $d3->bind_param('i',$id);
  if(!$d3->execute()) fail('Delete failed');
  $d3->close();

  log_audit($conn, $id, 'delete', 'Admin delete');
  ok();
}

fail('Unsupported route',404);
