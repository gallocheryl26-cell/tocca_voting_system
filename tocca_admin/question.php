<?php
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');          // don't dump HTML errors
ini_set('html_errors', '0');             // REALLY don't format errors as HTML
ob_start();                              // buffer anything accidental

set_error_handler(function($errno, $errstr, $errfile, $errline){
  http_response_code(500);
  $msg = "PHP $errno: $errstr at $errfile:$errline";
  while (ob_get_level()) ob_end_clean();
  echo json_encode(['status'=>'error','message'=>$msg]);
  exit;
});
set_exception_handler(function($ex){
  http_response_code(500);
  while (ob_get_level()) ob_end_clean();
  echo json_encode(['status'=>'error','message'=>$ex->getMessage()]);
  exit;
});

require_once __DIR__ . '/require_admin_api.php';
$helper = __DIR__ . '/audit_log.php';
if (!is_file($helper)) {
  throw new RuntimeException('audit_log.php not found next to question.php');
}
require_once $helper;

$data = json_decode(file_get_contents("php://input"), true) ?? [];
function get_active_event_id(mysqli $conn): ?int {
  $res = $conn->query("SELECT event_id FROM tbl_events WHERE is_active = 1 ORDER BY year DESC, event_id DESC LIMIT 1");
  if ($res && $res->num_rows) return (int)$res->fetch_assoc()['event_id'];
  $res = $conn->query("SELECT event_id FROM tbl_events ORDER BY year DESC, event_id DESC LIMIT 1");
  if ($res && $res->num_rows) return (int)$res->fetch_assoc()['event_id'];
  return null;
}
$event_id = get_active_event_id($conn);

function fetch_question(mysqli $conn, int $question_id): ?array {
  $st = $conn->prepare("SELECT question_id, question_name, category_id, choice_type FROM tbl_questions WHERE question_id=?");
  $st->bind_param("i", $question_id);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();
  return $row ?: null;
}

function question_event_id(mysqli $conn, int $category_id): ?int {
  $st = $conn->prepare("SELECT event_id FROM tbl_categories WHERE category_id=?");
  $st->bind_param("i", $category_id);
  $st->execute();
  $res = $st->get_result();
  $eid = $res ? ($res->fetch_assoc()['event_id'] ?? null) : null;
  $st->close();
  return $eid !== null ? (int)$eid : null;
}

if (($data['action'] ?? '') === 'loadCategories') {
  $eid = $event_id ?? 0;
  $stmt = $conn->prepare("
    SELECT category_id, category_name, event_id
    FROM tbl_categories 
    WHERE event_id = ? 
    ORDER BY category_name ASC
  ");
  $stmt->bind_param("i", $eid);
  $stmt->execute();
  $result = $stmt->get_result();
  $categories = [];
  while ($row = $result->fetch_assoc()) $categories[] = $row;
  echo json_encode(['status' => 'success', 'data' => $categories]);
  exit;
}

if (($data['action'] ?? '') === 'loadAll' || (!empty($data['loadOnly']) && $data['loadOnly'] == true)) {
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

  echo json_encode(['status' => 'success', 'data' => $questions]);
  exit;
}

if (($data['action'] ?? '') === 'delete' && !empty($data['ids']) && is_array($data['ids'])) {
  $ids = array_map('intval', $data['ids']);
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));

  $oldRows = [];
  $st = $conn->prepare("SELECT question_id, question_name, category_id, choice_type FROM tbl_questions WHERE question_id IN ($placeholders)");
  $st->bind_param($types, ...$ids);
  $st->execute();
  $res = $st->get_result();
  while ($row = $res->fetch_assoc()) {
    $qid = (int)$row['question_id'];
    $eid = question_event_id($conn, (int)$row['category_id']);
    if ($eid !== null) $row['event_id'] = $eid;
    $oldRows[$qid] = $row;
  }
  $st->close();

  $del = $conn->prepare("DELETE FROM tbl_questions WHERE question_id IN ($placeholders)");
  $del->bind_param($types, ...$ids);
  $ok = $del->execute();
  $del->close();

  if ($ok) {
    foreach ($ids as $qid) {
      $old = $oldRows[$qid] ?? ['question_id' => (int)$qid];
      audit_log($conn, 'questions', 'delete', 'question', (int)$qid, ['old' => $old]);
    }
    echo json_encode(['status' => 'success']);
  } else {
    throw new RuntimeException('Delete failed');
  }
  exit;
}

if (($data['action'] ?? '') === 'filterByCategory' && isset($data['category_id'])) {
  $category_id = (int)$data['category_id'];
  $stmt = $conn->prepare("SELECT q.*, c.category_name FROM tbl_questions q INNER JOIN tbl_categories c ON c.category_id = q.category_id WHERE q.category_id = ? ORDER BY q.question_id DESC");
  $stmt->bind_param("i", $category_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $questions = [];
  while ($row = $result->fetch_assoc()) $questions[] = $row;
  echo json_encode(['status' => 'success', 'data' => $questions]);
  exit;
}

if (($data['action'] ?? '') === 'getSingle' && isset($data['question_id'])) {
  $question_id = (int)$data['question_id'];
  $stmt = $conn->prepare("SELECT * FROM tbl_questions WHERE question_id = ?");
  $stmt->bind_param("i", $question_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $row = $result->fetch_assoc();
  echo json_encode(['status' => 'success', 'data' => $row]);
  exit;
}

$question_name = trim($data['question_name'] ?? '');
$question_id   = isset($data['question_id']) ? (int)$data['question_id'] : 0;
$category_id   = isset($data['category_id']) ? (int)$data['category_id'] : 0;
$choice_type   = isset($data['choice_type']) ? (int)$data['choice_type'] : 1;
$action        = $data['action'] ?? null;

if (!empty($question_name) && $category_id > 0) {
  $check_id = $question_id ?: 0;
  $stmt = $conn->prepare("SELECT question_id FROM tbl_questions WHERE question_name = ? AND question_id != ?");
  $stmt->bind_param("si", $question_name, $check_id);
  $stmt->execute();
  $dupCheck = $stmt->get_result();
  if ($dupCheck->num_rows > 0) {
    echo json_encode(['status' => 'duplicate']);
    exit;
  }

  if ($action === 'update' && $question_id) {
    $old = fetch_question($conn, $question_id);

    $stmt = $conn->prepare("UPDATE tbl_questions SET question_name = ?, category_id = ?, choice_type = ? WHERE question_id = ?");
    $stmt->bind_param("siii", $question_name, $category_id, $choice_type, $question_id);
    $ok = $stmt->execute();

    if ($ok) {
      $new = fetch_question($conn, $question_id);
      $eidNew = question_event_id($conn, (int)($new['category_id'] ?? $category_id));
      if ($eidNew !== null) $new['event_id'] = $eidNew;
      if ($old) {
        $eidOld = question_event_id($conn, (int)$old['category_id']);
        if ($eidOld !== null) $old['event_id'] = $eidOld;
      }
      $diff = audit_diff_assoc($old ?? [], $new ?? []);
      audit_log($conn, 'questions', 'update', 'question', $question_id, ['old'=>$old, 'new'=>$new, 'diff'=>$diff]);
      echo json_encode(['status' => 'success']);
    } else {
      throw new RuntimeException('Update failed');
    }
    exit;

  } elseif ($action === 'create' || !$question_id) {
    $stmt = $conn->prepare("INSERT INTO tbl_questions (question_name, category_id, choice_type) VALUES (?, ?, ?)");
    $stmt->bind_param("sii", $question_name, $category_id, $choice_type);
    $ok = $stmt->execute();
    if ($ok) {
      $newId = (int)$stmt->insert_id;
      $new   = fetch_question($conn, $newId);
      $eid   = question_event_id($conn, (int)($new['category_id'] ?? $category_id));
      if ($eid !== null) $new['event_id'] = $eid;

      audit_log($conn, 'questions', 'create', 'question', $newId, ['new'=>$new]);
      echo json_encode(['status' => 'success']);
    } else {
      throw new RuntimeException('Insert failed');
    }
    exit;
  } else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request for save.']);
    exit;
  }
}

echo json_encode(['status' => 'error', 'message' => 'Missing question or category']);
