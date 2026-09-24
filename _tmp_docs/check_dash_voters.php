<?php
require_once dirname(__DIR__) . '/tocca_admin/db_connection.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/voters_list_data.php';
$c = $conn;
$t = microtime(true);
$rows = voters_list_rows_for_event($c, 7);
$elapsed = round(microtime(true) - $t, 2);
$counts = ['completed' => 0, 'drafted' => 0, 'not started' => 0];
foreach ($rows as $row) {
    $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
}
echo 'rows=' . count($rows) . ' seconds=' . $elapsed . ' ' . json_encode($counts) . PHP_EOL;
$c = $conn;
if ($c->connect_error) {
    fwrite(STDERR, $c->connect_error);
    exit(1);
}
function one(mysqli $c, string $sql): void {
    $r = $c->query($sql);
    if (!$r) {
        echo $sql, " ERR ", $c->error, PHP_EOL;
        return;
    }
    echo $sql, PHP_EOL;
    while ($row = $r->fetch_assoc()) {
        echo json_encode($row), PHP_EOL;
    }
}
one($c, 'SELECT COUNT(*) AS all_rows, SUM(is_archived IS NULL) AS archived_null, SUM(is_archived = 0) AS eq_zero, SUM(COALESCE(is_archived,0)=0) AS coalesce_zero FROM tbl_voters');
one($c, 'SELECT has_voted, COUNT(*) AS c FROM tbl_voters GROUP BY has_voted');
one($c, 'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "tbl_voters" AND COLUMN_NAME IN ("is_archived","has_voted")');
one($c, 'SELECT COUNT(DISTINCT voters_id) AS poll_voters FROM (SELECT voters_id FROM tbl_poll_choice UNION SELECT voters_id FROM tbl_poll_freetext) x');
one($c, 'SELECT COUNT(DISTINCT voters_id) AS draft_voters FROM (SELECT voters_id FROM tbl_draft_choice UNION SELECT voters_id FROM tbl_draft_freetext) x');
one($c, 'SELECT COUNT(DISTINCT v.voters_id) AS poll_but_has_voted_0 FROM tbl_voters v INNER JOIN (SELECT voters_id FROM tbl_poll_choice UNION SELECT voters_id FROM tbl_poll_freetext) votes ON v.voters_id = votes.voters_id WHERE v.has_voted = 0');
