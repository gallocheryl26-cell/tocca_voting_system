<?php
declare(strict_types=1);

/**
 * Resolve the single active event for admin/import operations.
 */
function admin_get_active_event_id(mysqli $conn): ?int
{
    $res = $conn->query(
        'SELECT event_id FROM tbl_events
         WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0
         ORDER BY year DESC, event_id DESC
         LIMIT 1'
    );
    if ($res && $res->num_rows > 0) {
        return (int) $res->fetch_assoc()['event_id'];
    }
    return null;
}

function admin_get_active_event_label(mysqli $conn, ?int $eventId = null): string
{
    if ($eventId === null) {
        $eventId = admin_get_active_event_id($conn);
    }
    if ($eventId === null) {
        return 'No active event';
    }
    $stmt = $conn->prepare('SELECT event_name, year FROM tbl_events WHERE event_id = ? LIMIT 1');
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return 'Event #' . $eventId;
    }
    return trim(($row['event_name'] ?? '') . ' ' . ($row['year'] ?? ''));
}
