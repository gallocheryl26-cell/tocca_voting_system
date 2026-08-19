<?php
/**
 * Refresh voter portal copy to 2026 (defaults + fix stored rows).
 * Safe to re-run.
 *
 *   php db/migrations/015_update_portal_copy_2026.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tocca_admin/db_connection.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/voter_portal_copy.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/establishment_type_event_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

voter_portal_copy_ensure_schema($conn);

$defaults = voter_portal_copy_defaults();
$activeId = et_get_active_event_id($conn) ?? 0;

if ($activeId > 0) {
    voter_portal_copy_save($conn, $activeId, $defaults);
    echo "Saved 2026 voter portal copy for active event_id={$activeId}\n";
}

$conn->query("
    UPDATE tbl_voter_portal_copy
    SET intro_title  = REPLACE(intro_title, '2024', '2026'),
        intro_body   = REPLACE(REPLACE(intro_body, '2024', '2026'), '2025', '2026'),
        how_to_lead  = REPLACE(how_to_lead, '2024', '2026'),
        footer_note  = REPLACE(footer_note, '2024', '2026')
    WHERE intro_title LIKE '%2024%'
       OR intro_body LIKE '%2024%'
       OR intro_body LIKE '%2025%'
       OR how_to_lead LIKE '%2024%'
       OR footer_note LIKE '%2024%'
");
echo 'Updated ' . $conn->affected_rows . " stored row(s) with year replacements.\n";
echo "Done.\n";
