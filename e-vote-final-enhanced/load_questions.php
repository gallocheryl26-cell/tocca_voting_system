<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');
require_once '../tocca_admin/db_connection.php';
require_once __DIR__ . '/lib/voter_flow.php';

if (!isset($_GET['category_id']) || !is_numeric($_GET['category_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid category_id']);
    exit;
}

$category_id = (int)$_GET['category_id'];
$votableSql = voter_flow_votable_question_sql($conn, 'q');

$stmt = $conn->prepare("SELECT q.* FROM tbl_questions q WHERE q.category_id = ? AND {$votableSql}");
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare statement']);
    exit;
}
$stmt->bind_param("i", $category_id);
if (!$stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to execute query']);
    exit;
}
$result = $stmt->get_result();

$questions = [];
while ($row = $result->fetch_assoc()) {
    $questions[] = [
        'question_id' => (int)$row['question_id'],
        'question_name' => $row['question_name'],
        'category_id' => (int)$row['category_id'],
        'choice_type' => (int)$row['choice_type']
    ];
}

echo json_encode(['status' => 'success', 'questions' => $questions]);
exit;
