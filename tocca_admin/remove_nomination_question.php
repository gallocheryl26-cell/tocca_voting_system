<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/award_removal_reasons.php';
require_once __DIR__ . '/audit_log.php';

// Allow only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
  exit;
}

// Read JSON body or fall back to form data
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }

// CSRF
$csrf = $data['csrf'] ?? '';
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
  http_response_code(403);
  echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
  exit;
}

$nomination_id = isset($data['nomination_id']) ? (int)$data['nomination_id'] : 0;
$question_id   = isset($data['question_id'])   ? (int)$data['question_id']   : 0;
$reason        = award_removal_reason_normalize(isset($data['reason']) ? (string)$data['reason'] : null);

if ($nomination_id <= 0 || $question_id <= 0) {
  http_response_code(422);
  echo json_encode(['status' => 'error', 'message' => 'Missing or invalid nomination_id/question_id']);
  exit;
}

if ($reason === null) {
  http_response_code(422);
  echo json_encode(['status' => 'error', 'message' => 'Select a reason for removing this award.']);
  exit;
}

$conn->set_charset('utf8mb4');
award_removal_schema_ensure($conn);

$conn->begin_transaction();
try {
  // Ensure the pair exists
  $chk = $conn->prepare("SELECT 1 FROM tbl_nomination_questions WHERE nomination_id=? AND question_id=? LIMIT 1");
  if (!$chk) { throw new Exception('Prepare failed: '.$conn->error); }
  $chk->bind_param('ii', $nomination_id, $question_id);
  $chk->execute();
  $chk->store_result();
  if ($chk->num_rows === 0) {
    throw new Exception('Pair not found or already removed.');
  }
  $chk->close();

  // Hard delete (switch to soft delete if you have deleted_at)
  $del = $conn->prepare("DELETE FROM tbl_nomination_questions WHERE nomination_id=? AND question_id=? LIMIT 1");
  if (!$del) { throw new Exception('Prepare failed: '.$conn->error); }
  $del->bind_param('ii', $nomination_id, $question_id);
  if (!$del->execute()) { throw new Exception('Delete failed: '.$del->error); }
  $aff = $del->affected_rows;
  $del->close();

  $choiceId = award_choice_id_for_nomination($conn, $nomination_id);
  if ($choiceId > 0) {
    award_unlink_choice_question($conn, $choiceId, $question_id);
  }

  // Audit (no admin_id)
  $hasReasonCol = award_removal_schema_ensure($conn);
  if ($hasReasonCol) {
    $aud = $conn->prepare("
      INSERT INTO tbl_nomination_question_audit (nomination_id, question_id, action, reason, changed_at)
      VALUES (?, ?, 'REMOVED', ?, NOW())
    ");
    if ($aud) {
      $aud->bind_param('iis', $nomination_id, $question_id, $reason);
      $aud->execute();
      $aud->close();
    }
  } else {
    $aud = $conn->prepare("
      INSERT INTO tbl_nomination_question_audit (nomination_id, question_id, action, changed_at)
      VALUES (?, ?, 'REMOVED', NOW())
    ");
    if ($aud) {
      $aud->bind_param('ii', $nomination_id, $question_id);
      $aud->execute();
      $aud->close();
    }
  }

  $conn->commit();
  $qName = '';
  $qn = $conn->prepare('SELECT question_name FROM tbl_questions WHERE question_id = ? LIMIT 1');
  if ($qn) {
    $qn->bind_param('i', $question_id);
    $qn->execute();
    $qRow = $qn->get_result()->fetch_assoc();
    $qn->close();
    $qName = (string) ($qRow['question_name'] ?? '');
  }
  audit_log($conn, 'registrations', 'remove_award', 'nomination', $nomination_id, [
    'question_id' => $question_id,
    'question_name' => $qName,
    'reason' => $reason,
    'choice_id' => $choiceId,
  ]);
  echo json_encode(['status' => 'success', 'removed' => (int)$aff]);
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
