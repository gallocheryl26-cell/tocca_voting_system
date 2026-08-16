<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_session.php';
tocca_admin_require_login(true);

/* ---------- Keep a global output buffer ON the whole time ---------- */
ob_start();

require_once 'db_connection.php';
require_once 'comm.php';

/* Wipe anything those includes might have printed, but keep buffering */
ob_clean();

header('Content-Type: application/json; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors','0');
@set_time_limit(120);

/* ---------- JSON helpers ---------- */
function _flush_json(array $payload, int $code = 200): void {
  // Nuke ALL buffers so only JSON goes out
  while (ob_get_level() > 0) { @ob_end_clean(); }
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function jerr(string $m, int $c=500, array $extra=[]): void {
  _flush_json(['status'=>'error','message'=>$m] + $extra, $c);
}
function jok(array $p=[]): void {
  _flush_json(['status'=>'success'] + $p, 200);
}

/* ---------- Turn ANY PHP error into JSON ---------- */
set_error_handler(function($no,$str,$file,$line){
  error_log("PHP error [$no] $str @ $file:$line");
  jerr("Server error: $str", 500, ['at'=>basename($file).':'.$line,'errno'=>$no]);
});
set_exception_handler(function(Throwable $e){
  error_log("PHP exception: ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine());
  jerr("Server exception: ".$e->getMessage(), 500, ['at'=>basename($e->getFile()).':'.$e->getLine()]);
});
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
    error_log("PHP fatal: {$e['message']} @ {$e['file']}:{$e['line']}");
    jerr("Server fatal: {$e['message']}", 500, ['at'=>basename($e['file']).':'.$e['line']]);
  }
});

/* ---------- Small helpers ---------- */
function log_audit(mysqli $conn, int $nom_id, string $action, string $details=''): void {
  if ($st = $conn->prepare("INSERT INTO `tbl_nomination_audit` (`nomination_id`,`action`,`details`) VALUES (?,?,?)")) {
    $st->bind_param('iss',$nom_id,$action,$details);
    $st->execute(); $st->close();
  }
}
function column_exists(mysqli $conn, string $table, string $col): bool {
  $table = preg_replace('/[^A-Za-z0-9_]/','',$table);
  $col   = preg_replace('/[^A-Za-z0-9_]/','',$col);
  $rs = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '".$conn->real_escape_string($col)."'");
  if ($rs && $rs->num_rows > 0) { $rs->close(); return true; }
  if ($rs) $rs->close();
  return false;
}
function norm(string $s): string { return strtolower(trim(preg_replace('/\s+/','_', $s))); }

function find_answer_by_alias(mysqli $conn, int $nomination_id, array $aliases): string {
  $want = array_map('norm', $aliases);
  $map  = array_flip($want);
  $sql = "SELECT COALESCE(f.name,'') AS fname, COALESCE(f.label,'') AS flabel, a.answer
          FROM tbl_nomination_answers a
          LEFT JOIN tbl_nomination_fields f ON f.id = a.field_id
          WHERE a.nomination_id = ?";
  if (!$st = $conn->prepare($sql)) return '';
  $st->bind_param('i', $nomination_id);
  $st->execute();
  $rs = $st->get_result();
  while ($row = $rs->fetch_assoc()) {
    $fname = norm((string)$row['fname']);
    $flabel= norm((string)$row['flabel']);
    if ((isset($map[$fname]) || isset($map[$flabel])) && trim((string)$row['answer']) !== '') {
      $st->close();
      return (string)$row['answer'];
    }
  }
  $st->close();
  return '';
}

/** @return array{vote_url:string,qr_path:string,email_qr_path:string} */
function nomination_approved_vote_assets(mysqli $conn, int $nomination_id, int $event_id, string $biz_name): array
{
  $choiceId = 0;
  if (column_exists($conn, 'tbl_nominations', 'merged_choice_id')) {
    $st = $conn->prepare('SELECT merged_choice_id FROM tbl_nominations WHERE nomination_id = ? LIMIT 1');
    if ($st) {
      $st->bind_param('i', $nomination_id);
      $st->execute();
      $row = $st->get_result()->fetch_assoc();
      $st->close();
      $choiceId = (int) ($row['merged_choice_id'] ?? 0);
    }
  }
  if ($choiceId <= 0 && $biz_name !== '') {
    $st = $conn->prepare(
      'SELECT choice_id FROM tbl_choices WHERE event_id = ? AND choice_name = ? ORDER BY choice_id DESC LIMIT 1'
    );
    if ($st) {
      $st->bind_param('is', $event_id, $biz_name);
      $st->execute();
      $row = $st->get_result()->fetch_assoc();
      $st->close();
      $choiceId = (int) ($row['choice_id'] ?? 0);
    }
  }
  if ($choiceId <= 0) {
    return ['vote_url' => '', 'qr_path' => '', 'email_qr_path' => ''];
  }

  $voteUrl = '';
  $qrPath = '';
  $emailQrPath = '';
  try {
    require_once __DIR__ . '/qr_url.php';
    require_once __DIR__ . '/qr_utils.php';
    // Direct file URL (no rewrite) so a scan opens this business's voting page.
    $voteUrl = qr_scan_reachable_url(qr_vote_token_url_for_choice($choiceId, $conn));
    $generated = ensure_qr_png_for_choice($choiceId, false);
    if (is_string($generated) && is_file($generated)) {
      $qrPath = $generated;
    }
    $emailQrPath = nomination_write_email_qr_png($voteUrl);
  } catch (Throwable $e) {
    error_log('nomination approve vote assets: ' . $e->getMessage());
  }

  return ['vote_url' => $voteUrl, 'qr_path' => $qrPath, 'email_qr_path' => $emailQrPath];
}

/** Plain high-contrast QR (no poster frame or center logo) for email scanning. */
function nomination_write_email_qr_png(string $voteUrl): string
{
  if ($voteUrl === '' || !function_exists('qr_generate_png_resource')) {
    return '';
  }
  $style = [
    'use_center_logo'      => false,
    'use_corner_brackets'  => false,
    'fg_color'             => '#000000',
    'bg_color'             => '#ffffff',
    'logo_path'            => '',
    'logo_path_absolute'   => '',
  ];
  $img = qr_generate_png_resource($voteUrl, 480, $style);
  if (!$img) {
    return '';
  }
  if (function_exists('qr_flatten_to_background')) {
    qr_flatten_to_background($img, [255, 255, 255]);
  }
  $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tocca_email_qr';
  if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    imagedestroy($img);
    return '';
  }
  $path = $dir . DIRECTORY_SEPARATOR . 'vote_' . sha1($voteUrl) . '.png';
  $ok = imagepng($img, $path, 6);
  imagedestroy($img);
  return ($ok && is_file($path)) ? $path : '';
}

function nomination_html_inject_vote_assets(string $html, string $voteUrl, bool $hasQr): string
{
  $html = str_replace(['{vote_url}', '{qr_code}'], '', $html);
  $html = preg_replace('/<p>\s*(?:Share this voting[\s\S]*?|You can also print[\s\S]*?)<\/p>/i', '', $html) ?? $html;
  return $html;
}

function nomination_status_heading(string $status): string
{
  return match ($status) {
    'approved'   => 'Registration Approved',
    'needs_info' => 'More Information Needed',
    'rejected'   => 'Registration Update',
    default      => 'Registration Update',
  };
}

/* ---------- Auth / CSRF ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('POST only', 405);
if (empty($_SESSION['csrf']))              jerr('CSRF not initialized', 403);

/* ---------- Input ---------- */
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in) || !$in) $in = $_POST;

$csrf           = (string)($in['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'], $csrf)) jerr('Invalid CSRF token', 403);

$nomination_id  = (int)($in['nomination_id'] ?? 0);
$reference      = trim((string)($in['reference'] ?? ''));
$status         = strtolower(trim((string)($in['status'] ?? '')));
$send_email     = !empty($in['send_email']);
$subject        = trim((string)($in['subject'] ?? ''));
$html           = (string)($in['html'] ?? '');
$missing_fields = trim((string)($in['missing_fields'] ?? ''));

if ($nomination_id <= 0 && $reference === '') jerr('Missing registration identifier', 422);
if ($status === '')                            jerr('Missing status', 422);

$allowed = ['pending','in_review','needs_info','approved','rejected','merged'];
if (!in_array($status, $allowed, true)) jerr('Invalid status', 422, ['allowed'=>$allowed]);

/* ---------- DB ---------- */
if (!isset($conn) || !($conn instanceof mysqli)) jerr('DB connection not available', 500);
$conn->set_charset('utf8mb4');

/* Lookup by reference_no if needed */
if ($nomination_id <= 0 && $reference !== '') {
  $lk = $conn->prepare("SELECT `nomination_id` FROM `tbl_nominations` WHERE `reference_no`=? LIMIT 1");
  if (!$lk) jerr('DB error (lookup): '.$conn->error, 500);
  $lk->bind_param('s',$reference);
  $lk->execute();
  $row = $lk->get_result()->fetch_assoc();
  $lk->close();
  if (!$row) jerr('Reference number not found', 404);
  $nomination_id = (int)$row['nomination_id'];
}

/* Build SELECT only with columns that exist */
$cols = ['`nomination_id`','`event_id`','`status`'];
$hasEmailCol = column_exists($conn,'tbl_nominations','email');
$hasBizCol   = column_exists($conn,'tbl_nominations','business_name');
if ($hasEmailCol) $cols[]='`email`';
if ($hasBizCol)   $cols[]='`business_name`';
$sqlSel = implode(',', $cols);

$st = $conn->prepare("SELECT {$sqlSel} FROM `tbl_nominations` WHERE `nomination_id`=? LIMIT 1");
if (!$st) jerr('DB error (load): '.$conn->error, 500);
$st->bind_param('i',$nomination_id);
$st->execute();
$nom = $st->get_result()->fetch_assoc();
$st->close();
if (!$nom) jerr('Registration not found', 404);

$current  = strtolower((string)$nom['status']);
$event_id = (int)$nom['event_id'];

$email_to = $hasEmailCol ? (string)($nom['email'] ?? '') : '';
$biz_name = $hasBizCol   ? (string)($nom['business_name'] ?? '') : '';

/* Fallback to answers if not columns / empty */
if ($email_to === '' || $biz_name === '') {
  $EMAIL_ALIASES = ['email','contact_email'];
  $BIZ_ALIASES   = ['business_name','official_business_name','company','company_name','business'];
  if ($email_to === '') $email_to = find_answer_by_alias($conn, $nomination_id, $EMAIL_ALIASES);
  if ($biz_name === '') $biz_name = find_answer_by_alias($conn, $nomination_id, $BIZ_ALIASES);
}

/* Build dynamic SET for timestamps if columns exist */
$set = ['`status`=?'];
if (column_exists($conn,'tbl_nominations','status_updated_at')) $set[]='`status_updated_at`=NOW()';
if (column_exists($conn,'tbl_nominations','updated_at'))        $set[]='`updated_at`=NOW()';
$setSql = implode(', ', $set);

/* Update (do not change a finalized status) */
$finalized = ['approved','rejected','merged'];
if ($current !== $status) {
  if (in_array($current, $finalized, true)) {
    jerr('This registration is already ' . $current . '. Status can no longer be changed.', 409);
  }
  $u = $conn->prepare("UPDATE `tbl_nominations` SET {$setSql} WHERE `nomination_id`=?");
  if (!$u) jerr('DB error (update): '.$conn->error, 500);
  $u->bind_param('si', $status, $nomination_id);
  $u->execute(); $u->close();
  log_audit($conn, $nomination_id, 'status_update', "from={$current} to={$status}");
  $current = $status;
}

/* Optional email */
if ($send_email) {
  $to = filter_var($email_to, FILTER_VALIDATE_EMAIL) ?: '';
  if ($to === '')                     jerr('Business has no valid email on file.', 422);
  if ($subject === '')                jerr('Subject is required', 422);
  if (trim(strip_tags($html)) === '') jerr('Message body is required', 422);

  $embedImage = '';
  $voteUrl = '';
  if ($status === 'approved') {
    // Approval confirms registration only. Voting QR is sent later to finalists.
    $html = nomination_html_inject_vote_assets($html, '', false);
  }

  $html = tocca_branded_status_email(
    $subject,
    $biz_name !== '' ? $biz_name : 'there',
    nomination_status_heading($status),
    $html,
    ($status === 'approved' && $voteUrl !== '') ? 'Open Voting Page' : '',
    $voteUrl,
    $status === 'approved' && $embedImage !== ''
  );

  // Guard against stray output from queue_email()
  ob_start();
  $res = queue_email($conn, [
    'event_id'        => $event_id,
    'type'            => 'nomination_status',
    'recipient_email' => $to,
    'recipient_name'  => $biz_name ?: 'Business',
    'subject'         => $subject,
    'html'            => $html,
    'embed_image'     => $embedImage,
  ]);
  // Dump any accidental echo from queue_email
  ob_end_clean();

  log_audit($conn, $nomination_id, 'email_send_attempt', "status=".($res['status'] ?? 'unknown')."; id=".($res['id'] ?? 0));

  jok([
    'message'    => (($res['status'] ?? '') === 'sent') ? 'Status updated, email sent' : 'Status updated, email failed',
    'status_new' => $current,
    'email'      => $res,
  ]);
}

/* Done */
jok(['message'=>'Status updated','status_new'=>$current]);