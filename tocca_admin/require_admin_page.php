<?php
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';

if (!function_exists('h')) {
    function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

if (!($_SESSION['loggedin'] ?? false)) {
    header('Location: index.php');
    exit;
}
