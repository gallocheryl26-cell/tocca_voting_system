<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
require_once '../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/admin_schema.php';

$eventId = (int) ($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    $event = voter_flow_active_event($conn);
    $eventId = (int) ($event['event_id'] ?? 0);
}

if ($eventId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No active event.',
        'questions' => [],
    ]);
    exit;
}

$catSql = admin_active_category_sql($conn, 'cat');
$qSql = admin_active_question_sql($conn, 'q');
$votableSql = voter_flow_votable_question_sql($conn, 'q');

$sql = "
    SELECT
        q.question_id,
        q.question_name,
        q.category_id,
        q.choice_type,
        cat.category_name
    FROM tbl_questions q
    INNER JOIN tbl_categories cat ON q.category_id = cat.category_id
    WHERE cat.event_id = ? AND {$catSql} AND {$qSql} AND {$votableSql}
    ORDER BY cat.category_name ASC, q.question_id ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Query failed.',
        'questions' => [],
    ]);
    exit;
}

$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();

$questions = [];
while ($row = $result->fetch_assoc()) {
    $questions[] = [
        'question_id' => (int) $row['question_id'],
        'question_name' => $row['question_name'],
        'category_id' => (int) $row['category_id'],
        'choice_type' => (int) $row['choice_type'],
        'category_name' => $row['category_name'],
    ];
}
$stmt->close();

echo json_encode([
    'status' => 'success',
    'event_id' => $eventId,
    'questions' => $questions,
]);
