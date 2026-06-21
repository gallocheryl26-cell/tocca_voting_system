<?php
require_once 'connection.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$mobile = $data['mobile'] ?? '';
$pin = $data['draft_pin'] ?? '';

if (!preg_match('/^09\d{9}$/', $mobile) || !preg_match('/^\d{4}$/', $pin)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid mobile number or PIN format.']);
    exit;
}

$hashedPin = password_hash($pin, PASSWORD_DEFAULT);

try {
    $stmt = $conn->prepare("
        INSERT INTO tbl_draft_auth (mobile, draft_pin_hash)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE draft_pin_hash = VALUES(draft_pin_hash)
    ");
    $stmt->bind_param("ss", $mobile, $hashedPin);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['status' => 'success', 'message' => 'PIN saved successfully.']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to save PIN.', 'error' => $e->getMessage()]);
}
