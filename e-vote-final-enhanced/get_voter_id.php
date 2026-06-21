<?php
include 'connection.php';
header('Content-Type: application/json');

$mobile = $_GET['mobile'] ?? '';
if (!$mobile) {
  echo json_encode(['status' => 'error', 'message' => 'Missing mobile']);
  exit;
}

$stmt = $conn->prepare("SELECT voters_id FROM tbl_voters WHERE mobile_number = ?");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
  echo json_encode(['status' => 'success', 'voter_id' => $row['voters_id']]);
} else {
  echo json_encode(['status' => 'error', 'message' => 'Voter not found']);
}
?>
