<?php
require __DIR__ . '/../tocca_admin/db_connection.php';
$conn->query(
  "INSERT INTO tbl_admin_audit_log
   (event_time, admin_id, admin_name, module, entity_type, entity_id, action, details_json, ip_address, user_agent)
   VALUES (NOW(), 1, 'admin', 'registrations', 'nomination', '1', 'approve', '{\"to\":\"approved\"}', '127.0.0.1', 'cli')"
);
echo 'inserted id=' . $conn->insert_id . PHP_EOL;
