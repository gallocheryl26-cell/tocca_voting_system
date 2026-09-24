<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once 'connection.php';
require_once __DIR__ . '/lib/voter_flow.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $mobileRaw = is_array($data) ? (string)($data['mobile_number'] ?? '') : '';

    if ($mobileRaw === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing mobile number.']);
        exit;
    }

    echo json_encode(voter_flow_mobile_gate_status($conn, $mobileRaw));
} catch (Throwable $e) {
    error_log('check_mobile_status: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to check this mobile number right now. Please try again.',
    ]);
}
