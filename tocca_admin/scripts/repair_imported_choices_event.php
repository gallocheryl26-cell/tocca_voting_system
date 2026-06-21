<?php
declare(strict_types=1);

/**
 * Align establishment event_id with the event of linked awards (one-time repair after import).
 * Run: php tocca_admin/scripts/repair_imported_choices_event.php
 */

require_once dirname(__DIR__) . '/db_connection.php';
require_once dirname(__DIR__) . '/includes/admin_active_event.php';

$eventId = admin_get_active_event_id($conn);
if ($eventId === null) {
    fwrite(STDERR, "No active event.\n");
    exit(1);
}

$sql = '
    UPDATE tbl_choices c
    INNER JOIN tbl_question_choices qc ON qc.choice_id = c.choice_id
    INNER JOIN tbl_questions q ON q.question_id = qc.question_id
    INNER JOIN tbl_categories cat ON cat.category_id = q.category_id AND cat.event_id = ?
    SET c.event_id = ?
    WHERE c.event_id <> ?
';

$stmt = $conn->prepare($sql);
$stmt->bind_param('iii', $eventId, $eventId, $eventId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

echo "Repaired {$affected} establishment row(s) for event #{$eventId} (" . admin_get_active_event_label($conn, $eventId) . ").\n";
