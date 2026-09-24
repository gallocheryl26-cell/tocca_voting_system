<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';

$voterId = voter_require_authenticated();
voter_flow_json_ballot_denied($conn, $voterId);

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$eventId = isset($data['event_id']) ? (int)$data['event_id'] : 0;
$categoryId = isset($data['category_id']) ? (int)$data['category_id'] : null;

if ($eventId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing event ID']);
    exit;
}

if (isset($data['voter_id'])) {
    $requested = voter_resolve_id($conn, $data['voter_id']) ?? (is_numeric($data['voter_id']) ? (int)$data['voter_id'] : 0);
    if ($requested > 0) {
        voter_assert_matches_session($requested);
    }
}

$finalizedIds = [];

$choiceSql = 'SELECT pc.question_id FROM tbl_poll_choice pc ' .
    'JOIN tbl_questions q ON pc.question_id = q.question_id ' .
    'JOIN tbl_categories c ON q.category_id = c.category_id ' .
    'WHERE pc.voters_id = ? AND c.event_id = ? AND c.status = 1';
if ($categoryId) {
    $choiceSql .= ' AND c.category_id = ?';
}
$query1 = $conn->prepare($choiceSql);
if ($categoryId) {
    $query1->bind_param('iii', $voterId, $eventId, $categoryId);
} else {
    $query1->bind_param('ii', $voterId, $eventId);
}
$query1->execute();
$result1 = $query1->get_result();
while ($row = $result1->fetch_assoc()) {
    $qid = (int) ($row['question_id'] ?? 0);
    if ($qid > 0) {
        $finalizedIds[$qid] = $qid;
    }
}
$query1->close();

$freetextSql = 'SELECT pf.question_id FROM tbl_poll_freetext pf ' .
    'JOIN tbl_questions q ON pf.question_id = q.question_id ' .
    'JOIN tbl_categories c ON q.category_id = c.category_id ' .
    'WHERE pf.voters_id = ? AND c.event_id = ? AND c.status = 1';
if ($categoryId) {
    $freetextSql .= ' AND c.category_id = ?';
}
$query2 = $conn->prepare($freetextSql);
if ($categoryId) {
    $query2->bind_param('iii', $voterId, $eventId, $categoryId);
} else {
    $query2->bind_param('ii', $voterId, $eventId);
}
$query2->execute();
$result2 = $query2->get_result();
while ($row = $result2->fetch_assoc()) {
    $qid = (int) ($row['question_id'] ?? 0);
    if ($qid > 0) {
        $finalizedIds[$qid] = $qid;
    }
}
$query2->close();

$ids = array_values($finalizedIds);
echo json_encode(['status' => 'success', 'answers' => $ids, 'finalized' => $ids]);
