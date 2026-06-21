<?php
header('Content-Type: application/json');
require_once '../tocca_admin/db_connection.php';

$sql = "
    SELECT 
        q.question_id,
        q.question_name,
        q.category_id,
        q.choice_type,
        cat.category_name
    FROM tbl_questions q
    LEFT JOIN tbl_categories cat ON q.category_id = cat.category_id
    ORDER BY q.question_id ASC
";

$result = $conn->query($sql);

$questions = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $questions[] = [
            'question_id' => (int)$row['question_id'],
            'question_name' => $row['question_name'],
            'category_id' => (int)$row['category_id'],
            'choice_type' => (int)$row['choice_type'],
            'category_name' => $row['category_name']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'questions' => $questions
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Query failed: ' . $conn->error
    ]);
}
?>
