<?php
declare(strict_types=1);
require __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/includes/award_entry_helpers.php';

echo "db=" . (($conn instanceof mysqli) ? 'ok' : 'fail') . PHP_EOL;

$ev = $conn->query('SELECT event_id, event_name, is_active, voting_start, voting_end FROM tbl_events ORDER BY event_id');
echo "=== events ===\n";
while ($r = $ev->fetch_assoc()) {
    echo json_encode($r) . PHP_EOL;
}

echo "=== nomination columns ===\n";
$c = $conn->query('SHOW COLUMNS FROM tbl_nominations');
while ($r = $c->fetch_assoc()) {
    echo $r['Field'] . ' ' . $r['Type'] . PHP_EOL;
}

echo "=== establishment types ===\n";
$tables = $conn->query("SHOW TABLES LIKE 'tbl_establishment%'");
while ($t = $tables->fetch_row()) {
    echo $t[0] . PHP_EOL;
}

$et = $conn->query('SELECT * FROM tbl_establishment_types LIMIT 20');
if ($et) {
    while ($r = $et->fetch_assoc()) {
        echo json_encode($r) . PHP_EOL;
    }
}

echo "=== awards ===\n";
$sql = "SELECT q.question_id, q.question_name, q.answer_fields, c.category_id, c.category_name, c.event_id
        FROM tbl_questions q
        JOIN tbl_categories c ON c.category_id = q.category_id
        ORDER BY c.event_id, c.category_name, q.question_name";
$q = $conn->query($sql);
$n = 0;
while ($r = $q->fetch_assoc()) {
    $kind = award_entry_kind_for_question((string)$r['question_name'], $r['answer_fields'] ?? null);
    $r['entry_kind'] = $kind;
    if ((int)$r['event_id'] === 7 || $n < 40) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    $n++;
}
echo "total awards=$n\n";

echo "=== existing TWG Test ===\n";
$st = $conn->prepare("SELECT nomination_id, business_name, status FROM tbl_nominations WHERE business_name LIKE 'TWG Test%'");
$st->execute();
$res = $st->get_result();
while ($r = $res->fetch_assoc()) {
    echo json_encode($r) . PHP_EOL;
}

echo "=== fields ===\n";
$f = $conn->query("SELECT id, name, label, type, profile_role, is_required FROM tbl_nomination_fields WHERE is_active=1 ORDER BY sort_order, id");
if ($f) {
    while ($r = $f->fetch_assoc()) {
        echo json_encode($r) . PHP_EOL;
    }
}
