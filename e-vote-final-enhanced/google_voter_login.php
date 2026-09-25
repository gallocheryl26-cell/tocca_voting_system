<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/firebase_verify.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once __DIR__ . '/lib/voter_google_auth.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
voter_session_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'POST required.']);
        exit;
    }
    if (!voter_google_auth_enabled()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Google voter login is not enabled.']);
        exit;
    }
    if (!voter_flow_is_voting_open($conn)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Voting is not open at this time.']);
        exit;
    }

    voter_google_auth_rate_limit($conn);
    $data = json_decode((string) file_get_contents('php://input'), true);
    $idToken = trim((string) ($data['id_token'] ?? ''));
    $identity = firebase_google_identity(firebase_verify_id_token($idToken));

    $conn->begin_transaction();
    $upsert = $conn->prepare(
        "INSERT INTO tbl_voters
            (mobile_number, firebase_uid, google_email, auth_provider, google_linked_at, date_verified, has_voted)
         VALUES (NULL, ?, ?, 'google', NOW(), NOW(), 0)
         ON DUPLICATE KEY UPDATE
            voters_id = LAST_INSERT_ID(voters_id),
            google_email = VALUES(google_email),
            auth_provider = 'google',
            google_linked_at = COALESCE(google_linked_at, NOW())"
    );
    $upsert->bind_param('ss', $identity['uid'], $identity['email']);
    $upsert->execute();
    $isNew = $upsert->affected_rows === 1;
    $voterId = (int) $upsert->insert_id;
    $upsert->close();

    $stmt = $conn->prepare('SELECT has_voted FROM tbl_voters WHERE voters_id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $voterId);
    $stmt->execute();
    $voter = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$voter || $voterId <= 0) {
        throw new RuntimeException('Unable to resolve the voter account.');
    }
    $hasVoted = (int) $voter['has_voted'];
    $conn->commit();

    voter_google_auth_clear_rate_limit($conn);
    $hasVoted = voter_flow_sync_has_voted_if_complete($conn, $voterId);

    // Keep the authenticated voter in the PHP session even when the ballot is
    // complete. thankyou.php reads this session to show the real 100% progress.
    session_regenerate_id(true);
    $_SESSION['voter_id'] = $voterId;
    $_SESSION['voter_auth_provider'] = 'google';
    $_SESSION['firebase_uid'] = $identity['uid'];
    unset($_SESSION['verified_mobile']);

    if ($hasVoted === 1) {
        echo json_encode([
            'status' => 'success',
            'completion_status' => 'completed',
            'voter_id' => $voterId,
            'is_new' => $isNew,
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'completion_status' => 'incomplete',
        'voter_id' => $voterId,
        'is_new' => $isNew,
        'email' => $identity['email'],
        'display_name' => $identity['display_name'],
    ]);
} catch (mysqli_sql_exception $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('google_voter_login database: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Google login could not be saved. Apply the Google voter database migration, then try again.']);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('google_voter_login: ' . $e->getMessage());
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Google authentication failed. Please try again.']);
}
