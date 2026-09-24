<?php
require __DIR__ . '/../tocca_admin/db_connection.php';
if (!($conn instanceof mysqli)) {
  fwrite(STDERR, "no conn\n");
  exit(1);
}
$r = $conn->query("SHOW TABLES LIKE 'tbl_admin_audit_log'");
echo 'table=' . ($r && $r->num_rows ? 'yes' : 'no') . PHP_EOL;
if ($r && $r->num_rows) {
  $c = $conn->query('SHOW COLUMNS FROM tbl_admin_audit_log');
  while ($row = $c->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . PHP_EOL;
  }
  $n = $conn->query('SELECT COUNT(*) c FROM tbl_admin_audit_log')->fetch_assoc();
  echo 'count=' . $n['c'] . PHP_EOL;
  $sql = "SELECT log_id, event_time, admin_id, admin_name, module, entity_type, entity_id, action, details_json
          FROM tbl_admin_audit_log ORDER BY event_time DESC, log_id DESC LIMIT 0, 10";
  $ok = $conn->query($sql);
  if (!$ok) {
    echo 'query_err=' . $conn->error . PHP_EOL;
  } else {
    echo 'sample_ok rows=' . $ok->num_rows . PHP_EOL;
  }
}
// Check nominations business_name used in fallback
$nr = $conn->query("SHOW COLUMNS FROM tbl_nominations LIKE 'business_name'");
echo 'nom_business_name=' . ($nr && $nr->num_rows ? 'yes' : 'no') . PHP_EOL;
$nr2 = $conn->query("SHOW COLUMNS FROM tbl_nominations");
if ($nr2) {
  $cols = [];
  while ($row = $nr2->fetch_assoc()) $cols[] = $row['Field'];
  echo 'nom_cols=' . implode(',', $cols) . PHP_EOL;
}
