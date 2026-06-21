<?php
require_once dirname(__DIR__) . '/db_connection.php';
$t = $argv[1] ?? 'tbl_choices';
$r = $conn->query("SHOW COLUMNS FROM `$t`");
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
