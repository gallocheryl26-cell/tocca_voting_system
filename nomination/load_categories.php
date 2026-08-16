<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connection.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/establishment_type_event_helpers.php';

header('Content-Type: application/json; charset=UTF-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

et_ensure_m2m_schema($conn);

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection is not available.');
    }

    $requested = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
    $event_id = et_resolve_event_id($conn, $requested);

    if ($event_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'status'  => 'error',
            'message' => 'No active event is configured. Activate an event in admin first.',
        ]);
        exit;
    }

    $statusCol = et_detect_type_status_column($conn);
    $types = et_fetch_type_options_for_event($conn, $event_id, $statusCol);

    echo json_encode([
        'status'     => 'success',
        'types'      => $types,
        'categories' => $types,
        'event_id'   => $event_id,
    ]);
} catch (Throwable $e) {
    error_log('load_categories failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Could not load nature of business. Please try again or contact support.',
    ]);
}
