<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/require_admin_api.php';
}

require_once __DIR__ . '/db_connection.php';
require_once 'mailer_helper.php'; // must provide make_mailer() returning configured PHPMailer

date_default_timezone_set('Asia/Manila');
$conn->set_charset('utf8mb4');

$batch = 50;
$stmt = $conn->prepare("
  SELECT * FROM tbl_msg_outbox
  WHERE status='queued' AND (scheduled_at IS NULL OR scheduled_at <= NOW())
  ORDER BY priority ASC, outbox_id ASC
  LIMIT ?
");
$stmt->bind_param('i', $batch);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
  $conn->begin_transaction();

  $lock = $conn->prepare("UPDATE tbl_msg_outbox SET status='sending' WHERE outbox_id=? AND status='queued'");
  $lock->bind_param('i', $row['outbox_id']);
  $lock->execute();
  if ($conn->affected_rows === 0) { $conn->rollback(); continue; }

  try {
    $mail = make_mailer();
    $mail->clearAddresses(); $mail->clearAttachments();
    $mail->addAddress($row['to_address'], $row['to_name'] ?: '');
    $mail->Subject = $row['subject_rendered'] ?? '';
    $mail->isHTML(true);

    // attachments
    $atts = $conn->query("SELECT * FROM tbl_msg_attachment WHERE outbox_id=".$row['outbox_id']);
    while ($a = $atts->fetch_assoc()) {
      if ((int)$a['is_inline'] === 1 && $a['content_id']) {
        $mail->addEmbeddedImage($a['file_path'], $a['content_id'], $a['filename'], 'base64', $a['mime_type']);
      } else {
        $mail->addAttachment($a['file_path'], $a['filename']);
      }
    }

    $mail->Body    = $row['body_rendered'];
    $mail->AltBody = strip_tags($row['body_rendered']);

    if (!$mail->send()) throw new Exception($mail->ErrorInfo ?: 'Unknown send error');

    $ok = $conn->prepare("UPDATE tbl_msg_outbox SET status='sent', sent_at=NOW(), last_error=NULL WHERE outbox_id=?");
    $ok->bind_param('i', $row['outbox_id']);
    $ok->execute();

    $conn->commit();
  } catch (Throwable $e) {
    $retry  = (int)$row['retry_count'] + 1;
    $status = ($retry >= (int)$row['max_retries']) ? 'failed' : 'queued';
    $msg    = substr($e->getMessage(), 0, 1000);

    $fail = $conn->prepare("UPDATE tbl_msg_outbox SET status=?, retry_count=?, last_error=? WHERE outbox_id=?");
    $fail->bind_param('sisi', $status, $retry, $msg, $row['outbox_id']);
    $fail->execute();

    $conn->commit();
  }
}
