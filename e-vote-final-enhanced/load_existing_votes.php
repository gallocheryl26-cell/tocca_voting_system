<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
require_once 'connection.php';
require_once 'voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';

$voter_id = voter_require_authenticated();
voter_flow_json_ballot_denied($conn, $voter_id);
$event_id = isset($_REQUEST['event_id']) ? (int)$_REQUEST['event_id'] : 0;
$category_id = isset($_REQUEST['category_id']) ? (int)$_REQUEST['category_id'] : 0;
if ($event_id <= 0) {
  echo json_encode(['status' => 'error', 'message' => 'Missing event_id']);
  exit;
}
$answers = [];
function load_choice_answers($conn, $sql, $types, ...$params) {
  global $answers;
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    if (!isset($answers[$row['question_id']])) {
      $answers[$row['question_id']] = [
        'question_id' => $row['question_id'],
        'choice_id' => $row['choice_id'],
        'choice_text' => $row['choice_text'],
        'manual_input' => ''
      ];
    }
  }
}

function load_freetext_answers($conn, $sql, $types, ...$params) {
  global $answers;
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    if (isset($answers[$row['question_id']])) {
      $answers[$row['question_id']]['manual_input'] = $row['manual_input'];
    } else {
      $answers[$row['question_id']] = [
        'question_id' => $row['question_id'],
        'choice_id' => null,
        'choice_text' => '',
        'manual_input' => $row['manual_input']
      ];
    }
  }
}

$baseChoiceSql = "
  SELECT pc.question_id, pc.choice_id, ch.choice_name AS choice_text
  FROM tbl_poll_choice pc
  JOIN tbl_choices ch ON pc.choice_id = ch.choice_id
  JOIN tbl_questions q ON pc.question_id = q.question_id
  JOIN tbl_categories c ON q.category_id = c.category_id
  WHERE pc.voters_id = ? AND c.event_id = ? AND c.status = 1";
if ($category_id) {
  $baseChoiceSql .= " AND c.category_id = ?";
}
load_choice_answers(
  $conn,
  $baseChoiceSql,
  $category_id ? "iii" : "ii",
  $voter_id,
  $event_id,
  ...($category_id ? [$category_id] : [])
);

$baseFreetextSql = "
  SELECT pf.question_id, pf.freetext AS manual_input
  FROM tbl_poll_freetext pf
  JOIN tbl_questions q ON pf.question_id = q.question_id
  JOIN tbl_categories c ON q.category_id = c.category_id
  WHERE pf.voters_id = ? AND c.event_id = ? AND c.status = 1";
if ($category_id) {
  $baseFreetextSql .= " AND c.category_id = ?";
}
load_freetext_answers(
  $conn,
  $baseFreetextSql,
  $category_id ? "iii" : "ii",
  $voter_id,
  $event_id,
  ...($category_id ? [$category_id] : [])
);

$draftChoiceSql = "
  SELECT dc.question_id, dc.choice_id, ch.choice_name AS choice_text
  FROM tbl_draft_choice dc
  JOIN tbl_choices ch ON dc.choice_id = ch.choice_id
  JOIN tbl_questions q ON dc.question_id = q.question_id
  JOIN tbl_categories c ON q.category_id = c.category_id
  WHERE dc.voters_id = ? AND c.event_id = ? AND c.status = 1";
if ($category_id) {
  $draftChoiceSql .= " AND c.category_id = ?";
}
load_choice_answers(
  $conn,
  $draftChoiceSql,
  $category_id ? "iii" : "ii",
  $voter_id,
  $event_id,
  ...($category_id ? [$category_id] : [])
);

$draftFreetextSql = "
  SELECT df.question_id, df.freetext AS manual_input
  FROM tbl_draft_freetext df
  JOIN tbl_questions q ON df.question_id = q.question_id
  JOIN tbl_categories c ON q.category_id = c.category_id
  WHERE df.voters_id = ? AND c.event_id = ? AND c.status = 1";
if ($category_id) {
  $draftFreetextSql .= " AND c.category_id = ?";
}
load_freetext_answers(
  $conn,
  $draftFreetextSql,
  $category_id ? "iii" : "ii",
  $voter_id,
  $event_id,
  ...($category_id ? [$category_id] : [])
);

$hasMediaTable = false;
if ($res = $conn->query("SHOW TABLES LIKE 'tbl_choice_media'")) {
  $hasMediaTable = $res->num_rows > 0;
  $res->close();
}
if ($hasMediaTable) {
  $choiceIds = [];
  foreach ($answers as $ans) {
    if (!empty($ans['choice_id'])) {
      $choiceIds[] = (int) $ans['choice_id'];
    }
  }
  $choiceIds = array_values(array_unique($choiceIds));
  $withMedia = [];
  if ($choiceIds !== []) {
    $placeholders = implode(',', array_fill(0, count($choiceIds), '?'));
    $types = str_repeat('i', count($choiceIds));
    $sql = "SELECT DISTINCT choice_id FROM tbl_choice_media WHERE choice_id IN ($placeholders)";
    if ($mStmt = $conn->prepare($sql)) {
      $mStmt->bind_param($types, ...$choiceIds);
      $mStmt->execute();
      $mRes = $mStmt->get_result();
      while ($mRow = $mRes->fetch_assoc()) {
        $withMedia[(int) $mRow['choice_id']] = true;
      }
      $mStmt->close();
    }
  }
  foreach ($answers as &$ans) {
    $cid = (int) ($ans['choice_id'] ?? 0);
    $ans['has_media'] = $cid > 0 && isset($withMedia[$cid]);
  }
  unset($ans);
} else {
  foreach ($answers as &$ans) {
    $ans['has_media'] = false;
  }
  unset($ans);
}

echo json_encode([
  'status' => 'success',
  'answers' => array_values($answers)
]);
?>
