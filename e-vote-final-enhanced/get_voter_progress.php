<?php
declare(strict_types=1);

include 'connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/admin_schema.php';

header('Content-Type: application/json; charset=UTF-8');

$mobile = (string) ($_GET['mobile'] ?? '');
$eventId = (int) ($_GET['event_id'] ?? 0);

$voterId = 0;
voter_session_start();
$sessionVoter = (int) ($_SESSION['voter_id'] ?? 0);
if ($sessionVoter > 0) {
    $voterId = $sessionVoter;
}

if ($voterId <= 0 && $mobile !== '') {
    $mobile = preg_replace('/\D/', '', $mobile);
    $stmt = $conn->prepare('SELECT voters_id FROM tbl_voters WHERE mobile_number = ?');
    $stmt->bind_param('s', $mobile);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $voterId = (int) $row['voters_id'];
    }
}

if ($eventId <= 0) {
    $event = voter_flow_active_event($conn);
    $eventId = (int) ($event['event_id'] ?? 0);
}

if ($voterId <= 0 || $eventId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing voter or event']);
    exit;
}

$catSql = admin_active_category_sql($conn, 'c');
$qSql = admin_active_question_sql($conn, 'q');
$votableSql = voter_flow_votable_question_sql($conn, 'q');

$total = voter_flow_count_required_questions($conn, $eventId);
$answered = voter_flow_count_finalized_questions($conn, $voterId, $eventId);
$unanswered = max($total - $answered, 0);
$percent = $total ? (int) round(($answered / $total) * 100) : 0;

$answers = [];
$ansSql = "SELECT q.question_id, q.question_name, c.category_name, ch.choice_name, NULL AS freetext
     FROM tbl_poll_choice pc
     INNER JOIN tbl_questions q ON pc.question_id = q.question_id
     INNER JOIN tbl_categories c ON q.category_id = c.category_id
     INNER JOIN tbl_choices ch ON pc.choice_id = ch.choice_id
     WHERE pc.voters_id = ? AND c.event_id = ? AND {$catSql} AND {$qSql} AND {$votableSql}
     UNION ALL
     SELECT q.question_id, q.question_name, c.category_name, NULL AS choice_name, pf.freetext
     FROM tbl_poll_freetext pf
     INNER JOIN tbl_questions q ON pf.question_id = q.question_id
     INNER JOIN tbl_categories c ON q.category_id = c.category_id
     WHERE pf.voters_id = ? AND c.event_id = ? AND {$catSql} AND {$qSql} AND {$votableSql}";
$stmt = $conn->prepare($ansSql);
if ($stmt) {
    $stmt->bind_param('iiii', $voterId, $eventId, $voterId, $eventId);
    $stmt->execute();
    $res = $stmt->get_result();
    $byQuestion = [];
    while ($row = $res->fetch_assoc()) {
        $qid = (int) $row['question_id'];
        if (!isset($byQuestion[$qid])) {
            $byQuestion[$qid] = [
                'question_id' => $qid,
                'question_name' => (string) $row['question_name'],
                'category_name' => (string) $row['category_name'],
                'choice_name' => trim((string) ($row['choice_name'] ?? '')),
                'freetext' => trim((string) ($row['freetext'] ?? '')),
            ];
        } else {
            if ($byQuestion[$qid]['choice_name'] === '' && trim((string) ($row['choice_name'] ?? '')) !== '') {
                $byQuestion[$qid]['choice_name'] = trim((string) $row['choice_name']);
            }
            if ($byQuestion[$qid]['freetext'] === '' && trim((string) ($row['freetext'] ?? '')) !== '') {
                $byQuestion[$qid]['freetext'] = trim((string) $row['freetext']);
            }
        }
    }
    $stmt->close();
    $answers = array_values($byQuestion);
    usort($answers, static function ($a, $b) {
        $c = strcmp($a['category_name'], $b['category_name']);
        return $c !== 0 ? $c : strcmp($a['question_name'], $b['question_name']);
    });
}

echo json_encode([
    'status' => 'success',
    'answered' => $answered,
    'unanswered' => $unanswered,
    'percent' => $percent,
    'event_id' => $eventId,
    'answers' => $answers,
]);
