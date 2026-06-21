<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$host = (string) tocca_config('db_host');
$db   = (string) tocca_config('db_name');
$user = (string) tocca_config('db_user');
$password = (string) tocca_config('db_password');

$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($host, $user, $password, $db);
} catch (mysqli_sql_exception $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    if (!headers_sent() && (php_sapi_name() === 'cli' || str_contains($_SERVER['REQUEST_URI'] ?? '', '.php'))) {
        http_response_code(500);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'message' => 'Database unavailable. Start MySQL in XAMPP, then try again.']);
            exit;
        }
    }
    die('Database connection failed.');
}

if ($conn->connect_error) {
    error_log('DB connection failed: ' . $conn->connect_error);
    die('Database connection failed.');
}

$conn->set_charset('utf8mb4');
