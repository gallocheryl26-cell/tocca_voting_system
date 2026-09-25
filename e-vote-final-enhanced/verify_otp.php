<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once 'connection.php';
require_once 'voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';

header('Content-Type: application/json; charset=UTF-8');

if (strtolower(trim((string) tocca_config('voter_auth_mode'))) === 'google_with_legacy') {
    http_response_code(410);
    echo json_encode(['status' => 'error', 'message' => 'SMS OTP is disabled. Please use Google Sign-In.']);
    exit;
}

voter_session_start();

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']);
    exit;
}

$otpInput = trim((string)($data['otp'] ?? ''));
$mobile = voter_flow_normalize_mobile((string)($data['mobile_number'] ?? ($data['mobile'] ?? '')));

if ($otpInput === '' || $mobile === null) {
    echo json_encode(['status' => 'error', 'message' => 'Missing OTP or mobile number.']);
    exit;
}

if (!voter_flow_is_voting_open($conn)) {
    echo json_encode(['status' => 'error', 'message' => 'Voting is not open at this time.']);
    exit;
}

if (!isset($_SESSION['otp'], $_SESSION['otp_mobile'], $_SESSION['otp_expiry'])) {
    echo json_encode(['status' => 'error', 'message' => 'No OTP session found.']);
    exit;
}

if (time() > (int)$_SESSION['otp_expiry']) {
    echo json_encode(['status' => 'expired', 'message' => 'OTP expired.']);
    exit;
}

if ((string)$_SESSION['otp'] !== $otpInput || $_SESSION['otp_mobile'] !== $mobile) {
    echo json_encode(['status' => 'invalid', 'message' => 'OTP did not match.']);
    exit;
}

unset($_SESSION['otp'], $_SESSION['otp_expiry']);
$_SESSION['verified_mobile'] = $mobile;

$query = $conn->prepare('SELECT voters_id, has_voted FROM tbl_voters WHERE mobile_number = ?');
$query->bind_param('s', $mobile);
$query->execute();
$query->bind_result($voterId, $hasVoted);
$exists = $query->fetch();
$query->close();

if (!$exists) {
    $insert = $conn->prepare('INSERT INTO tbl_voters (mobile_number) VALUES (?)');
    $insert->bind_param('s', $mobile);
    $insert->execute();
    $voterId = (int)$insert->insert_id;
    $hasVoted = 0;
    $insert->close();
}

if ((int)$hasVoted === 1) {
    echo json_encode(['status' => 'blocked', 'message' => 'This mobile number has already submitted a vote.']);
    $conn->close();
    exit;
}

$_SESSION['voter_id'] = (int)$voterId;
session_regenerate_id(true);

echo json_encode([
    'status' => 'verified',
    'voter_id' => (int)$voterId,
]);
$conn->close();
