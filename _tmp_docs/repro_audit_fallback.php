<?php
require __DIR__ . '/../tocca_admin/db_connection.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $st = $conn->prepare('SELECT business_name FROM tbl_nominations WHERE nomination_id=?');
  $id = 1;
  $st->bind_param('i', $id);
  $st->execute();
  $r = $st->get_result()->fetch_assoc();
  echo "ok\n";
} catch (Throwable $e) {
  echo 'ERR: ' . $e->getMessage() . "\n";
}

// count nomination entity rows in audit
$c = $conn->query("SELECT COUNT(*) c FROM tbl_admin_audit_log WHERE entity_type IN ('nomination','registrations') OR module='registrations'")->fetch_assoc();
echo 'registration_audit_rows=' . $c['c'] . "\n";
$sample = $conn->query("SELECT log_id, entity_type, entity_id, module FROM tbl_admin_audit_log WHERE module='registrations' OR entity_type LIKE '%nomination%' LIMIT 5");
while ($row = $sample->fetch_assoc()) {
  print_r($row);
}
