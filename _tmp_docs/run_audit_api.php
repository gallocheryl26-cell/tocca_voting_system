<?php
$_SERVER['HTTP_ACCEPT'] = 'application/json';
session_start();
$_SESSION['loggedin'] = true;
$_SESSION['admin_id'] = 1;
$_SESSION['admin_name'] = 'test';
$_GET['draw'] = 1;
$_GET['start'] = 0;
$_GET['length'] = 25;

ob_start();
try {
  include __DIR__ . '/../tocca_admin/admin_audit_logs.php';
} catch (Throwable $e) {
  echo "INCLUDE_ERR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
$out = ob_get_clean();
echo "HTTP would be set; body length=" . strlen($out) . "\n";
echo substr($out, 0, 500) . "\n";
$j = json_decode($out, true);
if ($j === null) {
  echo "JSON_FAIL: " . json_last_error_msg() . "\n";
} else {
  echo "JSON_OK draw=" . ($j['draw'] ?? '?') . " total=" . ($j['recordsTotal'] ?? '?') . " rows=" . count($j['data'] ?? []) . "\n";
  if (!empty($j['error'])) echo "error_msg=" . ($j['message'] ?? '') . "\n";
}
