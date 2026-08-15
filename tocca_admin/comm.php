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
  return tocca_branded_status_email($title, '', $title, $inner);
}

/**
 * Dark branded status email (header, heading, optional CTA + QR, footer).
 */
function tocca_branded_status_email(
    string $subject,
    string $greetingName,
    string $heading,
    string $bodyHtml,
    string $ctaLabel = '',
    string $ctaUrl = '',
    bool $includeQr = false
): string {
    $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeHeading = htmlspecialchars($heading !== '' ? $heading : $subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $name = trim($greetingName);
    $greeting = $name !== ''
        ? 'Hello ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ','
        : 'Hello,';
    $year = date('Y');
    $inner = preg_replace(
        '/\s*color\s*:\s*(#0{3,6}|#111|#111111|#222|#222222|#333|#1a1a1a|black|rgb\(\s*0\s*,\s*0\s*,\s*0\s*\))\s*;?/i',
        '',
        $bodyHtml
    ) ?? $bodyHtml;

    $ctaBlock = '';
    if ($ctaUrl !== '' && $ctaLabel !== '') {
        $safeUrl = htmlspecialchars($ctaUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeCta = htmlspecialchars($ctaLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ctaBlock = '
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 12px;">
            <tr>
              <td bgcolor="#2563eb" style="border-radius:6px;">
                <a href="' . $safeUrl . '" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;">' . $safeCta . '</a>
              </td>
            </tr>
          </table>
          <p style="margin:0 0 8px;color:#9ca3af;font-size:13px;line-height:1.5;">If the button does not work, copy and paste this link into your browser:</p>
          <p style="margin:0 0 20px;word-break:break-all;"><a href="' . $safeUrl . '" style="color:#60a5fa;font-size:13px;">' . $safeUrl . '</a></p>';
    }

    $qrBlock = '';
    if ($includeQr) {
        $qrBlock = '
          <p style="margin:8px 0 12px;color:#e5e7eb;font-size:15px;">You can also print or display this QR code:</p>
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 8px;">
            <tr>
              <td bgcolor="#ffffff" style="padding:12px;border-radius:8px;">
                <img src="cid:tocca_qr" alt="Voting QR code" width="240" height="240" style="display:block;width:240px;height:240px;border:0;">
              </td>
            </tr>
          </table>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . $safeSubject . '</title>
</head>
<body style="margin:0;padding:0;background:#111111;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#111111" style="background:#111111;">
    <tr>
      <td align="center" style="padding:24px 12px;">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;font-family:Arial,Helvetica,sans-serif;">
          <tr><td bgcolor="#2563eb" style="height:4px;line-height:4px;font-size:0;">&nbsp;</td></tr>
          <tr>
            <td bgcolor="#1a1a1a" style="padding:28px 32px;background:#1a1a1a;">
              <div style="color:#ffffff;font-size:11px;letter-spacing:1.6px;font-weight:700;">CITY GOVERNMENT OF ORMOC</div>
              <div style="color:#ffffff;font-size:26px;font-weight:700;margin-top:8px;line-height:1.2;">Tatak Ormoc</div>
              <div style="color:#9ca3af;font-size:14px;margin-top:6px;">Consumers&rsquo; Choice Awards (TOCCA)</div>
            </td>
          </tr>
          <tr>
            <td bgcolor="#000000" style="padding:32px;background:#000000;color:#e5e7eb;">
              <p style="margin:0 0 18px;color:#ffffff;font-size:16px;">' . $greeting . '</p>
              <h1 style="margin:0 0 18px;color:#ffffff;font-size:28px;line-height:1.25;font-weight:700;">' . $safeHeading . '</h1>
              <div style="color:#e5e7eb;font-size:15px;line-height:1.65;">' . $inner . '</div>
              ' . $ctaBlock . $qrBlock . '
            </td>
          </tr>
          <tr>
            <td bgcolor="#2a2a2a" style="padding:24px 32px;background:#2a2a2a;">
              <p style="margin:0 0 10px;color:#ffffff;font-size:14px;font-weight:700;">This is an automated message. Please do not reply.</p>
              <p style="margin:0 0 14px;color:#9ca3af;font-size:13px;line-height:1.5;">For assistance, open the registration tracking page or contact the TOCCA secretariat at <a href="mailto:support@tatakormoc.com" style="color:#60a5fa;">support@tatakormoc.com</a>.</p>
              <p style="margin:0;color:#6b7280;font-size:12px;">&copy; ' . $year . ' City Government of Ormoc &mdash; Tatak Ormoc Consumers&rsquo; Choice Awards</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

function render_email_template(string $name, array $data): array {
  $safe = fn($k,$d='') => htmlspecialchars((string)($data[$k] ?? $d), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
  $hasAward = !empty($data['award_name']);
  $awardStr = $hasAward ? " in <strong>{$safe('award_name')}</strong>" : '';
  $commonFooter = '<p style="margin-top:20px;color:#777;font-size:12px;">This is an automated message from Tatak Ormoc.</p>';

  switch ($name) {
    case 'nomination_approved':
      $subject = 'Your registration has been APPROVED';
      $body = "
        <p>Hi {$safe('nominator_name','there')},</p>
        <p>Your registration for <strong>{$safe('business_name')}</strong>{$awardStr} has been <strong>APPROVED</strong>.</p>"
        . (!empty($data['voting_start']) ? "<p>Voting starts on <strong>{$safe('voting_start')}</strong>.</p>" : "")
        . "<p>You can view details here: <a href=\"{$safe('nomination_link','#')}\">View registration</a></p>
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
function send_mail_now(string $toEmail, string $toName, string $subject, string $html, ?string &$errorMsg, ?string $embedImagePath = null, ?string $attachPath = null): bool {
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
      $mail->addAttachment($attachPath, 'voting_qr_poster.png');
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
  $attachPath = trim((string)($params['attach_image'] ?? ''));

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
    $attachPath !== '' ? $attachPath : null
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
