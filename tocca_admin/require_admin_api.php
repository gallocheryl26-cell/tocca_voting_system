<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db_connection.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

if (!($_SESSION['loggedin'] ?? false)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
