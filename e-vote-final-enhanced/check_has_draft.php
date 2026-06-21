<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/lib/voter_flow.php';

$data = json_decode(file_get_contents('php://input'), true);
$mobileRaw = is_array($data) ? (string)($data['mobile_number'] ?? '') : '';
$mobile = voter_flow_normalize_mobile($mobileRaw);

if ($mobile === null) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid mobile number.']);
    exit;
}

$voter = voter_flow_find_by_mobile($conn, $mobile);
if ($voter === null) {
    echo json_encode(['status' => 'no_draft']);
    exit;
}

if ((int)$voter['has_voted'] === 1) {
    echo json_encode([
        'status' => 'blocked',
        'message' => 'This mobile number has already submitted a vote.',
    ]);
    exit;
}

if ($voter['draft_code'] !== null && $voter['draft_code'] !== '') {
    echo json_encode(['status' => 'has_draft']);
    exit;
}

echo json_encode(['status' => 'no_draft']);
