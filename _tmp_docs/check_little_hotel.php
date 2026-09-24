<?php
require __DIR__ . '/../tocca_admin/db_connection.php';
require __DIR__ . '/../tocca_admin/includes/establishment_type_event_helpers.php';
et_ensure_m2m_schema($conn);
$q = $conn->query("SELECT event_id, event_name FROM tbl_events WHERE is_active=1 AND COALESCE(is_archived,0)=0 ORDER BY event_id DESC LIMIT 1");
$ev = $q->fetch_assoc();
$eid = (int) $ev['event_id'];
echo "event={$eid} {$ev['event_name']}\n";

$st = $conn->prepare(
  "SELECT q.question_id, q.question_name, t.type_id, t.type_name
   FROM tbl_questions q
   JOIN tbl_categories c ON c.category_id=q.category_id AND c.event_id=?
   JOIN tbl_establishment_type_awards x ON x.question_id=q.question_id
   JOIN tbl_establishment_types t ON t.type_id=x.type_id
   WHERE q.question_name LIKE '%Little Hotel%' OR q.question_name LIKE '%Mabuhay%'
   ORDER BY q.question_name, t.type_name"
);
$st->bind_param('i', $eid);
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) {
  echo "{$row['question_id']} | {$row['question_name']} => {$row['type_id']} {$row['type_name']}\n";
}

// list types for this event
echo "\nTypes for event:\n";
foreach (et_types_for_event($conn, $eid) as $t) {
  echo "{$t['type_id']} | {$t['type_name']}\n";
}
