<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/freetext_vote.php';
require_once __DIR__ . '/includes/award_answer_fields.php';

$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($event_id === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid event ID']);
    exit;
}

$data = [];

// ---- 1. Load CHOICE-BASED VOTES ----
$sqlChoices = "
SELECT 
    c.category_id, c.category_name,
    q.question_id, q.question_name,
    ch.choice_name,
    COUNT(*) AS vote_count
FROM tbl_poll_choice pc
JOIN tbl_questions q ON pc.question_id = q.question_id
JOIN tbl_categories c ON q.category_id = c.category_id
JOIN tbl_choices ch ON pc.choice_id = ch.choice_id
WHERE c.event_id = ?
GROUP BY c.category_id, q.question_id, ch.choice_id
ORDER BY c.category_name ASC, q.question_id ASC, vote_count DESC
";

$stmt1 = $conn->prepare($sqlChoices);
$stmt1->bind_param("i", $event_id);
$stmt1->execute();
$result1 = $stmt1->get_result();

while ($row = $result1->fetch_assoc()) {
    $catId = $row['category_id'];
    $qId = $row['question_id'];

    if (!isset($data[$catId])) {
        $data[$catId] = [
            'category_name' => $row['category_name'],
            'questions' => [],
        ];
    }

    if (!isset($data[$catId]['questions'][$qId])) {
        $data[$catId]['questions'][$qId] = [
            'question_name' => $row['question_name'],
            'results' => [],
        ];
    }

    $data[$catId]['questions'][$qId]['results'][] = [
        'choice_name' => $row['choice_name'],
        'vote_count' => $row['vote_count'],
    ];
}

// ---- 2. Load FREETEXT VOTES ----
$sqlFreetext = "
SELECT 
    c.category_id, c.category_name,
    q.question_id, q.question_name,
    pf.freetext,
    COUNT(*) AS vote_count
FROM tbl_poll_freetext pf
JOIN tbl_questions q ON pf.question_id = q.question_id
JOIN tbl_categories c ON q.category_id = c.category_id
WHERE c.event_id = ?
GROUP BY c.category_id, q.question_id, pf.freetext
ORDER BY c.category_name ASC, q.question_id ASC, vote_count DESC
";


$stmt2 = $conn->prepare($sqlFreetext);
$stmt2->bind_param("i", $event_id);
$stmt2->execute();
$result2 = $stmt2->get_result();

$freetextIndex = [];
while ($row = $result2->fetch_assoc()) {
    $catId = $row['category_id'];
    $qId = $row['question_id'];

    if (!isset($data[$catId])) {
        $data[$catId] = [
            'category_name' => $row['category_name'],
            'questions' => [],
        ];
    }

    if (!isset($data[$catId]['questions'][$qId])) {
        $data[$catId]['questions'][$qId] = [
            'question_name' => $row['question_name'],
            'results' => [],
        ];
    }

    $isPlace = award_answer_fields_is_place_award((string) $row['question_name']);
    $rowForMerge = [
        [
            'choice_name' => $row['freetext'] ?: '(No Answer)',
            'vote_count' => $row['vote_count'],
        ],
    ];
    $merged = $isPlace
        ? freetext_vote_merge_rows_single($rowForMerge)
        : freetext_vote_merge_rows($rowForMerge);
    $entry = $merged[0] ?? [
        'choice_name' => '(No Answer)',
        'vote_count' => $row['vote_count'],
    ];
    $key = $isPlace
        ? freetext_vote_part_key((string) $entry['choice_name'])
        : freetext_vote_key((string) $entry['choice_name']);
    if ($key === '') {
        $key = "\0empty";
    }
    $mapKey = $catId . ':' . $qId . ':' . $key;
    if (isset($freetextIndex[$mapKey])) {
        $idx = $freetextIndex[$mapKey];
        $data[$catId]['questions'][$qId]['results'][$idx]['vote_count'] =
            (int) $data[$catId]['questions'][$qId]['results'][$idx]['vote_count']
            + (int) $entry['vote_count'];
        continue;
    }
    $freetextIndex[$mapKey] = count($data[$catId]['questions'][$qId]['results']);
    $data[$catId]['questions'][$qId]['results'][] = [
        'choice_name' => $entry['choice_name'],
        'vote_count' => $entry['vote_count'],
    ];
}

foreach ($data as &$cat) {
    foreach ($cat['questions'] as &$question) {
        usort($question['results'], static function ($a, $b) {
            return ((int) $b['vote_count']) <=> ((int) $a['vote_count']);
        });
    }
    unset($question);
}
unset($cat);

// ---- Final Response ----
echo json_encode(['status' => 'success', 'data' => $data]);
?>
