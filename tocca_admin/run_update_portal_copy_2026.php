<?php
/**
 * One-time browser runner for db/migrations/015_update_portal_copy_2026.php
 * Open while logged into admin, then delete this file.
 */
declare(strict_types=1);

require_once __DIR__ . '/require_admin_page.php';

header('Content-Type: text/plain; charset=UTF-8');

$script = dirname(__DIR__) . '/db/migrations/015_update_portal_copy_2026.php';
if (!is_file($script)) {
    http_response_code(500);
    echo "Missing file:\n{$script}\n";
    exit;
}

ob_start();
require $script;
echo ob_get_clean();
