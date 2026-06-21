<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$host = (string) tocca_config('db_host');
$db   = (string) tocca_config('db_name');
$user = (string) tocca_config('db_user');
$pass = (string) tocca_config('db_password');

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    error_log('DB connection failed: ' . $conn->connect_error);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database unavailable.']);
    exit;
}
$conn->set_charset('utf8mb4');
