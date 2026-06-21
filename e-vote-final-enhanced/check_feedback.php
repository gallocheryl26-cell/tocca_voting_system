<?php
require_once '../tocca_admin/db_connection.php';

$voters_id = intval($_GET['voters_id'] ?? 0);
$event_id = intval($_GET['event_id'] ?? 0);

$response = ['hasFeedback' => false];

if ($voters_id > 0 && $event_id > 0) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_feedback WHERE feedback_type = 'voter' AND voters_id = ? AND event_id = ?");
    $stmt->bind_param("ii", $voters_id, $event_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count > 0) {
        $response['hasFeedback'] = true;
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>
