<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once 'connection.php';
require_once 'voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';

try {
    voter_session_start();

    $data = json_decode(file_get_contents('php://input'), true);
    $mobile = trim((string)($data['mobile'] ?? ''));
    $draft_code = trim((string)($data['draft_code'] ?? ''));

    if (!$mobile || !preg_match('/^09\d{9}$/', $mobile) || !preg_match('/^\d{4}$/', $draft_code)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input.']);
        exit;
    }

    $sessionVoterId = (int)($_SESSION['voter_id'] ?? 0);
    $sessionMobile = (string)($_SESSION['verified_mobile'] ?? $_SESSION['otp_mobile'] ?? '');

    if ($sessionVoterId <= 0 || $sessionMobile !== $mobile) {
        // First access code only. An existing code still requires OTP (Forgot access code).
        $find = $conn->prepare(
            'SELECT voters_id, draft_code, has_voted FROM tbl_voters WHERE mobile_number = ? LIMIT 1'
        );
        if (!$find) {
            voter_json_error('Unable to save the access code. Please try again.', 500);
        }
        $find->bind_param('s', $mobile);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        $find->close();
        if (!$existing) {
            voter_json_error('Authentication required. Please complete mobile verification first.', 401);
        }
        if ((int)($existing['has_voted'] ?? 0) === 1) {
            voter_json_error('You have already completed voting.', 403);
        }
        $currentCode = trim((string)($existing['draft_code'] ?? ''));
        if ($currentCode !== '') {
            voter_json_error('This number already has an access code. Use Enter Access Code.', 403);
        }
        $sessionVoterId = (int)$existing['voters_id'];
        $_SESSION['voter_id'] = $sessionVoterId;
        $_SESSION['verified_mobile'] = $mobile;
    }

    if (!voter_flow_is_voting_open($conn)) {
        voter_json_error('Voting is not open at this time.', 403);
    }

    $stmt = $conn->prepare('SELECT voters_id, has_voted FROM tbl_voters WHERE voters_id = ? AND mobile_number = ? LIMIT 1');
    $stmt->bind_param('is', $sessionVoterId, $mobile);
    $stmt->execute();
    $voter = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$voter) {
        echo json_encode(['status' => 'error', 'message' => 'Voter record mismatch.']);
        exit;
    }
    if ((int)($voter['has_voted'] ?? 0) === 1) {
        voter_json_error('You have already completed voting.', 403);
    }

    $update = $conn->prepare('UPDATE tbl_voters SET draft_code = ? WHERE voters_id = ?');
    $update->bind_param('si', $draft_code, $sessionVoterId);
    $update->execute();
    $update->close();

    echo json_encode(['status' => 'success', 'voter_id' => $sessionVoterId]);
} catch (Throwable $e) {
    error_log('save_draft_code: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again.']);
}
