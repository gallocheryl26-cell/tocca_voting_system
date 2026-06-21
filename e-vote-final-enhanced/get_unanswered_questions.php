<?php
include '../tocca_admin/db_connection.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$voter_id = $data['voter_id'] ?? null;
$event_id = $data['event_id'] ?? null;

if (!$voter_id || !$event_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing voter_id or event_id']);
    exit;
}

// Get all question IDs for the current event
$questionsQuery = $conn->prepare("
    SELECT q.question_id, q.question_name, q.category_id
    FROM tbl_questions q
    INNER JOIN tbl_categories c ON q.category_id = c.category_id
    WHERE c.event_id = ? AND c.is_archived = 0 AND c.status = 1
");
$questionsQuery->bind_param("i", $event_id);
$questionsQuery->execute();
$questionsResult = $questionsQuery->get_result();

$unanswered = [];
while ($row = $questionsResult->fetch_assoc()) {
    $question_id = $row['question_id'];

    // Check if this question has been finalized (either choice or freetext)
    $finalized = false;

    // Check poll_choice
    $stmt1 = $conn->prepare("SELECT 1 FROM tbl_poll_choice WHERE voters_id = ? AND question_id = ? AND event_id = ?");
    $stmt1->bind_param("iii", $voter_id, $question_id, $event_id);
    $stmt1->execute();
    $stmt1->store_result();
    if ($stmt1->num_rows > 0) {
        $finalized = true;
    }
    $stmt1->close();

    // Check poll_freetext only if not finalized
    if (!$finalized) {
        $stmt2 = $conn->prepare("SELECT 1 FROM tbl_poll_freetext WHERE voters_id = ? AND question_id = ? AND event_id = ?");
        $stmt2->bind_param("iii", $voter_id, $question_id, $event_id);
        $stmt2->execute();
        $stmt2->store_result();
        if ($stmt2->num_rows > 0) {
            $finalized = true;
        }
        $stmt2->close();
    }

    if (!$finalized) {
        $unanswered[] = $row;
    }
}

echo json_encode(['status' => 'success', 'unanswered' => $unanswered]);
exit;
