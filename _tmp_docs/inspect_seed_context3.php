<?php
declare(strict_types=1);
require __DIR__ . '/../tocca_admin/db_connection.php';

$qids = [77, 72, 65, 138, 139, 140];
$ph = implode(',', array_fill(0, count($qids), '?'));
$sql = "SELECT qc.question_id, q.question_name, c.choice_id, c.choice_name, t.twg_average
        FROM tbl_question_choices qc
        JOIN tbl_questions q ON q.question_id = qc.question_id
        JOIN tbl_choices c ON c.choice_id = qc.choice_id
        LEFT JOIN tbl_twg_scores t ON t.choice_id = c.choice_id AND t.question_id = qc.question_id
        WHERE qc.question_id IN ($ph) AND c.event_id = 7
        ORDER BY q.question_name, c.choice_name";
$st = $conn->prepare($sql);
$st->bind_param(str_repeat('i', count($qids)), ...$qids);
$st->execute();
$res = $st->get_result();
while ($r = $res->fetch_assoc()) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== twg member score counts ===\n";
$c = $conn->query("SELECT COUNT(*) c FROM tbl_twg_member_scores m JOIN tbl_choices ch ON ch.choice_id=m.choice_id WHERE ch.event_id=7");
echo json_encode($c->fetch_assoc()) . PHP_EOL;

echo "=== list business names via field 24 ===\n";
$q = $conn->query("SELECT n.nomination_id, n.status, n.reference_no, a.answer AS business_name
  FROM tbl_nominations n
  LEFT JOIN tbl_nomination_answers a ON a.nomination_id=n.nomination_id AND a.field_id=24
  WHERE n.event_id=7
  ORDER BY n.nomination_id");
while ($r = $q->fetch_assoc()) echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
