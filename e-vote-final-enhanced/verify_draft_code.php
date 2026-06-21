<?php
include 'connection.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

$voter_id = $data['voter_id'] ?? '';
$draft_code = $data['draft_code'] ?? '';

if (!$voter_id || !$draft_code) {
    echo json_encode(['status' => 'error', 'message' => 'Missing voter ID or draft code.']);
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE tbl_voters SET draft_code = ? WHERE voters_id = ?");
    $stmt->bind_param("si", $draft_code, $voter_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Draft code saved.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Voter not found or draft code unchanged.']);
    }

    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
