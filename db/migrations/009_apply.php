<?php
/**
 * One-shot: add tbl_choices.on_ballot and keep approved-but-not-QR-sent
 * registrations off the public ballot. Safe to re-run.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tocca_admin/db_connection.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/ballot_status.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

$ok = ballot_status_ensure_column($conn);
if (!$ok) {
    fwrite(STDERR, "Failed to add on_ballot column.\n");
    exit(1);
}

$res = $conn->query('SELECT COUNT(*) AS c, SUM(on_ballot = 1) AS onb, SUM(on_ballot = 0) AS offb FROM tbl_choices');
$row = $res ? $res->fetch_assoc() : ['c' => 0, 'onb' => 0, 'offb' => 0];
echo "on_ballot column ready\n";
echo 'choices=' . (int) $row['c']
    . ' on_ballot=' . (int) $row['onb']
    . ' under_eval=' . (int) $row['offb']
    . "\n";
