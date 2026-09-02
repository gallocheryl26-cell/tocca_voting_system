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

/* ========= SMTP config (config.local.php / tocca_smtp_config) ========= */
function mail_config(): array {
  if (!function_exists('tocca_smtp_config')) {
    $cfgFile = dirname(__DIR__) . '/config.php';
    if (is_file($cfgFile)) {
      require_once $cfgFile;
    }
  }
  if (function_exists('tocca_smtp_config')) {
    return tocca_smtp_config();
  }
  return [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'user'       => '',
    'pass'       => '',
    'from_email' => '',
    'from_name'  => 'Tatak Ormoc',
    'secure'     => 'tls',
  ];
}

require_once __DIR__ . '/includes/branded_email.php';

/* ========= HTML helpers ========= */
function wrap_email_html(string $title, string $inner): string {
  return tocca_branded_status_email($title, '', $title, $inner);
}

function render_email_template(string $name, array $data): array {
  $safe = fn($k,$d='') => htmlspecialchars((string)($data[$k] ?? $d), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
  $hasAward = !empty($data['award_name']);
  $awardStr = $hasAward ? " in <strong>{$safe('award_name')}</strong>" : '';
  $commonFooter = '<p style="margin-top:20px;color:#777;font-size:12px;">This is an automated message from Tatak Ormoc.</p>';

  switch ($name) {
    case 'nomination_approved':
      $subject = 'Your registration is under evaluation';
      $body = "
        <p>Hi {$safe('nominator_name','there')},</p>
        <p>Your registration for <strong>{$safe('business_name')}</strong>{$awardStr} is now <strong>under evaluation</strong>.</p>
        <p>We will email your QR code and voting link only when your business is confirmed for public voting.</p>
        <p>You can view details here: <a href=\"{$safe('nomination_link','#')}\">View registration</a></p>
        {$commonFooter}";
      return [$subject, wrap_email_html($subject, $body)];

    case 'nomination_rejected':
      $subject = 'Your registration has been REJECTED';
      $body = "
        <p>Hi {$safe('nominator_name','there')},</p>
        <p>We’re sorry—your registration for <strong>{$safe('business_name')}</strong>{$awardStr} was <strong>REJECTED</strong>.</p>
        <p>If you believe this is an error, please reply to this email.</p>
        <p>Details: <a href=\"{$safe('nomination_link','#')}\">View registration</a></p>
        {$commonFooter}";
      return [$subject, wrap_email_html($subject, $body)];

    case 'nomination_needs_info':
      $subject = 'Action required: More information needed for your registration';
      $missing = nl2br($safe('missing_fields','(not specified)'));
      $body = "
        <p>Hi {$safe('nominator_name','there')},</p>
        <p>We need additional information to proceed with your registration for <strong>{$safe('business_name')}</strong>{$awardStr}.</p>
        <p><strong>What’s missing:</strong><br>{$missing}</p>
        <p>Please provide the details here: <a href=\"{$safe('nomination_link','#')}\">Update registration</a></p>
        {$commonFooter}";
      return [$subject, wrap_email_html($subject, $body)];

    case 'qr_email':
      $subject = 'Your Tatak Ormoc Voting QR Code';
      $vote   = $safe('qr_url', '#');
      $portal = $safe('vote_portal_url', $vote);
      $body = "
        <p>Your unique voting QR for <strong>{$safe('choice_name')}</strong> is ready. Your poster is attached.</p>
        <p style=\"margin:18px 0 4px;font-weight:700;\">All awards</p>
        <p style=\"margin:0 0 6px;color:#4b5563;font-size:14px;\">Share this if you want customers to browse every category and pick businesses themselves.</p>
        <p style=\"margin:0 0 4px;word-break:break-all;\"><a href=\"{$portal}\" style=\"color:#2563eb;\">{$portal}</a></p>
        <p style=\"margin:16px 0 4px;font-weight:700;\">Your business (best to promote)</p>
        <p style=\"margin:0 0 6px;color:#4b5563;font-size:14px;\">Share this on Facebook, Messenger, or posters so customers go straight to voting for your business.</p>
        <p style=\"margin:0 0 4px;word-break:break-all;\"><a href=\"{$vote}\" style=\"color:#2563eb;\">{$vote}</a></p>
        {$commonFooter}";
      return [$subject, wrap_email_html($subject, $body)];
  }

  $subject = 'Notification';
  $body = "<p>Hi {$safe('nominator_name','there')},</p><p>This is a notification.</p>{$commonFooter}";
  return [$subject, wrap_email_html($subject, $body)];
}

/* ========= Immediate sender ========= */
function send_mail_now(string $toEmail, string $toName, string $subject, string $html, ?string &$errorMsg, ?string $embedImagePath = null, ?string $attachPath = null, ?string $attachName = null): bool {
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
    $mail->CharSet    = 'UTF-8';

    // Gmail: From must match authenticated account
    $mail->setFrom($cfg['from_email'], $cfg['from_name']);
    $mail->addAddress($toEmail, $toName ?: '');

    if ($embedImagePath && is_file($embedImagePath)) {
      $mail->addEmbeddedImage($embedImagePath, 'tocca_qr', 'voting_qr.png');
    }
    if ($attachPath && is_file($attachPath) && $attachPath !== $embedImagePath) {
      $mail->addAttachment($attachPath, $attachName ?: basename($attachPath));
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;

    $plain = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
    $plain = strip_tags($plain);
    $mail->AltBody = $subject . "\n\n" . html_entity_decode($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $mail->send();
    if (method_exists($mail, 'smtpClose')) {
      $mail->smtpClose();
    }
    usleep(400000);
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
  $embedImagePath = trim((string)($params['embed_image'] ?? ''));
  $attachPath = trim((string)($params['attach_path'] ?? $params['attach_image'] ?? ''));
  $attachName = trim((string)($params['attach_name'] ?? ''));

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
  $ok  = send_mail_now(
    $toEmail,
    $toName,
    $subject,
    $html,
    $err,
    $embedImagePath !== '' ? $embedImagePath : null,
    $attachPath !== '' ? $attachPath : null,
    $attachName !== '' ? $attachName : null
  );

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
