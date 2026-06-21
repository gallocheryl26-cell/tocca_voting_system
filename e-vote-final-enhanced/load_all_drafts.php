<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
require_once 'connection.php';
require_once 'voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';

$voter_id = voter_require_authenticated();
voter_flow_json_ballot_denied($conn, $voter_id);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$event_id = isset($input['event_id']) ? (int)$input['event_id'] : 0;

if ($event_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing event ID.']);
    exit;
}

if (isset($input['voter_id'])) {
    $requested = voter_resolve_id($conn, $input['voter_id']) ?? (is_numeric($input['voter_id']) ? (int)$input['voter_id'] : 0);
    if ($requested > 0) {
        voter_assert_matches_session($requested);
    }
}

$hasMediaTable = false;
if ($res = $conn->query("SHOW TABLES LIKE 'tbl_choice_media'")) {
    $hasMediaTable = $res->num_rows > 0;
    $res->close();
}
$mediaCountSql = $hasMediaTable
    ? ', (SELECT COUNT(*) FROM tbl_choice_media m WHERE m.choice_id = COALESCE(dc.choice_id, pc.choice_id)) AS media_count'
    : ', 0 AS media_count';

$query = "
    SELECT
        c.category_id,
        c.category_name,
        q.question_id,
        q.question_name,
        COALESCE(dc.choice_id, pc.choice_id) AS choice_id,
        COALESCE(ch_d.choice_name, ch_p.choice_name, df.freetext) AS selected_answer_text,
        df.freetext AS manual_input,
        CASE WHEN pc.question_id IS NOT NULL OR pf.question_id IS NOT NULL THEN 1 ELSE 0 END AS is_finalized
        {$mediaCountSql}
    FROM tbl_questions q
    JOIN tbl_categories c ON q.category_id = c.category_id
    LEFT JOIN tbl_draft_choice dc ON dc.question_id = q.question_id AND dc.voters_id = ?
    LEFT JOIN tbl_choices ch_d ON dc.choice_id = ch_d.choice_id
    LEFT JOIN tbl_draft_freetext df ON df.question_id = q.question_id AND df.voters_id = ?
    LEFT JOIN tbl_poll_choice pc ON pc.question_id = q.question_id AND pc.voters_id = ?
    LEFT JOIN tbl_choices ch_p ON pc.choice_id = ch_p.choice_id
    LEFT JOIN tbl_poll_freetext pf ON pf.question_id = q.question_id AND pf.voters_id = ?
    WHERE c.event_id = ? AND c.status = 1
    ORDER BY c.category_name, q.question_name
";

$stmt = $conn->prepare($query);
$stmt->bind_param('iiiii', $voter_id, $voter_id, $voter_id, $voter_id, $event_id);
$stmt->execute();
$result = $stmt->get_result();

$grouped = [];
while ($row = $result->fetch_assoc()) {
    $cat_id = (int)$row['category_id'];
    if (!isset($grouped[$cat_id])) {
        $grouped[$cat_id] = [
            'category_id' => $cat_id,
            'category_name' => $row['category_name'],
            'questions' => [],
        ];
    }
    $hasAnswer = $row['choice_id'] !== null
        || (isset($row['manual_input']) && trim((string)$row['manual_input']) !== '');
    $grouped[$cat_id]['questions'][] = [
        'question_id' => (int)$row['question_id'],
        'question_name' => $row['question_name'],
        'choice_id' => $row['choice_id'] !== null ? (int)$row['choice_id'] : null,
        'has_media' => ((int)($row['media_count'] ?? 0)) > 0,
        'selected_answer_text' => $row['selected_answer_text'],
        'manual_input' => $row['manual_input'],
        'is_answered' => $hasAnswer ? 1 : 0,
        'is_finalized' => (int)$row['is_finalized'],
        'category_id' => $cat_id,
    ];
}
$stmt->close();
$conn->close();

echo json_encode(array_values($grouped));
