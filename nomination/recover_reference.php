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

// Find matching nominations via email answers / email-role fields.
$sql = "
  SELECT DISTINCT
    n.nomination_id,
    n.reference_no,
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

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/nomination'))), '/');
$trackBase = $scheme . '://' . $host . $basePath . '/nomination_tracking.php';

$rowsHtml = '';
foreach ($matches as $m) {
    $ref = htmlspecialchars((string) $m['reference_no'], ENT_QUOTES, 'UTF-8');
    $status = htmlspecialchars(str_replace('_', ' ', (string) ($m['status'] ?? '')), ENT_QUOTES, 'UTF-8');
    $created = !empty($m['created_at']) ? date('M j, Y', strtotime((string) $m['created_at'])) : '';
    $link = htmlspecialchars($trackBase . '?ref=' . rawurlencode((string) $m['reference_no']), ENT_QUOTES, 'UTF-8');
    $rowsHtml .= "<tr>
      <td style=\"padding:8px 10px;border:1px solid #dbe4f3;\"><strong>{$ref}</strong></td>
      <td style=\"padding:8px 10px;border:1px solid #dbe4f3;\">" . ucwords($status) . "</td>
      <td style=\"padding:8px 10px;border:1px solid #dbe4f3;\">{$created}</td>
      <td style=\"padding:8px 10px;border:1px solid #dbe4f3;\"><a href=\"{$link}\">Track</a></td>
    </tr>";
}

$count = count($matches);
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$subject = $count === 1
    ? 'Your Tatak Ormoc registration reference'
    : 'Your Tatak Ormoc registration references';

$html = "
  <p>Hello,</p>
  <p>We received a request to recover your registration reference for <strong>{$safeEmail}</strong>.</p>
  <p>Here " . ($count === 1 ? 'is your reference number' : "are the reference numbers we found") . ":</p>
  <table style=\"border-collapse:collapse;width:100%;max-width:560px;font-family:Arial,sans-serif;font-size:14px;\">
    <thead>
      <tr style=\"background:#eef4fc;\">
        <th style=\"text-align:left;padding:8px 10px;border:1px solid #dbe4f3;\">Reference</th>
        <th style=\"text-align:left;padding:8px 10px;border:1px solid #dbe4f3;\">Status</th>
        <th style=\"text-align:left;padding:8px 10px;border:1px solid #dbe4f3;\">Submitted</th>
        <th style=\"text-align:left;padding:8px 10px;border:1px solid #dbe4f3;\">Link</th>
      </tr>
    </thead>
    <tbody>{$rowsHtml}</tbody>
  </table>
  <p style=\"margin-top:16px;\">If you did not request this, you can ignore this email.</p>
  <p>Thank you,<br>Tatak Ormoc Consumers' Choice Awards</p>
";

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
