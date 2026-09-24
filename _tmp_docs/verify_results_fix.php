<?php
declare(strict_types=1);
require dirname(__DIR__) . '/tocca_admin/db_connection.php';
require dirname(__DIR__) . '/tocca_admin/includes/results_formula.php';

$eventId = 7;
$cast = results_formula_unique_cast_votes($conn, $eventId);
echo "unique_cast total={$cast['total']}\n";
foreach ($cast['by_category'] as $cat => $n) {
    $top = $cast['top_by_category'][$cat]['choice_name'] ?? 'n/a';
    $tv = $cast['top_by_category'][$cat]['vote_count'] ?? 0;
    echo "  {$cat}: {$n} votes, top={$top} ({$tv})\n";
}

$old = $conn->query(
    "SELECT (
      (SELECT COUNT(*) FROM tbl_poll_choice pc
       JOIN tbl_questions q ON pc.question_id=q.question_id
       JOIN tbl_categories c ON q.category_id=c.category_id WHERE c.event_id={$eventId})
      +
      (SELECT COUNT(*) FROM tbl_poll_freetext pf
       JOIN tbl_questions q ON pf.question_id=q.question_id
       JOIN tbl_categories c ON q.category_id=c.category_id WHERE c.event_id={$eventId})
    ) AS n"
)->fetch_assoc();
echo 'old dashboard sum=' . (int) ($old['n'] ?? 0) . "\n";

$r = $conn->query('SELECT COUNT(*) n, SUM(ballot_entry_id IS NOT NULL AND ballot_entry_id>0) e FROM tbl_poll_choice');
$x = $r->fetch_assoc();
echo "\npoll_choice n=" . $x['n'] . ' with_entry=' . ($x['e'] ?? 0) . "\n";
$r = $conn->query('SELECT question_id, COUNT(*) n FROM tbl_award_ballot_entries WHERE is_active=1 GROUP BY question_id ORDER BY n DESC LIMIT 8');
while ($x = $r->fetch_assoc()) {
    echo 'entries q' . $x['question_id'] . '=' . $x['n'] . "\n";
}
$r = $conn->query('SELECT question_id, question_name, answer_fields FROM tbl_questions WHERE question_id IN (131,73,77,145,140)');
while ($x = $r->fetch_assoc()) {
    echo json_encode($x) . PHP_EOL;
}

foreach ([131, 145, 77, 73, 140] as $qid) {
    $p = results_formula_fetch_for_award($conn, $eventId, $qid);
    echo "\nQ{$qid} total_votes={$p['total_votes']} rows=" . count($p['results']) . "\n";
    $shown = 0;
    foreach ($p['results'] as $row) {
        if ((int) ($row['vote_count'] ?? 0) <= 0 && empty($row['ballot_entry_id'])) {
            continue;
        }
        echo sprintf(
            "  rank %s | id=%s entry=%s | %s | votes=%s final=%s\n",
            $row['rank'] ?? '',
            $row['choice_id'] ?? '',
            $row['ballot_entry_id'] ?? '',
            $row['choice_name'] ?? '',
            $row['vote_count'] ?? 0,
            $row['final_score'] ?? ''
        );
        $shown++;
        if ($shown >= 8) {
            echo "  ...\n";
            break;
        }
    }
}
