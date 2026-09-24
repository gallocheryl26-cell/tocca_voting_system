<?php
declare(strict_types=1);
require __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../e-vote-final-enhanced/lib/voter_flow.php';
require_once __DIR__ . '/../tocca_admin/includes/results_formula.php';

$payload = voter_flow_public_categories($conn);
echo "event={$payload['event_id']}\n";
foreach ($payload['categories'] as $c) {
    echo "CAT {$c['id']} {$c['name']} profile={$c['voting_profile']} shortlist=" . (twg_category_uses_shortlist($c['name']) ? 'yes' : 'no') . PHP_EOL;
}

$eid = (int) $payload['event_id'];
$votable = voter_flow_votable_question_sql($conn, 'q');
$sql = "SELECT q.question_id, q.question_name, q.answer_fields, q.choice_type, c.category_name
        FROM tbl_questions q
        JOIN tbl_categories c ON c.category_id = q.category_id
        WHERE c.event_id = ? AND {$votable}
          AND (c.category_name LIKE '%Informal%' OR q.question_name LIKE '%Song%' OR q.question_name LIKE '%Meryenda%')
        ORDER BY c.category_name, q.question_id";
$st = $conn->prepare($sql);
$st->bind_param('i', $eid);
$st->execute();
$res = $st->get_result();
echo "=== votable specials ===\n";
while ($row = $res->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$st = $conn->prepare(
    "SELECT ch.choice_id, ch.choice_name, ch.status, ch.on_ballot
     FROM tbl_question_choices qc
     JOIN tbl_choices ch ON ch.choice_id = qc.choice_id
     JOIN tbl_questions q ON q.question_id = qc.question_id
     WHERE q.question_name = 'Most Popular Local Meryenda'
     ORDER BY ch.choice_name"
);
$st->execute();
$res = $st->get_result();
echo "=== meryenda choices ===\n";
$n = 0;
while ($row = $res->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $n++;
}
echo "count={$n}\n";
