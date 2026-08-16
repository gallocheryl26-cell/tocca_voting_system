<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/results_formula.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    if (($data['action'] ?? '') !== 'save_member') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
        exit;
    }
    $questionId = (int) ($data['question_id'] ?? 0);
    $choiceId = (int) ($data['choice_id'] ?? 0);
    $memberKey = (string) ($data['member_key'] ?? '');
    $scoreRaw = $data['score'] ?? null;
    $score = null;
    if ($scoreRaw !== null && $scoreRaw !== '') {
        $score = (float) $scoreRaw;
    }
    if (!twg_choice_linked_to_question($conn, $questionId, $choiceId)) {
        echo json_encode(['status' => 'error', 'message' => 'That business is not linked to this award.']);
        exit;
    }
    $result = twg_save_member_score($conn, $questionId, $choiceId, $memberKey, $score);
    echo json_encode([
        'status' => $result['ok'] ? 'success' : 'error',
        'message' => $result['message'],
        'average' => $result['average'],
        'scored' => $result['scored'],
        'member_count' => count(twg_member_keys()),
    ]);
    exit;
}

$eventId = (int) ($_GET['event_id'] ?? 0);
$questionId = (int) ($_GET['question_id'] ?? 0);
if ($eventId <= 0 || $questionId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing event or award.']);
    exit;
}

if (!twg_question_in_event($conn, $eventId, $questionId)) {
    echo json_encode(['status' => 'error', 'message' => 'That award is not an active title in this event.']);
    exit;
}

$sheet = twg_fetch_sheet($conn, $eventId, $questionId);
echo json_encode([
    'status' => 'success',
    'members' => $sheet['members'],
    'nominees' => $sheet['nominees'],
]);
exit;
