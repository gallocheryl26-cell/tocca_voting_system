<?php
declare(strict_types=1);

/**
 * Create admin users — only available to logged-in administrators.
 */
require_once __DIR__ . '/require_admin_session.php';
tocca_admin_require_login(true);

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_log.php';

$data = json_decode(file_get_contents('php://input'));
if (!is_object($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON received.']);
    exit;
}

$username  = trim((string)($data->username ?? ''));
$firstName = trim((string)($data->firstName ?? ''));
$lastName  = trim((string)($data->lastName ?? ''));
$email     = trim((string)($data->email ?? ''));
$password  = (string)($data->password ?? '');

if ($username === '' || $firstName === '' || $lastName === '' || $email === '' || $password === '') {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare('SELECT 1 FROM tbl_user WHERE username = ? OR email = ? LIMIT 1');
$stmt->bind_param('ss', $username, $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Username or email already exists.']);
    exit;
}
$stmt->close();

$insert = $conn->prepare(
    'INSERT INTO tbl_user (username, firstname, lastname, email, password) VALUES (?, ?, ?, ?, ?)'
);
$insert->bind_param('sssss', $username, $firstName, $lastName, $email, $passwordHash);
if ($insert->execute()) {
    $newId = (int) $insert->insert_id;
    audit_log($conn, 'admin_users', 'create', 'user', $newId, [
        'username' => $username,
        'email' => $email,
    ]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error inserting user.']);
}
$insert->close();
$conn->close();
