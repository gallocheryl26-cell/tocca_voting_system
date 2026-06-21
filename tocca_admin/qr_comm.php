<?php
// qr_comm.php
require_once 'db_connection.php';
require_once 'comm.php';     // queue_email(...)
require_once 'qr_utils.php'; // make_qr_url_for_choice(..), ensure_qr_png_for_choice(..)

function queue_qr_email_for_choice(mysqli $conn, int $choice_id) {
  // Load choice
  $stmt = $conn->prepare("SELECT choice_name, email, event_id FROM tbl_choices WHERE choice_id=?");
  $stmt->bind_param('i', $choice_id);
  $stmt->execute();
  // handle mysqlnd/no-mysqlnd safely
  if (method_exists($stmt, 'get_result')) {
    $c = $stmt->get_result()->fetch_assoc();
  } else {
    $stmt->bind_result($choice_name, $email, $event_id);
    $c = $stmt->fetch() ? ['choice_name'=>$choice_name, 'email'=>$email, 'event_id'=>$event_id] : null;
  }
  if (!$c || empty($c['email'])) {
    throw new RuntimeException("Choice not found or no email");
  }

  // Build template data (adjust to your utils)
  $qrUrl = make_qr_url_for_choice($choice_id);
  $qrPng = ensure_qr_png_for_choice($choice_id);

  // Optional: guard against duplicate queueing in the last hour
  $dupe = $conn->prepare("
    SELECT 1 FROM tbl_msg_outbox
    WHERE message_type='QR_EMAIL' AND related_type='choice' AND related_id=? AND created_at >= (NOW() - INTERVAL 1 HOUR)
    LIMIT 1
  ");
  $dupe->bind_param('i', $choice_id);
  $dupe->execute();
  if ((method_exists($dupe,'get_result') && $dupe->get_result()->num_rows) || (!method_exists($dupe,'get_result') && $dupe->fetch())) {
    return; // already queued recently
  }

  // Queue the email
  queue_email($conn, [
    'template_name' => 'qr_email',   // your worker must know this template
    'to_name'       => $c['choice_name'],
    'to_address'    => $c['email'],
    'message_type'  => 'QR_EMAIL',
    'event_id'      => (int)$c['event_id'],
    'related_type'  => 'choice',
    'related_id'    => $choice_id,
    'data'          => [
      'choice_name'  => $c['choice_name'],
      'qr_url'       => $qrUrl,
      'qr_png_path'  => $qrPng,
    ],
  ]);
}
