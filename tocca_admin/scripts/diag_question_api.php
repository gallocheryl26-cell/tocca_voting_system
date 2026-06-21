<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'POST';
require_once dirname(__DIR__) . '/db_connection.php';

$data = ['action' => 'loadAll'];
// Minimal replay of question.php loadAll
function get_active_event_id(mysqli $conn): ?int {
  $res = $conn->query("SELECT event_id FROM tbl_events WHERE is_active = 1 ORDER BY year DESC, event_id DESC LIMIT 1");
  if ($res && $res->num_rows) return (int)$res->fetch_assoc()['event_id'];
  return null;
}
$event_id = get_active_event_id($conn);
$stmt = $conn->prepare("
    SELECT q.*, c.category_name
    FROM tbl_questions q
    INNER JOIN tbl_categories c ON c.category_id = q.category_id
    WHERE c.event_id = ?
    ORDER BY q.question_id DESC
  ");
$eid = $event_id ?? 0;
$stmt->bind_param("i", $eid);
$stmt->execute();
$result = $stmt->get_result();
$questions = [];
while ($row = $result->fetch_assoc()) $questions[] = $row;
echo json_encode(['status' => 'success', 'data' => $questions, 'event_id' => $eid], JSON_PRETTY_PRINT);
