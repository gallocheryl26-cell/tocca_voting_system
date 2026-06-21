<?php
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = '/TOCCA_RECENT_NEWEST_2/tocca_admin/nominations.php';
session_start();
$_SESSION['loggedin'] = true;
$_SESSION['username'] = 'test';

$t0 = microtime(true);
ob_start();
include __DIR__ . '/../nominations.php';
$html = ob_get_clean();
echo 'nominations.php: ' . round((microtime(true) - $t0) * 1000) . "ms\n";
echo 'html bytes: ' . strlen($html) . "\n";

$t1 = microtime(true);
require __DIR__ . '/../db_connection.php';
require __DIR__ . '/../includes/nominations_list.php';
$r = nominations_fetch_list($conn, 7, 'all', 1, 10);
echo 'list query: ' . round((microtime(true) - $t1) * 1000) . "ms rows=" . count($r['rows']) . "\n";

$t2 = microtime(true);
require __DIR__ . '/../get_logo.php';
echo 'get_logo: ' . round((microtime(true) - $t2) * 1000) . "ms\n";
