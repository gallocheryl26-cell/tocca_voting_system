<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/firebase_verify.php';
require_once __DIR__ . '/lib/voter_flow.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
voter_session_start();

try {
    if (strtolower(trim((string) tocca_config('voter_auth_mode'))) === 'google_with_legacy') {
        http_response_code(410);
        echo json_encode([
            'status' => 'error',
            'message' => 'SMS registration has been replaced by Google Sign-In.',
        ]);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    $idToken = trim((string)($data['id_token'] ?? ''));
    $mobileInput = trim((string)($data['mobile_number'] ?? ''));
    $otpProvider = strtolower(trim((string) tocca_config('otp_provider')));
    $firebaseRequired = $otpProvider !== 'server'
        && (string) tocca_config('firebase_web_api_key') !== '';

    if ($idToken === '' && isset($_SESSION['voter_id'], $_SESSION['verified_mobile'])) {
        $sessionMobile = (string) $_SESSION['verified_mobile'];
        if (!preg_match('/^09\d{9}$/', $sessionMobile)) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid verified session. Please verify OTP again.']);
            exit;
        }
        if ($mobileInput !== '' && $mobileInput !== $sessionMobile) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Mobile number does not match verified session.']);
            exit;
        }
        echo json_encode([
            'status' => 'success',
            'message' => 'Voter session active.',
            'voter_id' => (int) $_SESSION['voter_id'],
        ]);
        exit;
    }

    if ($idToken !== '') {
        $firebaseUser = firebase_verify_id_token($idToken);
        $mobile = firebase_phone_to_local09($firebaseUser);
        if (!preg_match('/^09\d{9}$/', $mobile)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Verified phone number is not a valid PH mobile.']);
            exit;
        }
        if ($mobileInput !== '' && $mobileInput !== $mobile) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Mobile number does not match Firebase verification.']);
            exit;
        }
    } elseif ($firebaseRequired && !tocca_config('app_debug')) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Phone verification required. Please complete OTP again.']);
        exit;
    } elseif (!preg_match('/^09\d{9}$/', $mobileInput)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid mobile number.']);
        exit;
    } else {
        $mobile = $mobileInput;
    }

    if (!voter_flow_is_voting_open($conn)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Voting is not open at this time.']);
        exit;
    }

    $registerCheck = voter_flow_can_register_mobile($conn, $mobile);
    if ($registerCheck['status'] === 'blocked') {
        http_response_code(403);
        echo json_encode(['status' => 'blocked', 'message' => $registerCheck['message']]);
        exit;
    }
    $existing = voter_flow_find_by_mobile($conn, $mobile);
    if ($existing !== null) {
        if ((int)$existing['has_voted'] === 1) {
            http_response_code(403);
            echo json_encode([
                'status' => 'blocked',
                'message' => 'This mobile number has already submitted a vote.',
            ]);
            exit;
        }
        $voter_id = $existing['voters_id'];
    } else {
        $insert = $conn->prepare('INSERT INTO tbl_voters (mobile_number, date_verified, has_voted) VALUES (?, NOW(), 0)');
        $insert->bind_param('s', $mobile);
        $insert->execute();
        $voter_id = (int)$insert->insert_id;
        $insert->close();
    }

    $_SESSION['voter_id'] = $voter_id;
    $_SESSION['verified_mobile'] = $mobile;
    $_SESSION['voter_auth_provider'] = 'phone';
    unset($_SESSION['firebase_uid']);
    session_regenerate_id(true);

    echo json_encode([
        'status' => 'success',
        'message' => 'Voter registered successfully.',
        'voter_id' => $voter_id,
    ]);
} catch (Throwable $e) {
    error_log('register_new_voter: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Registration failed. Please try again.']);
}
