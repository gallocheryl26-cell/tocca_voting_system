<?php
// voter/save_feedback.php (drop-in)
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '../tocca_admin/db_connection.php';

function fail(string $msg): never { echo $msg; exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('invalid_request');

// Inputs
$feedback   = trim((string)($_POST['feedback'] ?? ''));    // no length limit
$voters_id  = isset($_POST['voters_id']) && ctype_digit((string)$_POST['voters_id']) ? (int)$_POST['voters_id'] : 0;
$event_id   = isset($_POST['event_id'])  && ctype_digit((string)$_POST['event_id'])  ? (int)$_POST['event_id']  : 0;

// NEW: anonymity flag (default anonymous)
$is_anonymous = ($_POST['is_anonymous'] ?? '1');
$is_anonymous = ($is_anonymous === '0' || $is_anonymous === 0) ? 0 : 1;

if ($feedback === '') fail('empty');
if ($voters_id <= 0 || $event_id <= 0) fail('error: Voter ID and Event ID must be identified.');

try {
    // Insert voter feedback
    $sql = "
        INSERT INTO tbl_feedback
          (feedback_type, voters_id, nomination_id, event_id, feedback, is_anonymous, submitted_at)
        VALUES
          ('voter', ?, NULL, ?, ?, ?, NOW())
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) fail('error: '.$conn->error);

    // types: i (voters_id), i (event_id), s (feedback), i (is_anonymous)
    $stmt->bind_param('iisi', $voters_id, $event_id, $feedback, $is_anonymous);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        fail('insert_failed: '.$err);
    }
    $stmt->close();

    echo 'success';
} catch (Throwable $e) {
    fail('error: '.$e->getMessage());
}
