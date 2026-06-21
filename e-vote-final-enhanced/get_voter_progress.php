<?php
include 'connection.php';
header('Content-Type: application/json');

$mobile = $_GET['mobile'] ?? '';
$eventId = $_GET['event_id'] ?? '';

if (!$mobile || !$eventId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit;
}

$mobile = preg_replace('/\D/', '', $mobile);

// Lookup voter ID
$stmt = $conn->prepare("SELECT voters_id FROM tbl_voters WHERE mobile_number = ?");
$stmt->bind_param('s', $mobile);
$stmt->execute();
$result = $stmt->get_result();
if (!($row = $result->fetch_assoc())) {
    echo json_encode(['status' => 'error', 'message' => 'Voter not found']);
    exit;
}
$voterId = (int)$row['voters_id'];
$stmt->close();

// Total questions for event
$total = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tbl_questions q INNER JOIN tbl_categories c ON q.category_id = c.category_id WHERE c.event_id = ? AND c.is_archived = 0");
$stmt->bind_param('i', $eventId);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $total = (int)$row['total'];
}
$stmt->close();

// Answered questions (finalized) for event.
// Do NOT depend on event_id columns in tbl_poll_choice/tbl_poll_freetext; infer event via question->category.
$answered = 0;
$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT qid) AS cnt FROM (
        SELECT q.question_id AS qid
        FROM tbl_poll_choice pc
        INNER JOIN tbl_questions q ON pc.question_id = q.question_id
        INNER JOIN tbl_categories c ON q.category_id = c.category_id
        WHERE pc.voters_id = ? AND c.event_id = ? AND c.status = 1
        UNION
        SELECT q.question_id AS qid
        FROM tbl_poll_freetext pf
        INNER JOIN tbl_questions q ON pf.question_id = q.question_id
        INNER JOIN tbl_categories c ON q.category_id = c.category_id
        WHERE pf.voters_id = ? AND c.event_id = ? AND c.status = 1
    ) AS answered_q"
);
$stmt->bind_param('iiii', $voterId, $eventId, $voterId, $eventId);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $answered = (int)($row['cnt'] ?? 0);
}
$stmt->close();

$unanswered = max($total - $answered, 0);
$percent = $total ? round(($answered / $total) * 100) : 0;

echo json_encode([
    'answered' => $answered,
    'unanswered' => $unanswered,
    'percent' => $percent
]);
?>