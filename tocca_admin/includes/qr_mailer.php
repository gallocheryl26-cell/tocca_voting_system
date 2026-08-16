<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Shared SMTP mailer for QR emails (single + batch) with connection reuse.
 */
function qr_mailer_create(): PHPMailer
{
    if (!function_exists('tocca_smtp_config')) {
        $cfgFile = dirname(__DIR__, 2) . '/config.php';
        if (is_file($cfgFile)) {
            require_once $cfgFile;
        }
    }
    $cfg = function_exists('tocca_smtp_config') ? tocca_smtp_config() : [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'secure' => 'tls',
        'user' => '',
        'pass' => '',
        'from_email' => '',
        'from_name' => 'Tatak Ormoc',
    ];

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['user'];
    $mail->Password   = $cfg['pass'];
    $mail->SMTPSecure = ($cfg['secure'] === 'ssl')
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int) $cfg['port'];
    $mail->SMTPKeepAlive = true;
    $mail->SMTPDebug  = 0;
    $mail->CharSet    = 'UTF-8';
    $fromEmail = $cfg['from_email'] !== '' ? $cfg['from_email'] : $cfg['user'];
    $mail->setFrom($fromEmail, $cfg['from_name'] !== '' ? $cfg['from_name'] : 'Tatak Ormoc');
    $mail->isHTML(true);
    return $mail;
}

function qr_mailer_reset_recipient(PHPMailer $mail): void
{
    $mail->clearAddresses();
    $mail->clearAttachments();
    $mail->clearReplyTos();
    $mail->clearCCs();
    $mail->clearBCCs();
}

/**
 * @throws Exception
 */
function qr_mailer_send_with_attachment(
    PHPMailer $mail,
    string $toEmail,
    string $toName,
    string $subject,
    string $html,
    string $qrPath
): void {
    qr_mailer_reset_recipient($mail);
    $mail->addAddress($toEmail, $toName !== '' ? $toName : 'Valued Recipient');
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->addAttachment($qrPath, 'your_qr_code.png');
    $mail->send();
}

function qr_mailer_close(PHPMailer $mail): void
{
    if (method_exists($mail, 'smtpClose')) {
        $mail->smtpClose();
    }
}
