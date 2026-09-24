<?php
declare(strict_types=1);
require __DIR__ . '/../tocca_admin/db_connection.php';

$ev = $conn->query('SELECT event_id, event_name, year, is_active, is_archived FROM tbl_events ORDER BY event_id');
echo "=== events ===\n";
while ($r = $ev->fetch_assoc()) {
    echo json_encode($r) . PHP_EOL;
}

$cats = $conn->query('SELECT category_id, category_name, event_id, status, voting_profile FROM tbl_categories ORDER BY event_id, category_name');
echo "=== categories ===\n";
while ($r = $cats->fetch_assoc()) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== breakup / informal / meryenda ===\n";
$q = $conn->query("SELECT q.question_id, q.question_name, q.choice_type, q.answer_fields, c.category_id, c.category_name, c.event_id, c.status
                   FROM tbl_questions q
                   JOIN tbl_categories c ON c.category_id = q.category_id
                   WHERE q.question_name LIKE '%Break%' OR q.question_name LIKE '%Song%'
                      OR q.question_name LIKE '%Meryenda%' OR c.category_name LIKE '%Informal%'
                   ORDER BY c.event_id, q.question_id");
while ($r = $q->fetch_assoc()) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== choices columns ===\n";
$cols = $conn->query('SHOW COLUMNS FROM tbl_choices');
while ($r = $cols->fetch_assoc()) {
    echo $r['Field'] . ' ' . $r['Type'] . ($r['Null'] === 'YES' ? ' null' : '') . PHP_EOL;
}
