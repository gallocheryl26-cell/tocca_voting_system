<?php
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';

if (!empty($_SESSION['loggedin'])) {
    require_once __DIR__ . '/db_connection.php';
    require_once __DIR__ . '/audit_log.php';
    $userId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
    audit_log($conn, 'auth', 'logout', 'user', $userId, [
        'username' => (string) ($_SESSION['username'] ?? ''),
    ]);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: index.php');
exit;
