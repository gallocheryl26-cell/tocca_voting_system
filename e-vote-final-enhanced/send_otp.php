<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once 'connection.php';
require_once 'voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once __DIR__ . '/lib/otp_delivery.php';

voter_session_start();
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

$recaptchaToken = trim((string) ($data['recaptcha_response'] ?? ''));
if (!otp_verify_recaptcha($recaptchaToken)) {
    echo json_encode(['status' => 'error', 'message' => 'Please complete the reCAPTCHA verification first.']);
    exit;
}

$rateMsg = otp_check_rate_limit();
if ($rateMsg !== null) {
    echo json_encode(['status' => 'error', 'message' => $rateMsg]);
    exit;
}

$mobileRaw = (string)($data['mobile_number'] ?? ($data['mobile'] ?? ''));
$purpose = strtolower(trim((string) ($data['purpose'] ?? 'register')));

if ($purpose === 'reset_code') {
    $mobile = voter_flow_normalize_mobile($mobileRaw);
    if ($mobile === null) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid mobile number format.']);
        exit;
    }
    if (!voter_flow_is_voting_open($conn)) {
        echo json_encode(['status' => 'closed', 'message' => 'Voting is not open at this time.']);
        exit;
    }
    $voter = voter_flow_find_by_mobile($conn, $mobile);
    if ($voter === null || trim((string) ($voter['draft_code'] ?? '')) === '') {
        echo json_encode(['status' => 'error', 'message' => 'No access code found for this mobile number.']);
        exit;
    }
    if ((int) ($voter['has_voted'] ?? 0) === 1) {
        echo json_encode(['status' => 'blocked', 'message' => 'This mobile number has already completed voting.']);
        exit;
    }
    // fall through to OTP generation below with $mobile set
} else {
$registerCheck = voter_flow_can_register_mobile($conn, $mobileRaw);

if ($registerCheck['status'] === 'error') {
    echo json_encode(['status' => 'error', 'message' => $registerCheck['message']]);
    exit;
}
if ($registerCheck['status'] === 'closed') {
    echo json_encode(['status' => 'closed', 'message' => $registerCheck['message']]);
    exit;
}
if ($registerCheck['status'] === 'blocked') {
    echo json_encode(['status' => 'blocked', 'message' => $registerCheck['message']]);
    exit;
}
if ($registerCheck['status'] === 'exists') {
    $mobile = voter_flow_normalize_mobile($mobileRaw);
    $voter = voter_flow_find_by_mobile($conn, $mobile ?? '');
    $voterId = $voter['voters_id'] ?? 0;
    $event = voter_flow_active_event($conn);
    $eventId = $event['event_id'] ?? null;

    if ($voterId > 0 && voter_flow_has_ballot_data($conn, $voterId, $eventId)) {
        echo json_encode([
            'status' => 'incomplete',
            'message' => 'You have an unfinished vote. Please continue as an existing voter.',
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'exists',
        'message' => $registerCheck['message'],
    ]);
    exit;
}

$mobile = voter_flow_normalize_mobile($mobileRaw);
if ($mobile === null) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid mobile number format.']);
    exit;
}
}

$otp = (string) random_int(100000, 999999);
$_SESSION['otp'] = $otp;
$_SESSION['otp_mobile'] = $mobile;
$_SESSION['otp_expiry'] = time() + (int)tocca_config('otp_ttl_seconds');
otp_mark_sent();

try {
    otp_send_sms($mobile, $otp);
} catch (Throwable $e) {
    error_log('send_otp SMS: ' . $e->getMessage());
    unset($_SESSION['otp'], $_SESSION['otp_mobile'], $_SESSION['otp_expiry']);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

$response = ['status' => 'sent', 'message' => 'OTP sent to your mobile number.'];
if (tocca_config('app_debug')) {
    $response['debug_otp'] = $otp;
}
echo json_encode($response);
