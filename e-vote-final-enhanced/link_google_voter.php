<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/firebase_verify.php';
require_once __DIR__ . '/lib/voter_google_auth.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$voterId = voter_require_authenticated();

try {
    if (!voter_google_auth_enabled()) {
        throw new RuntimeException('Google voter login is not enabled.');
    }
    voter_google_auth_rate_limit($conn, 15, 300);
    $data = json_decode((string) file_get_contents('php://input'), true);
    $identity = firebase_google_identity(firebase_verify_id_token(trim((string) ($data['id_token'] ?? ''))));

    $conn->begin_transaction();
    $check = $conn->prepare('SELECT voters_id FROM tbl_voters WHERE firebase_uid = ? LIMIT 1 FOR UPDATE');
    $check->bind_param('s', $identity['uid']);
    $check->execute();
    $owner = $check->get_result()->fetch_assoc();
    $check->close();
    if ($owner && (int) $owner['voters_id'] !== $voterId) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'This Google account is already connected to another voter.']);
        exit;
    }

    $update = $conn->prepare(
        "UPDATE tbl_voters
         SET firebase_uid = ?, google_email = ?, auth_provider = 'google', google_linked_at = NOW()
         WHERE voters_id = ?"
    );
    $update->bind_param('ssi', $identity['uid'], $identity['email'], $voterId);
    $update->execute();
    $update->close();
    $conn->commit();

    voter_google_auth_clear_rate_limit($conn);
    session_regenerate_id(true);
    $_SESSION['voter_id'] = $voterId;
    $_SESSION['voter_auth_provider'] = 'google';
    $_SESSION['firebase_uid'] = $identity['uid'];

    echo json_encode(['status' => 'success', 'message' => 'Google account linked.', 'email' => $identity['email']]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('link_google_voter: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unable to link this Google account.']);
}
