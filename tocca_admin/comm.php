<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ========= PHPMailer loader ========= */
function try_load_phpmailer(): bool {
  static $loaded = null;
  if ($loaded !== null) return $loaded;

  $candidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
  ];
  foreach ($candidates as $file) {
    if (is_file($file)) { require_once $file; return $loaded = true; }
  }

  $manualBase = __DIR__ . '/lib/PHPMailer/src';
  if (is_file("$manualBase/PHPMailer.php") && is_file("$manualBase/SMTP.php") && is_file("$manualBase/Exception.php")) {
    require_once "$manualBase/Exception.php";
    require_once "$manualBase/PHPMailer.php";
    require_once "$manualBase/SMTP.php";
    return $loaded = true;
  }
  return $loaded = false;
}

/* ========= SMTP config =========
   TIP for Gmail: set from_email SAME as user to avoid SPF/DMARC issues. */
function mail_config(): array {
  return [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'user'       => 'amfcapacio@gmail.com',
    'pass'       => 'gfeh ddya qzez drbr', // Gmail App Password
    'from_email' => 'amfcapacio@gmail.com', // align with 'user' for Gmail
    'from_name'  => 'Tatak Ormoc',
    'secure'     => 'tls', // or 'ssl'
  ];
}

/* ========= HTML helpers ========= */
function wrap_email_html(string $title, string $inner): string {
  return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>"
    . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    . "</title></head>
  <body style=\"font-family:Arial,Helvetica,sans-serif;line-height:1.5;font-size:14px;color:#222;\">
    <div style=\"max-width:640px;margin:0 auto;padding:16px;border:1px solid #eee;border-radius:8px;\">{$inner}</div>
  </body></html>";
}

function render_email_template(string $name, array $data): array {
  $safe = fn($k,$d='') => htmlspecialchars((string)($data[$k] ?? $d), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
  $hasAward = !empty($data['award_name']);
  $awardStr = $hasAward ? " in <strong>{$safe('award_name')}</strong>" : '';
  $commonFooter = '<p style="margin-top:20px;color:#777;font-size:12px;">This is an automated message from Tatak Ormoc.</p>';

  switch ($name) {
    case 'nomination_approved':
      $subject = 'Your nomination has been APPROVED';
      $body = "
        <p>Hi {$safe('nominator_name','there')},</p>
        <p>Your nomination for <strong>{$safe('business_name')}</strong>{$awardStr} has been <strong>APPROVED</strong>.</p>"
        . (!empty($data['voting_start']) ? "<p>Voting starts on <strong>{$safe('voting_start')}</strong>.</p>" : "")
        . "<p>You can view details here: <a href=\"{$safe('nomination_link','#')}\">View nomination</a></p>
        {$commonFooter}";
      return [$subject, wrap_email_html($subject, $body)];

    case 'nomination_rejected':
      $subject = 'Your nomination has been REJECTED';
      $body = "
        <p>Hi {$safe('nominator_name','there')},</p>
        <p>We’re sorry—your nomination for <strong>{$safe('business_name')}</strong>{$awardStr} was <strong>REJECTED</strong>.</p>
        <p>If you believe this is an error, please reply to this email.</p>
        <p>Details: <a href=\"{$safe('nomination_link','#')}\">View nomination</a></p>
        {$commonFooter}";
      return [$subject, wrap_email_html($subject, $body)];

    case 'nomination_needs_info':
      $subject = 'Action required: More information needed for your nomination';
      $missing = nl2br($safe('missing_fields','(not specified)'));
      $body = "
        <p>Hi {$safe('nominator_name','there')},</p>
        <p>We need additional information to proceed with your nomination for <strong>{$safe('business_name')}</strong>{$awardStr}.</p>
        <p><strong>What’s missing:</strong><br>{$missing}</p>
        <p>Please provide the details here: <a href=\"{$safe('nomination_link','#')}\">Update nomination</a></p>
        {$commonFooter}";
      return [$subject, wrap_email_html($subject, $body)];

    case 'qr_email':
      $subject = 'Your Tatak Ormoc Voting QR Code';
      $vote   = $safe('qr_url', '#');
      $body = "
        <p>Hi {$safe('choice_name', 'there')},</p>
        <p>Your unique voting QR for <strong>{$safe('choice_name')}</strong> is ready. Your poster is attached.</p>
        <p style=\"margin:20px 0;\">
          <a href=\"{$vote}\" style=\"display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:600;\">
            Vote for us
          </a>
        </p>
        <p>Direct voting link (share with customers): <a href=\"{$vote}\">{$vote}</a></p>
        {$commonFooter}";
      return [$subject, wrap_email_html($subject, $body)];
  }

  $subject = 'Notification';
  $body = "<p>Hi {$safe('nominator_name','there')},</p><p>This is a notification.</p>{$commonFooter}";
  return [$subject, wrap_email_html($subject, $body)];
}

/* ========= Immediate sender ========= */
function send_mail_now(string $toEmail, string $toName, string $subject, string $html, ?string &$errorMsg): bool {
  if (!try_load_phpmailer()) {
    $errorMsg = 'PHPMailer not installed (vendor/autoload.php not found).';
    return false;
  }

  $cfg = mail_config();
  $mail = new PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['user'];
    $mail->Password   = $cfg['pass'];
    $mail->SMTPSecure = $cfg['secure']; // 'tls' or 'ssl'
    $mail->Port       = (int)$cfg['port'];

    // Gmail: From must match authenticated account
    $mail->setFrom($cfg['from_email'], $cfg['from_name']);
    $mail->addAddress($toEmail, $toName ?: '');

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;

    $plain = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
    $plain = strip_tags($plain);
    $mail->AltBody = $subject . "\n\n" . html_entity_decode($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $mail->send();
    return true;
  } catch (Exception $e) {
    $errorMsg = $e->getMessage();
    return false;
  }
}

/* ========= Option A: insert + send now + update row =========
   Returns: ['id'=>int, 'status'=>'sent'|'failed', 'error'=>string|null] */
function queue_email(mysqli $conn, array $params): array {
  $eventId = isset($params['event_id']) ? (int)$params['event_id'] : null;

  // Accept both naming styles
  $toName  = trim((string)($params['to_name'] ?? $params['recipient_name'] ?? ''));
  $toEmail = trim((string)($params['to_address'] ?? $params['recipient_email'] ?? ''));
  if ($toEmail === '') throw new InvalidArgumentException('to_address/recipient_email is required');

  $type   = (string)($params['type'] ?? 'other'); // e.g., 'nomination_status', 'qr_email'
  $tpl    = (string)($params['template_name'] ?? '');
  $data   = (array)($params['data'] ?? []);

  $subject = trim((string)($params['subject'] ?? ''));
  $html    = (string)($params['html'] ?? $params['body_html'] ?? '');

  if ($html === '') {
    [$subjectFromTpl, $bodyFromTpl] = render_email_template($tpl ?: 'notification', $data);
    if ($subject === '') $subject = $subjectFromTpl;
    $html = $bodyFromTpl;
  }

  // 1) Log as pending
  $stmt = $conn->prepare("
    INSERT INTO tbl_comm_messages
      (event_id, type, recipient_email, recipient_name, subject, body_html,
       status, error_text, retries, created_at, scheduled_at, sent_at)
    VALUES (?, ?, ?, ?, ?, ?, 'pending', NULL, 0, NOW(), NOW(), NULL)
  ");
  $stmt->bind_param('isssss', $eventId, $type, $toEmail, $toName, $subject, $html);
  $stmt->execute();
  $logId = (int)$stmt->insert_id;
  $stmt->close();

  // 2) Try to send immediately
  $err = null;
  $ok  = send_mail_now($toEmail, $toName, $subject, $html, $err);

  // 3) Update row
  if ($ok) {
    $upd = $conn->prepare("UPDATE tbl_comm_messages SET status='sent', error_text=NULL, sent_at=NOW() WHERE id=?");
    $upd->bind_param('i', $logId);
    $upd->execute();
    $upd->close();
    return ['id'=>$logId, 'status'=>'sent', 'error'=>null];
  } else {
    $upd = $conn->prepare("UPDATE tbl_comm_messages SET status='failed', error_text=?, retries=retries+1 WHERE id=?");
    $upd->bind_param('si', $err, $logId);
    $upd->execute();
    $upd->close();
    return ['id'=>$logId, 'status'=>'failed', 'error'=>$err ?: 'Unknown error'];
  }
}
