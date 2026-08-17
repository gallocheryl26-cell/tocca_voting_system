<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/comm.php';
require_once __DIR__ . '/audit_log.php';
date_default_timezone_set('Asia/Manila');

function fail($m,$c=400){ http_response_code($c); echo json_encode(['status'=>'error','message'=>$m]); exit; }
function ok($p=[]){ echo json_encode(['status'=>'success'] + $p); exit; }

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

$id = isset($in['id']) ? (int)$in['id'] : 0;
if (!$id) fail('Missing id');

$stmt = $conn->prepare("SELECT recipient_email, recipient_name, subject, body_html FROM tbl_comm_messages WHERE id=?");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) fail('Message not found', 404);

$err = null;
$ok  = send_mail_now($row['recipient_email'], $row['recipient_name'] ?? '', $row['subject'], $row['body_html'], $err);

if ($ok) {
  $u = $conn->prepare("UPDATE tbl_comm_messages SET status='sent', sent_at=NOW(), error_text=NULL WHERE id=?");
  $u->bind_param('i', $id);
  $u->execute();
  audit_log($conn, 'communications', 'send_email', 'message', $id, [
    'choice_name' => (string) ($row['recipient_name'] ?? ''),
    'recipient_email' => (string) ($row['recipient_email'] ?? ''),
    'resend' => true,
  ]);
  ok();
} else {
  $u = $conn->prepare("UPDATE tbl_comm_messages SET status='failed', error_text=CONCAT(IFNULL(error_text,''), '\n[RESEND] ', ?) WHERE id=?");
  $u->bind_param('si', $err, $id);
  $u->execute();
  fail($err ?: 'Send failed');
}
