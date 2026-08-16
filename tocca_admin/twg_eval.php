<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/results_formula.php';

function twg_eval_json(array $payload, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = $_POST;
        }
        $action = (string) ($data['action'] ?? '');
        if ($action === 'save_sheet') {
            $choiceId = (int) ($data['choice_id'] ?? 0);
            $scores = $data['scores'] ?? [];
            if (!is_array($scores)) {
                twg_eval_json(['status' => 'error', 'message' => 'Invalid scores.']);
            }
            $result = twg_save_sheet($conn, $choiceId, $scores);
            twg_eval_json([
                'status' => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
                'saved' => $result['saved'] ?? 0,
                'rows' => $result['rows'] ?? [],
                'invalid' => $result['invalid'] ?? null,
            ], $result['ok'] ? 200 : 400);
        }
        if ($action !== 'save_member') {
            twg_eval_json(['status' => 'error', 'message' => 'Invalid request']);
        }
        $questionId = (int) ($data['question_id'] ?? 0);
        $choiceId = (int) ($data['choice_id'] ?? 0);
        $memberKey = (string) ($data['member_key'] ?? '');
        $parsed = twg_parse_score_value($data['score'] ?? null);
        if (!$parsed['ok']) {
            twg_eval_json(['status' => 'error', 'message' => $parsed['message']]);
        }
        if (!twg_choice_linked_to_question($conn, $questionId, $choiceId)) {
            twg_eval_json(['status' => 'error', 'message' => 'That business is not linked to this award.']);
        }
        $result = twg_save_member_score($conn, $questionId, $choiceId, $memberKey, $parsed['score']);
        twg_eval_json([
            'status' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
            'average' => $result['average'],
            'scored' => $result['scored'],
            'member_count' => count(twg_member_keys()),
        ]);
    }

    $eventId = (int) ($_GET['event_id'] ?? 0);
    $action = trim((string) ($_GET['action'] ?? ''));
    $choiceId = (int) ($_GET['choice_id'] ?? 0);

    if ($eventId <= 0) {
        twg_eval_json(['status' => 'error', 'message' => 'Missing event.']);
    }

    if ($action === 'businesses') {
        twg_eval_json([
            'status' => 'success',
            'businesses' => twg_list_businesses_for_event($conn, $eventId),
        ]);
    }

    if ($choiceId <= 0) {
        twg_eval_json(['status' => 'error', 'message' => 'Choose a business.']);
    }

    if (!twg_choice_in_event($conn, $eventId, $choiceId)) {
        twg_eval_json(['status' => 'error', 'message' => 'That business is not in this event.']);
    }

    $sheet = twg_fetch_sheet_for_choice($conn, $eventId, $choiceId);
    twg_eval_json([
        'status' => 'success',
        'members' => $sheet['members'],
        'choice' => $sheet['choice'],
        'awards' => $sheet['awards'],
    ]);
} catch (Throwable $e) {
    error_log('twg_eval.php: ' . $e->getMessage());
    twg_eval_json(['status' => 'error', 'message' => 'Could not load the score sheet.'], 500);
}
