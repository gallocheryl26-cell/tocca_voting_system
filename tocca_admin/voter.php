<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/admin_active_event.php';
require_once __DIR__ . '/includes/voters_list_data.php';

$response = ['status' => 'success', 'data' => [], 'event_id' => null];

$eventId = isset($_GET['event_id']) && ctype_digit((string) $_GET['event_id'])
    ? (int) $_GET['event_id']
    : admin_get_active_event_id($conn);

if ($eventId === null || $eventId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No active event. Activate an event under File Maintenance → Events.',
        'data' => [],
    ]);
    exit;
}

$activeEventId = admin_get_active_event_id($conn);
if ($activeEventId !== null && $eventId !== $activeEventId) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Voter list is limited to the currently active event.',
        'data' => [],
    ]);
    exit;
}

$response['event_id'] = $eventId;
$response['data'] = voters_list_rows_for_event($conn, $eventId);

echo json_encode($response);
