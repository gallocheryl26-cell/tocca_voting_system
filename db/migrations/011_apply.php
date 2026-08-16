<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/tocca_admin/db_connection.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/results_formula.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}
twg_member_scores_ensure_schema($conn);
echo "tbl_twg_member_scores ready\n";
