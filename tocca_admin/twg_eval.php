<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/results_formula.php';
require_once __DIR__ . '/audit_log.php';

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
        if ($action === 'save_criteria' || $action === 'restore_defaults') {
            $eventId = (int) ($data['event_id'] ?? 0);
            if ($eventId <= 0 && function_exists('admin_get_active_event_id')) {
                $eventId = (int) (admin_get_active_event_id($conn) ?? 0);
            }
            if ($action === 'restore_defaults') {
                $result = twg_criteria_restore_defaults($conn, $eventId);
            } else {
                $items = $data['criteria'] ?? [];
                if (!is_array($items)) {
                    twg_eval_json(['status' => 'error', 'message' => 'Invalid criteria.']);
                }
                $result = twg_criteria_save_for_event($conn, $eventId, $items);
            }
            if (!empty($result['ok'])) {
                audit_log($conn, 'twg_evaluation', $action, 'event', $eventId, [
                    'count' => count($result['criteria'] ?? []),
                ]);
            }
            twg_eval_json([
                'status' => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
                'criteria' => $result['criteria'] ?? [],
                'members' => $result['criteria'] ?? [],
            ], $result['ok'] ? 200 : 400);
        }
        if ($action === 'save_rubric') {
            $eventId = (int) ($data['event_id'] ?? 0);
            if ($eventId <= 0 && function_exists('admin_get_active_event_id')) {
                $eventId = (int) (admin_get_active_event_id($conn) ?? 0);
            }
            $cells = $data['scores'] ?? [];
            if (!is_array($cells)) {
                twg_eval_json(['status' => 'error', 'message' => 'Invalid scores.']);
            }
            require_once __DIR__ . '/includes/twg_rubric.php';
            $result = twg_rubric_save_cells($conn, $eventId, $cells);
            if (!empty($result['ok'])) {
                audit_log($conn, 'twg_evaluation', 'save_rubric', 'event', $eventId, [
                    'saved' => $result['saved'] ?? 0,
                ]);
            }
            twg_eval_json([
                'status' => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
                'saved' => $result['saved'] ?? 0,
                'invalid' => $result['invalid'] ?? null,
            ], $result['ok'] ? 200 : 400);
        }
        if ($action === 'save_rubric_items') {
            $eventId = (int) ($data['event_id'] ?? 0);
            if ($eventId <= 0 && function_exists('admin_get_active_event_id')) {
                $eventId = (int) (admin_get_active_event_id($conn) ?? 0);
            }
            $items = $data['rubric'] ?? [];
            if (!is_array($items)) {
                twg_eval_json(['status' => 'error', 'message' => 'Invalid scoring items.']);
            }
            require_once __DIR__ . '/includes/twg_rubric.php';
            $result = twg_rubric_save_items($conn, $eventId, $items);
            if (!empty($result['ok'])) {
                audit_log($conn, 'twg_evaluation', 'save_rubric_items', 'event', $eventId, [
                    'count' => count($result['rubric'] ?? []),
                ]);
            }
            twg_eval_json([
                'status' => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
                'rubric' => $result['rubric'] ?? [],
            ], $result['ok'] ? 200 : 400);
        }
        if ($action === 'save_sheet') {
            $choiceId = (int) ($data['choice_id'] ?? 0);
            $scores = $data['scores'] ?? [];
            if (!is_array($scores)) {
                twg_eval_json(['status' => 'error', 'message' => 'Invalid scores.']);
            }
            $result = twg_save_sheet($conn, $choiceId, $scores);
            if (!empty($result['ok'])) {
                audit_log($conn, 'twg_evaluation', 'save_sheet', 'choice', $choiceId, [
                    'saved' => $result['saved'] ?? 0,
                ]);
            }
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
            'member_count' => (int) ($result['member_count'] ?? count(twg_member_keys())),
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

    if ($action === 'criteria') {
        require_once __DIR__ . '/includes/twg_rubric.php';
        $criteria = twg_criteria_for_event($conn, $eventId);
        twg_eval_json([
            'status' => 'success',
            'criteria' => $criteria,
            'members' => $criteria,
            'rubric' => twg_rubric_for_event($conn, $eventId),
        ]);
    }

    if ($action === 'filters') {
        $categories = [];
        $questionsByCategory = [];
        $catActive = admin_active_category_sql($conn, 'c');
        $qActive = admin_active_question_sql($conn, 'q');
        $catStmt = $conn->prepare(
            "SELECT c.category_id, c.category_name
             FROM tbl_categories c
             WHERE c.event_id = ? AND {$catActive}
               AND EXISTS (
                 SELECT 1 FROM tbl_questions q
                 WHERE q.category_id = c.category_id AND {$qActive}
               )
             ORDER BY c.category_name ASC"
        );
        if ($catStmt) {
            $catStmt->bind_param('i', $eventId);
            $catStmt->execute();
            $catRes = $catStmt->get_result();
            while ($row = $catRes->fetch_assoc()) {
                $categories[] = $row;
            }
            $catStmt->close();
        }
        $qStmt = $conn->prepare(
            "SELECT q.question_id, q.question_name, q.category_id
             FROM tbl_questions q
             INNER JOIN tbl_categories c ON q.category_id = c.category_id
             WHERE c.event_id = ? AND {$catActive} AND {$qActive}
             ORDER BY c.category_name ASC, q.question_name ASC"
        );
        if ($qStmt) {
            $qStmt->bind_param('i', $eventId);
            $qStmt->execute();
            $qRes = $qStmt->get_result();
            while ($row = $qRes->fetch_assoc()) {
                $questionsByCategory[$row['category_id']][] = [
                    'question_id' => (int) $row['question_id'],
                    'question_name' => (string) $row['question_name'],
                ];
            }
            $qStmt->close();
        }
        twg_eval_json([
            'status' => 'success',
            'categories' => $categories,
            'questions_by_category' => $questionsByCategory,
        ]);
    }

    if ($action === 'award_sheet') {
        require_once __DIR__ . '/includes/twg_rubric.php';
        $questionId = (int) ($_GET['question_id'] ?? 0);
        if ($questionId <= 0) {
            twg_eval_json(['status' => 'error', 'message' => 'Choose an award title.']);
        }
        $sheet = twg_rubric_award_sheet($conn, $eventId, $questionId);
        if (empty($sheet['award'])) {
            twg_eval_json(['status' => 'error', 'message' => 'Award not found.']);
        }
        twg_eval_json([
            'status' => 'success',
            'members' => $sheet['members'],
            'rubric' => $sheet['rubric'],
            'award' => $sheet['award'],
            'rows' => $sheet['rows'],
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
