<?php
declare(strict_types=1);

include '../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/admin_schema.php';

header('Content-Type: application/json; charset=UTF-8');

$voter_id = voter_require_authenticated();
voter_flow_json_ballot_denied($conn, $voter_id);

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$event_id = isset($data['event_id']) ? (int) $data['event_id'] : 0;

if ($event_id <= 0) {
    $event = voter_flow_active_event($conn);
    $event_id = (int) ($event['event_id'] ?? 0);
}

if ($event_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing event_id']);
    exit;
}

if (isset($data['voter_id'])) {
    $requested = voter_resolve_id($conn, $data['voter_id']) ?? (is_numeric($data['voter_id']) ? (int) $data['voter_id'] : 0);
    if ($requested > 0) {
        voter_assert_matches_session($requested);
    }
}

$catSql = admin_active_category_sql($conn, 'c');
$qSql = admin_active_question_sql($conn, 'q');
$votableSql = voter_flow_votable_question_sql($conn, 'q');

$questionsQuery = $conn->prepare("
    SELECT q.question_id, q.question_name, q.category_id
    FROM tbl_questions q
    INNER JOIN tbl_categories c ON q.category_id = c.category_id
    WHERE c.event_id = ? AND {$catSql} AND {$qSql} AND {$votableSql}
");
$questionsQuery->bind_param('i', $event_id);
$questionsQuery->execute();
$questionsResult = $questionsQuery->get_result();

$unanswered = [];
while ($row = $questionsResult->fetch_assoc()) {
    $question_id = (int) $row['question_id'];
    $finalized = false;

    $stmt1 = $conn->prepare('SELECT 1 FROM tbl_poll_choice WHERE voters_id = ? AND question_id = ? LIMIT 1');
    $stmt1->bind_param('ii', $voter_id, $question_id);
    $stmt1->execute();
    $stmt1->store_result();
    if ($stmt1->num_rows > 0) {
        $finalized = true;
    }
    $stmt1->close();

    if (!$finalized) {
        $stmt2 = $conn->prepare('SELECT 1 FROM tbl_poll_freetext WHERE voters_id = ? AND question_id = ? LIMIT 1');
        $stmt2->bind_param('ii', $voter_id, $question_id);
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
$questionsQuery->close();

echo json_encode(['status' => 'success', 'unanswered' => $unanswered]);
exit;
