<?php
$t = file_get_contents('c:/Users/Rysha/OneDrive - ormoc.sti.ph/Desktop/u556809062_tocca_db.sql');
foreach (['tbl_twg_member_scores', 'tbl_twg_scores', 'tbl_twg_entry_member_scores'] as $table) {
    if (!preg_match('/INSERT INTO `' . $table . '`.*?VALUES\s*(.*?);/s', $t, $m)) {
        echo $table . ": no INSERT\n";
        continue;
    }
    $n = preg_match_all('/\(\d+,\d+/', $m[1]);
    echo $table . ': ' . $n . " rows\n";
}
