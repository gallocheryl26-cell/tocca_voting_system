<?php
header('Content-Type: application/json');
include 'connection.php';
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}
$query = "SELECT * FROM tbl_categories WHERE status = 1 AND status = 1 ORDER BY category_name ASC";
$result = $conn->query($query);
if (!$result) {
    echo json_encode(['status' => 'error', 'message' => 'Query failed: ' . $conn->error]);
    exit;
}
$categories = [];
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}
echo json_encode(['status' => 'success', 'categories' => $categories]);
?>
    