<?php
require_once 'connection.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$old = $data['old_identifier'] ?? '';
$new = $data['new_identifier'] ?? '';

if (!$old || !$new) {
    echo json_encode(['status' => 'error', 'message' => 'Missing identifiers.']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => 'Voter identifier updated locally.',
    'old' => $old,
    'new' => $new
]);
?>
