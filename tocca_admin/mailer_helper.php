<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'db_connection.php';
require __DIR__ . '/../vendor/autoload.php';

function comm_log_create(mysqli $conn, array $data): int {
  $sql = "INSERT INTO tbl_comm_messages
          (event_id, type, recipient_email, recipient_name, subject, body_html, status, error_text, retries, created_at, scheduled_at, sent_at)
          VALUES (?, ?, ?, ?, ?, ?, 'pending', NULL, 0, NOW(), ?, NULL)";
  $stmt = $conn->prepare($sql);
  $event_id = $data['event_id'] ?? null;
  $type     = $data['type'] ?? 'other';
  $toEmail  = $data['recipient_email'] ?? '';
  $toName   = $data['recipient_name'] ?? null;
  $subject  = $data['subject'] ?? '';
  $body     = $data['body_html'] ?? '';
  $sched    = $data['scheduled_at'] ?? null;

  $stmt->bind_param(
    'issssss',
    $event_id, $type, $toEmail, $toName, $subject, $body, $sched
  );
  if (!$stmt->execute()) {
    throw new RuntimeException('Failed to create comm log: ' . $stmt->error);
  }
  return (int)$conn->insert_id;
}

function comm_log_mark_sent(mysqli $conn, int $id) {
  $stmt = $conn->prepare("UPDATE tbl_comm_messages SET status='sent', sent_at=NOW() WHERE id=?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
}

function comm_log_mark_failed(mysqli $conn, int $id, string $error, int $retries = 0) {
  $stmt = $conn->prepare("UPDATE tbl_comm_messages SET status='failed', error_text=?, retries=? WHERE id=?");
  $stmt->bind_param('sii', $error, $retries, $id);
  $stmt->execute();
}

/**
 * Send an email and log it. Returns ['ok'=>bool, 'id'=>int, 'error'=>?string]
 * $opts = [
 *   'event_id' => ?int,
 *   'type' => 'qr_email'|'nomination_status'|'other',
 *   'to_email' => 'recipient@example.com',
 *   'to_name'  => 'Recipient',
 *   'subject'  => '...',
 *   'html'     => '<p>...</p>',
 *   'scheduled_at' => ?'YYYY-MM-DD HH:MM:SS'
 * ]
 */
function comm_send_and_log(mysqli $conn, array $opts): array {
  // 1) Create log row (pending)
  $logId = comm_log_create($conn, [
    'event_id'        => $opts['event_id'] ?? null,
    'type'            => $opts['type'] ?? 'other',
    'recipient_email' => $opts['to_email'] ?? '',
    'recipient_name'  => $opts['to_name'] ?? null,
    'subject'         => $opts['subject'] ?? '',
    'body_html'       => $opts['html'] ?? '',
    'scheduled_at'    => $opts['scheduled_at'] ?? null,
  ]);

  // 2) Actually send with PHPMailer
  $mail = new PHPMailer(true);
  try {
    // SMTP config (adjust to your environment)
    // $mail->SMTPDebug = 2;
    $cfg = function_exists('tocca_smtp_config') ? tocca_smtp_config() : [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'secure' => 'tls',
        'user' => '',
        'pass' => '',
        'from_email' => '',
        'from_name' => 'Tatak Ormoc',
    ];
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['user'];
    $mail->Password   = $cfg['pass'];
    $mail->SMTPSecure = $cfg['secure'];
    $mail->Port       = (int) $cfg['port'];

    $mail->CharSet    = 'UTF-8';
    $mail->Encoding   = 'base64';

    $fromEmail = $cfg['from_email'] !== '' ? $cfg['from_email'] : $cfg['user'];
    $mail->setFrom($fromEmail, $cfg['from_name'] !== '' ? $cfg['from_name'] : 'Tatak Ormoc');
    $mail->addAddress($opts['to_email'], $opts['to_name'] ?? '');

    $mail->isHTML(true);
    $mail->Subject = (string)$opts['subject'];
    $mail->Body    = (string)$opts['html'];

    // Optional: plain text version
    $mail->AltBody = strip_tags((string)$opts['html']);

    $mail->send();
    comm_log_mark_sent($conn, $logId);
    return ['ok'=>true, 'id'=>$logId, 'error'=>null];
  } catch (Exception $e) {
    comm_log_mark_failed($conn, $logId, $mail->ErrorInfo ?: $e->getMessage());
    return ['ok'=>false, 'id'=>$logId, 'error'=>$mail->ErrorInfo ?: $e->getMessage()];
  }
}

/* ----------------------------------------------------------------------
 * Helper: QR email sender
 * ---------------------------------------------------------------------- */

/**
 * Send the QR email (and log it). Returns same structure as comm_send_and_log.
 * Note: $qrPngPath is included for future attachment use; currently HTML body
 * links/embeds by URL ($qrUrl).
 */
function send_qr_email_for_choice(mysqli $conn, int $event_id, string $toEmail, string $toName, string $qrUrl, string $qrPngPath) {
  require_once __DIR__ . '/includes/qr_email_body.php';
  if (!function_exists('qr_vote_portal_url')) {
    require_once __DIR__ . '/qr_url.php';
  }
  $subject = 'Your QR Code for Tatak Ormoc Voting';
  $portalUrl = function_exists('qr_vote_portal_url') ? qr_vote_portal_url($conn) : '';
  $html = qr_email_build_html($toName, qr_email_default_plain_message(), $portalUrl, $subject, $qrUrl);

  return comm_send_and_log($conn, [
    'event_id' => $event_id,
    'type'     => 'qr_email',
    'to_email' => $toEmail,
    'to_name'  => $toName,
    'subject'  => $subject,
    'html'     => $html,
  ]);
}

/* ----------------------------------------------------------------------
 * Optional Helper: Registration status email sender
 * ---------------------------------------------------------------------- */

/**
 * Send a registration status email (approve/reject/needs_more_info) and log it.
 * $status: 'approved' | 'rejected' | 'needs_more_info'
 */
function send_nomination_status_email(mysqli $conn, int $event_id, string $toEmail, string $toName, string $status, string $businessName, ?string $adminNote = null) {
  if (strtolower($status) === 'approved') {
    return ['status' => 'skipped', 'id' => 0];
  }
  $prettyStatus = ucwords(str_replace('_',' ', $status));
  $subject = "Registration Update: {$prettyStatus}";

  $safeName    = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
  $safeBiz     = htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8');
  $safeStatus  = htmlspecialchars(str_replace('_',' ', $status), ENT_QUOTES, 'UTF-8');
  $noteBlock   = $adminNote ? "<p><strong>Note from Admin:</strong><br>".nl2br(htmlspecialchars($adminNote, ENT_QUOTES, 'UTF-8'))."</p>" : "";

  $html = "
    <p>Hi {$safeName},</p>
    <p>Your registration for <strong>{$safeBiz}</strong> has been <strong>{$safeStatus}</strong>.</p>
    {$noteBlock}
    <p>Thank you for participating in Tatak Ormoc.</p>
  ";

  return comm_send_and_log($conn, [
    'event_id' => $event_id,
    'type'     => 'nomination_status',
    'to_email' => $toEmail,
    'to_name'  => $toName,
    'subject'  => $subject,
    'html'     => $html,
  ]);
}

/**
 * Send a registration receipt email with the reference number (for tracking / recovery).
 */
function send_nomination_receipt_email(
  mysqli $conn,
  int $event_id,
  string $toEmail,
  string $toName,
  string $businessName,
  string $referenceNo,
  ?string $trackUrl = null
) {
  require_once __DIR__ . '/includes/branded_email.php';
  if (!function_exists('qr_tracking_url_with_ref')) {
    require_once __DIR__ . '/qr_url.php';
  }

  $biz = trim($businessName) !== '' ? trim($businessName) : 'your business';
  $greeting = $biz !== 'your business' ? $biz : (trim($toName) !== '' ? trim($toName) : 'there');
  $ref = trim($referenceNo);
  $subject = 'Registration received';

  if (($trackUrl === null || $trackUrl === '') && $ref !== '' && function_exists('qr_tracking_url_with_ref')) {
    $trackUrl = qr_tracking_url_with_ref($conn, $ref);
  }

  $safeBiz = htmlspecialchars($biz, ENT_QUOTES, 'UTF-8');
  $safeRef = htmlspecialchars($ref, ENT_QUOTES, 'UTF-8');
  $inner = '
    <p style="margin:0 0 14px;">Thank you for registering <strong>' . $safeBiz . '</strong> for the Tatak Ormoc Consumers&rsquo; Choice Awards.</p>
    <p style="margin:0 0 14px;">We have received your application. Please save this reference number. You will need it to track your registration:</p>
    <p style="margin:8px 0 18px;font-size:22px;font-weight:700;letter-spacing:0.06em;">' . $safeRef . '</p>
    <p style="margin:0 0 14px;">We will email you again if we need more information, and when your business is confirmed for public voting. That later message will include your QR code and voting link.</p>
    <p style="margin:0 0 14px;">If you forget this number, use <strong>Forgot your reference number?</strong> on Track My Registration with this email address.</p>
  ';

  $html = tocca_branded_status_email(
    $subject,
    $greeting,
    'Registration received',
    $inner,
    ($trackUrl !== null && $trackUrl !== '') ? 'Track registration' : '',
    (string) ($trackUrl ?? '')
  );

  return comm_send_and_log($conn, [
    'event_id' => $event_id,
    'type'     => 'nomination_receipt',
    'to_email' => $toEmail,
    'to_name'  => $greeting,
    'subject'  => $subject,
    'html'     => $html,
  ]);
}
