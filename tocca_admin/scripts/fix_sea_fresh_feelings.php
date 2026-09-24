<?php
declare(strict_types=1);
/**
 * Keep Sea Fresh Feelings to one product per title:
 * Bucket Shrimps everywhere except Hangover Recovery Food = Mixed seafoods tinola.
 *
 * Run: php tocca_admin/scripts/fix_sea_fresh_feelings.php
 */
require_once dirname(__DIR__) . '/db_connection.php';
require_once dirname(__DIR__) . '/includes/award_entry_helpers.php';

award_entry_ensure_schema($conn);
try {
    $conn->query("DELETE FROM tbl_config WHERE config_key = 'sea_fresh_feelings_products_v1'");
} catch (Throwable $e) {
}
award_entry_apply_known_ballot_overrides($conn, true);

$res = $conn->query(
    "SELECT c.choice_id, c.choice_name, q.question_name, be.entry_name, be.is_active
     FROM tbl_award_ballot_entries be
     INNER JOIN tbl_choices c ON c.choice_id = be.choice_id
     INNER JOIN tbl_questions q ON q.question_id = be.question_id
     WHERE c.choice_name LIKE '%Sea Fresh%'
     ORDER BY q.question_name, be.is_active DESC, be.entry_name"
);
if (!$res) {
    echo "No Sea Fresh rows (or query failed).\n";
    exit(0);
}
$n = 0;
while ($row = $res->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $n++;
}
if ($n === 0) {
    echo "No Sea Fresh ballot entries in this database.\n";
}
