<?php
declare(strict_types=1);
/**
 * Recover registration reference number(s) by email.
 * Always returns a generic success message (do not leak whether the email exists).
 */
session_start();
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function rr_ok(array $extra = []): void
{
    // Generic on purpose — same message for 0 or N matches.
    echo json_encode([
        'status'  => 'success',
        'message' => 'If we find a registration with that email, we will send the reference number shortly. Please check your inbox (and spam folder).',
    ] + $extra);
    exit;
}

function rr_err(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rr_err('POST only.', 405);
}

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) {
    $in = $_POST;
}

$email = strtolower(trim((string) ($in['email'] ?? '')));
$eventId = isset($in['event_id']) ? (int) $in['event_id'] : 0;

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    rr_err('Please enter a valid email address.', 422);
}

// Simple rate limit: 5 requests / hour per IP+email
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateKey = hash('sha256', $ip . '|' . $email);
$rateFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tocca_ref_recover_' . $rateKey . '.json';
$now = time();
$window = 3600;
$maxHits = 5;
$hits = [];
if (is_file($rateFile)) {
    $decoded = json_decode((string) file_get_contents($rateFile), true);
    if (is_array($decoded)) {
        $hits = array_values(array_filter(array_map('intval', $decoded), static fn($t) => ($now - $t) < $window));
    }
}
if (count($hits) >= $maxHits) {
    rr_err('Too many recovery attempts. Please wait a while and try again.', 429);
}
$hits[] = $now;
@file_put_contents($rateFile, json_encode($hits), LOCK_EX);

require_once __DIR__ . '/../tocca_admin/db_connection.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    rr_err('Database unavailable.', 500);
}
$conn->set_charset('utf8mb4');

$hasBizCol = false;
if ($col = $conn->query("SHOW COLUMNS FROM tbl_nominations LIKE 'business_name'")) {
    $hasBizCol = $col->num_rows > 0;
    $col->free();
}
$bizSelect = $hasBizCol ? 'n.business_name,' : "'' AS business_name,";

// Find matching nominations via email answers / email-role fields.
$sql = "
  SELECT DISTINCT
    n.nomination_id,
    n.reference_no,
    {$bizSelect}
    n.status,
    n.event_id,
    n.created_at
  FROM tbl_nominations n
  INNER JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id
  INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
  WHERE n.reference_no IS NOT NULL
    AND n.reference_no <> ''
    AND LOWER(TRIM(a.answer)) = ?
    AND (
      f.type = 'email'
      OR IFNULL(f.profile_role, '') = 'email'
      OR LOWER(IFNULL(f.name, '')) LIKE '%email%'
      OR LOWER(IFNULL(f.label, '')) LIKE '%email%'
    )
";
$types = 's';
$params = [$email];
if ($eventId > 0) {
    $sql .= ' AND n.event_id = ?';
    $types .= 'i';
    $params[] = $eventId;
}
$sql .= ' ORDER BY n.created_at DESC LIMIT 10';

$st = $conn->prepare($sql);
if (!$st) {
    // Fallback if profile_role column missing
    $sql = "
      SELECT DISTINCT
        n.nomination_id,
        n.reference_no,
        {$bizSelect}
        n.status,
        n.event_id,
        n.created_at
      FROM tbl_nominations n
      INNER JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id
      INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
      WHERE n.reference_no IS NOT NULL
        AND n.reference_no <> ''
        AND LOWER(TRIM(a.answer)) = ?
        AND (
          f.type = 'email'
          OR LOWER(IFNULL(f.name, '')) LIKE '%email%'
          OR LOWER(IFNULL(f.label, '')) LIKE '%email%'
        )
    ";
    $types = 's';
    $params = [$email];
    if ($eventId > 0) {
        $sql .= ' AND n.event_id = ?';
        $types .= 'i';
        $params[] = $eventId;
    }
    $sql .= ' ORDER BY n.created_at DESC LIMIT 10';
    $st = $conn->prepare($sql);
}
if (!$st) {
    rr_err('Unable to look up registrations.', 500);
}
$st->bind_param($types, ...$params);
$st->execute();
$res = $st->get_result();
$matches = [];
while ($row = $res->fetch_assoc()) {
    $matches[] = $row;
}
$st->close();

// Always respond generically; only send mail when we have matches.
if ($matches === []) {
    rr_ok();
}

require_once __DIR__ . '/nomination_field_helpers.php';
require_once __DIR__ . '/../tocca_admin/qr_url.php';
require_once __DIR__ . '/../tocca_admin/comm.php';

function rr_business_name(mysqli $conn, array $row): string
{
    $nid = (int) ($row['nomination_id'] ?? 0);
    $fallback = trim((string) ($row['business_name'] ?? ''));
    if ($nid <= 0) {
        return function_exists('nf_is_ownership_type_value') && nf_is_ownership_type_value($fallback)
            ? ''
            : $fallback;
    }
    $roleSelect = '';
    if (function_exists('nf_column_exists') && nf_column_exists($conn, 'profile_role')) {
        $roleSelect = ', f.profile_role AS profile_role';
    }
    $st = $conn->prepare(
        "SELECT f.name AS name, f.label AS label{$roleSelect}, a.answer AS answer
           FROM tbl_nomination_answers a
           INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
          WHERE a.nomination_id = ?
            AND TRIM(IFNULL(a.answer, '')) <> ''"
    );
    if (!$st) {
        return function_exists('nf_is_ownership_type_value') && nf_is_ownership_type_value($fallback)
            ? ''
            : $fallback;
    }
    $st->bind_param('i', $nid);
    $st->execute();
    $res = $st->get_result();
    $fields = [];
    while ($r = $res->fetch_assoc()) {
        $fields[] = $r;
    }
    $st->close();
    if (function_exists('nf_pick_business_name')) {
        return nf_pick_business_name($fields, $fallback);
    }
    return $fallback;
}

$th = 'text-align:left;padding:10px 12px;border:1px solid #e5e7eb;color:#111111;font-size:13px;font-weight:700;background:#f3f4f6;';
$td = 'padding:10px 12px;border:1px solid #e5e7eb;color:#111111;font-size:14px;vertical-align:top;';

$rowsHtml = '';
$firstTrackUrl = '';
foreach ($matches as $m) {
    $ref = htmlspecialchars((string) $m['reference_no'], ENT_QUOTES, 'UTF-8');
    $biz = htmlspecialchars(rr_business_name($conn, $m) ?: '—', ENT_QUOTES, 'UTF-8');
    $status = htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($m['status'] ?? ''))), ENT_QUOTES, 'UTF-8');
    $created = !empty($m['created_at']) ? date('M j, Y', strtotime((string) $m['created_at'])) : '—';
    $link = qr_tracking_url_with_ref($conn instanceof mysqli ? $conn : null, (string) $m['reference_no']);
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    if ($firstTrackUrl === '') {
        $firstTrackUrl = $link;
    }
    $rowsHtml .= "<tr>
      <td style=\"{$td}\">{$biz}</td>
      <td style=\"{$td}\"><strong>{$ref}</strong></td>
      <td style=\"{$td}\">{$status}</td>
      <td style=\"{$td}\">{$created}</td>
      <td style=\"{$td}\"><a href=\"{$safeLink}\" style=\"color:#2563eb;font-weight:700;text-decoration:underline;\">Track</a></td>
    </tr>";
}

$count = count($matches);
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$subject = $count === 1
    ? 'Your Tatak Ormoc registration reference'
    : 'Your Tatak Ormoc registration references';
$heading = $count === 1 ? 'Your registration reference' : 'Your registration references';

$inner = '
  <p style="margin:0 0 14px;">We received a request to recover your registration reference for <strong>' . $safeEmail . '</strong>.</p>
  <p style="margin:0 0 16px;">Here ' . ($count === 1 ? 'is your registration' : 'are the registrations we found') . '. Use the reference number to track your registration.</p>
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;width:100%;margin:0 0 18px;">
    <thead>
      <tr>
        <th style="' . $th . '">Business name</th>
        <th style="' . $th . '">Reference</th>
        <th style="' . $th . '">Status</th>
        <th style="' . $th . '">Submitted</th>
        <th style="' . $th . '">Link</th>
      </tr>
    </thead>
    <tbody>' . $rowsHtml . '</tbody>
  </table>
  <p style="margin:0;">If you did not request this, you can ignore this email.</p>
';

$html = tocca_branded_status_email(
    $subject,
    '',
    $heading,
    $inner,
    $count === 1 && $firstTrackUrl !== '' ? 'Track registration' : '',
    $count === 1 ? $firstTrackUrl : ''
);

try {
    require_once __DIR__ . '/../tocca_admin/mailer_helper.php';
    $eventForLog = $eventId > 0 ? $eventId : (int) ($matches[0]['event_id'] ?? 0);
    $send = comm_send_and_log($conn, [
        'event_id' => $eventForLog > 0 ? $eventForLog : null,
        'type'     => 'nomination_reference_recover',
        'to_email' => $email,
        'to_name'  => '',
        'subject'  => $subject,
        'html'     => $html,
    ]);
    if (empty($send['ok'])) {
        // Log only — same generic client response whether send succeeded or not.
        error_log('recover_reference mail failed: ' . ($send['error'] ?? 'unknown'));
    }
} catch (Throwable $e) {
    error_log('recover_reference exception: ' . $e->getMessage());
}

rr_ok();
