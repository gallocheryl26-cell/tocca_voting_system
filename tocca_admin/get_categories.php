<?php
require_once __DIR__ . '/require_admin_api.php';

// Get active event_id
$eventResult = $conn->query("SELECT event_id FROM tbl_events WHERE is_active = 1 LIMIT 1");

if ($eventResult && $eventResult->num_rows > 0) {
    $row = $eventResult->fetch_assoc();
    $active_event_id = (int)$row['event_id'];

    $stmt = $conn->prepare("SELECT * FROM tbl_categories WHERE event_id = ? ORDER BY category_name ASC");
    $stmt->bind_param("i", $active_event_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $categories = [];

    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }

    echo json_encode(['status' => 'success', 'data' => $categories]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No active event found']);
}
?>
