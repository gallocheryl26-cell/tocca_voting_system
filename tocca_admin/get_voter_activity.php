<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/admin_active_event.php';

if (!isset($_GET['voter_id']) || !ctype_digit((string) $_GET['voter_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing voter ID']);
    exit;
}

$voterId = (int) $_GET['voter_id'];
$eventId = isset($_GET['event_id']) && ctype_digit((string) $_GET['event_id'])
    ? (int) $_GET['event_id']
    : admin_get_active_event_id($conn);

if ($eventId === null || $eventId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'No active event.']);
    exit;
}

$activities = [];

// Fetch choice-based votes (active event only)
$stmt1 = $conn->prepare("
    SELECT pc.vote_at AS date, q.question_name, c.choice_name AS answer, 'choice' AS answer_type
    FROM tbl_poll_choice pc
    INNER JOIN tbl_questions q ON pc.question_id = q.question_id
    INNER JOIN tbl_categories cat ON q.category_id = cat.category_id
    INNER JOIN tbl_choices c ON pc.choice_id = c.choice_id
    WHERE pc.voters_id = ? AND cat.event_id = ?
    ORDER BY pc.vote_at DESC
");
$stmt1->bind_param('ii', $voterId, $eventId);
$stmt1->execute();
$result1 = $stmt1->get_result();

while ($row = $result1->fetch_assoc()) {
    $activities[] = $row;
}

// Fetch freetext votes (active event only)
$stmt2 = $conn->prepare("
    SELECT pf.vote_at AS date, q.question_name, pf.freetext AS answer, 'manual' AS answer_type
    FROM tbl_poll_freetext pf
    INNER JOIN tbl_questions q ON pf.question_id = q.question_id
    INNER JOIN tbl_categories cat ON q.category_id = cat.category_id
    WHERE pf.voters_id = ? AND cat.event_id = ?
    ORDER BY pf.vote_at DESC
");
$stmt2->bind_param('ii', $voterId, $eventId);
$stmt2->execute();
$result2 = $stmt2->get_result();

while ($row = $result2->fetch_assoc()) {
    $activities[] = $row;
}

// Sort by date descending
usort($activities, function ($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

echo json_encode(['status' => 'success', 'data' => $activities]);
exit;
