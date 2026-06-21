<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once 'connection.php';
require_once 'voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';

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
    voter_json_error('Authentication required. Please complete mobile verification first.', 401);
}

if (!voter_flow_is_voting_open($conn)) {
    voter_json_error('Voting is not open at this time.', 403);
}

if (voter_flow_voter_has_submitted($conn, $sessionVoterId)) {
    voter_json_error('You have already completed voting.', 403);
}

try {
    $stmt = $conn->prepare('SELECT voters_id FROM tbl_voters WHERE voters_id = ? AND mobile_number = ?');
    $stmt->bind_param('is', $sessionVoterId, $mobile);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Voter record mismatch.']);
        exit;
    }
    $stmt->close();

    $update = $conn->prepare('UPDATE tbl_voters SET draft_code = ? WHERE voters_id = ?');
    $update->bind_param('si', $draft_code, $sessionVoterId);
    $update->execute();
    $update->close();

    echo json_encode(['status' => 'success', 'voter_id' => $sessionVoterId]);
} catch (Throwable $e) {
    error_log('save_draft_code: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again.']);
}
