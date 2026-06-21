<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Shared SMTP mailer for QR emails (single + batch) with connection reuse.
 */
function qr_mailer_create(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'amfcapacio@gmail.com';
    $mail->Password   = 'gfeh ddya qzez drbr';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->SMTPKeepAlive = true;
    $mail->SMTPDebug  = 0;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom('amfcapacio@gmail.com', 'TOCCA Admin');
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
