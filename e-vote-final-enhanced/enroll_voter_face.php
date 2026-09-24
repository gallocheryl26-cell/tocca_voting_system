<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
voter_session_start();

if (!tocca_config('voter_face_enroll')) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Face enrollment is not enabled.']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $mobile = trim((string) ($data['mobile_number'] ?? ''));
    $image = (string) ($data['image'] ?? '');

    if (!preg_match('/^09\d{9}$/', $mobile)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid mobile number.']);
        exit;
    }
    if (!preg_match('#^data:image/jpeg;base64,#', $image)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'A camera photo is required.']);
        exit;
    }

    $raw = base64_decode(substr($image, strlen('data:image/jpeg;base64,')), true);
    if ($raw === false || strlen($raw) < 2000 || strlen($raw) > 800000 || !str_starts_with($raw, "\xFF\xD8")) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'The photo could not be saved. Take it again.']);
        exit;
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

    $col = $conn->query("SHOW COLUMNS FROM tbl_voters LIKE 'face_image'");
    if ($col && $col->num_rows === 0) {
        $conn->query('ALTER TABLE tbl_voters ADD COLUMN face_image VARCHAR(255) NULL');
    }

    $existing = voter_flow_find_by_mobile($conn, $mobile);
    if ($existing !== null) {
        if ((int) $existing['has_voted'] === 1) {
            http_response_code(403);
            echo json_encode(['status' => 'blocked', 'message' => 'This mobile number has already submitted a vote.']);
            exit;
        }
        if (trim((string) ($existing['draft_code'] ?? '')) !== '') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'This number already has an access code. Use Enter Access Code.']);
            exit;
        }
        $voterId = (int) $existing['voters_id'];
    } else {
        $insert = $conn->prepare('INSERT INTO tbl_voters (mobile_number, date_verified, has_voted) VALUES (?, NOW(), 0)');
        $insert->bind_param('s', $mobile);
        $insert->execute();
        $voterId = (int) $insert->insert_id;
        $insert->close();
    }

    $dir = __DIR__ . '/storage/voter_faces';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Face folder unavailable');
    }
    $relative = 'voter_faces/' . $voterId . '.jpg';
    if (file_put_contents($dir . '/' . $voterId . '.jpg', $raw) === false) {
        throw new RuntimeException('Face file was not written');
    }

    $update = $conn->prepare('UPDATE tbl_voters SET face_image = ? WHERE voters_id = ?');
    $update->bind_param('si', $relative, $voterId);
    $update->execute();
    $update->close();

    $_SESSION['voter_id'] = $voterId;
    $_SESSION['verified_mobile'] = $mobile;
    session_regenerate_id(true);

    echo json_encode([
        'status' => 'success',
        'message' => 'Face saved. Set your access code next.',
        'voter_id' => $voterId,
    ]);
} catch (Throwable $e) {
    error_log('enroll_voter_face: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not save the photo. Please try again.']);
}
