<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once 'connection.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once __DIR__ . '/../tocca_admin/includes/category_voting_profile.php';

$response = ['status' => 'error', 'message' => 'Unable to load categories right now. Please try again.'];

if (!isset($conn) || $conn->connect_error) {
    error_log('Database connection failed in get_all_categories.php');
    http_response_code(500);
    echo json_encode($response);
    exit;
}

try {
    $payload = voter_flow_public_categories($conn);
    $response = [
        'status' => 'success',
        'categories' => $payload['categories'],
        'event_id' => $payload['event_id'],
    ];
} catch (Throwable $e) {
    error_log('get_all_categories.php: ' . $e->getMessage());
    http_response_code(500);
}

if (isset($conn) && !$conn->connect_error) {
    $conn->close();
}

echo json_encode($response);
