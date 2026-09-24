<?php
declare(strict_types=1);
require __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/includes/award_entry_helpers.php';
award_entry_ensure_schema($conn);
$r = $conn->query("SELECT q.question_id, q.question_name, c.category_id, c.category_name
                   FROM tbl_questions q
                   JOIN tbl_categories c ON c.category_id = q.category_id
                   WHERE c.category_name LIKE '%eeling%' OR q.question_name LIKE '%Hangover%'
                   LIMIT 40");
$ids = [];
while ($row = $r->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $ids[] = (int) $row['question_id'];
}
$ballots = award_entry_fetch_ballot_for_questions($conn, $ids);
echo 'qid_count=' . count($ids) . ' ballot_q=' . count($ballots) . PHP_EOL;
foreach ($ballots as $qid => $entries) {
    $byBiz = [];
    foreach ($entries as $e) {
        $cid = (int) $e['choice_id'];
        $byBiz[$cid] = ($byBiz[$cid] ?? 0) + 1;
    }
    $multi = array_filter($byBiz, static fn($n) => $n > 1);
    if ($multi) {
        echo "MULTI qid={$qid} " . json_encode($multi) . PHP_EOL;
    }
}
echo "ok\n";
