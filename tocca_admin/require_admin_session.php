<?php
declare(strict_types=1);

/**
 * Starts TOCCA_ADMIN session without forcing JSON Content-Type.
 * Use require_admin_api.php for JSON-only endpoints.
 */
require_once __DIR__ . '/session_bootstrap.php';

function tocca_admin_require_login(bool $jsonResponse = true): void
{
    if ($_SESSION['loggedin'] ?? false) {
        return;
    }
    http_response_code(401);
    if (!headers_sent()) {
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=UTF-8');
        } else {
            header('Content-Type: text/plain; charset=UTF-8');
        }
    }
    echo $jsonResponse
        ? json_encode(['status' => 'error', 'message' => 'Unauthorized'])
        : 'Unauthorized';
    exit;
}
