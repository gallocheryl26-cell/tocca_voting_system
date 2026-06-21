<?php
require_once '../tocca_admin/db_connection.php';
header('Content-Type: text/plain; charset=UTF-8');
session_start();

function fail($m){ echo $m; exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('invalid_request');

$feedback = trim($_POST['feedback'] ?? '');
if ($feedback === '') fail('missing_feedback');  

$nomIdIn = trim((string)($_POST['nomination_id'] ?? ''));
$refIn   = trim((string)($_POST['reference'] ?? ''));
if ($refIn === '' && !empty($_SESSION['last_nom_ref'])) $refIn = trim((string)$_SESSION['last_nom_ref']);
if ($refIn === '' && isset($_GET['ref']))               $refIn = trim((string)$_GET['ref']);

$isAnon = $_POST['is_anonymous'] ?? '1';
$isAnon = ($isAnon === '0' || $isAnon === 0) ? 0 : 1;

error_log("[save_feedback_nomination] POST nomination_id={$nomIdIn} ref={$refIn} is_anonymous={$isAnon}");

$nomination_id = null;
$event_id      = null;

if ($nomIdIn !== '' && ctype_digit($nomIdIn)) {
  $nid  = (int)$nomIdIn;
  $stmt = $conn->prepare("SELECT nomination_id, event_id FROM tbl_nominations WHERE nomination_id = ? LIMIT 1");
  if (!$stmt) fail('SQL Error (prepare find by id): ' . $conn->error);
  $stmt->bind_param('i', $nid);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) {
    $nomination_id = (int)$row['nomination_id'];
    $event_id      = (int)$row['event_id'];
  }
  $stmt->close();
}

if (!$event_id && $refIn !== '') {
  foreach (['reference_no','reference','ref_no','tracking_code'] as $col) {
    $sql  = "SELECT nomination_id, event_id FROM tbl_nominations WHERE {$col} = ? LIMIT 1";
    $stmt = @ $conn->prepare($sql);
    if (!$stmt) continue;
    $stmt->bind_param('s', $refIn);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      if ($row = $res->fetch_assoc()) {
        $nomination_id = (int)$row['nomination_id'];
        $event_id      = (int)$row['event_id'];
        $stmt->close();
        break;
      }
    }
    $stmt->close();
  }
}

if (!$event_id) {
  $r = $conn->query("SELECT event_id FROM tbl_events WHERE is_active=1 ORDER BY year DESC, event_id DESC LIMIT 1");
  if ($r && $r->num_rows) $event_id = (int)$r->fetch_assoc()['event_id'];
  if (!$event_id) {
    $r2 = $conn->query("SELECT event_id FROM tbl_events ORDER BY year DESC, event_id DESC LIMIT 1");
    if ($r2 && $r2->num_rows) $event_id = (int)$r2->fetch_assoc()['event_id'];
  }
  if (!$event_id) fail('no_event_available');
}

$nidParam = $nomination_id ?: 0;
$stmt = $conn->prepare("
  INSERT INTO tbl_feedback
    (feedback_type, voters_id, nomination_id, event_id, feedback, is_anonymous, submitted_at)
  VALUES
    ('nominee', NULL, NULLIF(?,0), ?, ?, ?, NOW())
");
if (!$stmt) fail('SQL Error (prepare insert): ' . $conn->error);
$stmt->bind_param('iisi', $nidParam, $event_id, $feedback, $isAnon);

if (!$stmt->execute()) {
  $e = $stmt->error; $stmt->close(); fail('db_error: ' . $e);
}
$stmt->close();

echo 'success';
