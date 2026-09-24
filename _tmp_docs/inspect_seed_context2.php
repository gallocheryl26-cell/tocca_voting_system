<?php
declare(strict_types=1);
require __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/includes/establishment_type_event_helpers.php';

echo "=== fields ===\n";
$f = $conn->query("SELECT id, name, label, type, profile_role, is_required FROM tbl_nomination_fields WHERE is_active=1 ORDER BY sort_order, id");
while ($r = $f->fetch_assoc()) {
    echo json_encode($r) . PHP_EOL;
}

echo "=== cafe type 4 awards for event 7 ===\n";
$typeId = 4;
$eventId = 7;
$sql = "SELECT q.question_id, q.question_name, q.answer_fields, c.category_name
        FROM tbl_establishment_type_awards eta
        JOIN tbl_questions q ON q.question_id = eta.question_id
        JOIN tbl_categories c ON c.category_id = q.category_id
        WHERE eta.type_id = ? AND c.event_id = ?
        ORDER BY c.category_name, q.question_name";
$st = $conn->prepare($sql);
if (!$st) {
    echo "prep fail: " . $conn->error . PHP_EOL;
    // maybe different schema
    $show = $conn->query('SHOW COLUMNS FROM tbl_establishment_type_awards');
    while ($r = $show->fetch_assoc()) echo json_encode($r) . PHP_EOL;
} else {
    $st->bind_param('ii', $typeId, $eventId);
    $st->execute();
    $res = $st->get_result();
    $n = 0;
    while ($r = $res->fetch_assoc()) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $n++;
    }
    echo "count=$n\n";
}

echo "=== existing noms event 7 ===\n";
$cnt = $conn->query("SELECT status, COUNT(*) c FROM tbl_nominations WHERE event_id=7 GROUP BY status");
while ($r = $cnt->fetch_assoc()) echo json_encode($r) . PHP_EOL;

echo "=== answers sample names ===\n";
$q = $conn->query("SELECT n.nomination_id, n.status, n.reference_no, a.answer
  FROM tbl_nominations n
  JOIN tbl_nomination_answers a ON a.nomination_id=n.nomination_id
  JOIN tbl_nomination_fields f ON f.id=a.field_id
  WHERE n.event_id=7 AND (f.profile_role='business_name' OR f.name LIKE '%business%')
  ORDER BY n.nomination_id DESC LIMIT 15");
if ($q) {
    while ($r = $q->fetch_assoc()) echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== twg businesses ===\n";
$c = $conn->query("SELECT COUNT(*) c FROM tbl_choices WHERE event_id=7 AND COALESCE(status,1)=1");
echo json_encode($c->fetch_assoc()) . PHP_EOL;
