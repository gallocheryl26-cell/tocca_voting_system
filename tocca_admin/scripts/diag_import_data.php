<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db_connection.php';

echo "Events:\n";
$res = $conn->query('SELECT event_id, event_name, year, is_active FROM tbl_events ORDER BY event_id DESC');
while ($r = $res->fetch_assoc()) {
    echo "  #{$r['event_id']} {$r['event_name']} {$r['year']} active={$r['is_active']}\n";
}

$active = $conn->query('SELECT event_id, event_name, year FROM tbl_events WHERE is_active = 1 ORDER BY year DESC, event_id DESC LIMIT 1');
$row = $active->fetch_assoc();
$eid = (int) $row['event_id'];
echo "\nActive (ordered): #{$eid} {$row['event_name']} {$row['year']}\n";

$active2 = $conn->query('SELECT event_id FROM tbl_events WHERE is_active = 1 LIMIT 1');
$row2 = $active2->fetch_assoc();
echo "Active (import query): #{$row2['event_id']}\n";

$stmt = $conn->prepare('SELECT q.question_id, q.question_name, c.category_name, c.event_id FROM tbl_questions q INNER JOIN tbl_categories c ON c.category_id = q.category_id WHERE c.event_id = ?');
$stmt->bind_param('i', $eid);
$stmt->execute();
$r = $stmt->get_result();
echo "\nQuestions for active event: {$r->num_rows}\n";
while ($q = $r->fetch_assoc()) {
    echo "  [{$q['question_id']}] {$q['question_name']} ({$q['category_name']})\n";
}

$stmt2 = $conn->prepare('SELECT choice_id, choice_name, event_id FROM tbl_choices WHERE event_id = ?');
$stmt2->bind_param('i', $eid);
$stmt2->execute();
$r2 = $stmt2->get_result();
echo "\nChoices for active event: {$r2->num_rows}\n";
while ($c = $r2->fetch_assoc()) {
    echo "  [{$c['choice_id']}] {$c['choice_name']}\n";
}

echo "\nAngel's Burger rows:\n";
$res = $conn->query("SELECT choice_id, choice_name, event_id FROM tbl_choices WHERE choice_name LIKE \"%Angel%\"");
while ($c = $res->fetch_assoc()) {
    echo "  [{$c['choice_id']}] event={$c['event_id']} {$c['choice_name']}\n";
}

echo "\nAll questions (any event):\n";
$res = $conn->query('SELECT q.question_id, q.question_name, c.category_name, c.event_id FROM tbl_questions q INNER JOIN tbl_categories c ON c.category_id = q.category_id ORDER BY q.question_id DESC LIMIT 20');
while ($q = $res->fetch_assoc()) {
    echo "  [{$q['question_id']}] event={$q['event_id']} {$q['question_name']} ({$q['category_name']})\n";
}
