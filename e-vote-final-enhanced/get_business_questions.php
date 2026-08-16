<?php
require_once '../tocca_admin/db_connection.php';
header('Content-Type: application/json');
require_once '../tocca_admin/includes/ballot_status.php';

$choice_id = $_GET['choice_id'] ?? null;

if (!$choice_id) {
  echo json_encode(['status' => 'error', 'message' => 'Missing choice_id']);
  exit;
}

$choice_id = (int) $choice_id;
if (ballot_status_flag($conn, $choice_id) === false) {
  echo json_encode(['status' => 'error', 'message' => 'This business is not yet on the public ballot.']);
  exit;
}

try {
  $stmt = $conn->prepare("
    SELECT 
      c.category_id,
      c.category_name,
      q.question_id,
      q.question_name
    FROM tbl_question_choices qc
    JOIN tbl_questions q ON qc.question_id = q.question_id
    JOIN tbl_categories c ON q.category_id = c.category_id
    WHERE qc.choice_id = ?
      AND c.status = 1
    ORDER BY c.category_name, q.question_name
  ");
  $stmt->bind_param("i", $choice_id);
  $stmt->execute();
  $result = $stmt->get_result();

  $categories = [];

  while ($row = $result->fetch_assoc()) {
    $catId = $row['category_id'];
    if (!isset($categories[$catId])) {
      $categories[$catId] = [
        'category_id' => $catId,
        'category_name' => $row['category_name'],
        'questions' => []
      ];
    }
    $categories[$catId]['questions'][] = [
      'question_id' => $row['question_id'],
      'question_name' => $row['question_name']
    ];
  }

  echo json_encode(['status' => 'success', 'categories' => array_values($categories)]);
} catch (Exception $e) {
  echo json_encode(['status' => 'error', 'message' => 'DB error']);
}
?>
