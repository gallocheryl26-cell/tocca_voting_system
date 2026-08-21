<?php
/**
 * Ensure award-entry tables exist and set Date Place to business dropdown.
 * Safe to re-run.
 *
 *   php db/migrations/016_award_named_entries.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tocca_admin/db_connection.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/award_entry_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

award_entry_ensure_schema($conn);

foreach (['tbl_poll_choice', 'tbl_draft_choice'] as $table) {
    $has = false;
    if ($r = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'ballot_entry_id'")) {
        $has = $r->num_rows > 0;
        $r->free();
    }
    if (!$has) {
        $conn->query("ALTER TABLE `{$table}` ADD COLUMN `ballot_entry_id` INT UNSIGNED NULL DEFAULT NULL AFTER `choice_id`");
        $conn->query("ALTER TABLE `{$table}` ADD KEY `idx_{$table}_ballot_entry` (`ballot_entry_id`)");
        echo "Added ballot_entry_id to {$table}\n";
    }
}

// Best Date Place → business dropdown (no typed place name).
$u = $conn->prepare(
    "UPDATE tbl_questions
     SET answer_fields = 'business_photo', choice_type = 1
     WHERE LOWER(question_name) LIKE '%date place%'"
);
$u->execute();
echo 'Date Place awards updated: ' . $u->affected_rows . "\n";
$u->close();

// Best Event Stylist / Make-up Artist stay discoverable by name; Feelings food keep product_business.
$u = $conn->prepare(
    "UPDATE tbl_questions
     SET answer_fields = 'product_business', choice_type = 1
     WHERE (
       LOWER(question_name) LIKE '%make-up artist%'
       OR LOWER(question_name) LIKE '%makeup artist%'
       OR LOWER(question_name) LIKE '%event stylist%'
     )"
);
$u->execute();
echo 'Artist/Stylist awards updated: ' . $u->affected_rows . "\n";
$u->close();

echo "Done.\n";
