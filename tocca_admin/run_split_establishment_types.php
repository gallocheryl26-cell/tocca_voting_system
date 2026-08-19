<?php
/**
 * One-time browser runner for db/migrations/013_split_establishment_types.php
 * Open while logged into admin, then delete this file.
 */
declare(strict_types=1);

require_once __DIR__ . '/require_admin_page.php';

header('Content-Type: text/plain; charset=UTF-8');

$script = dirname(__DIR__) . '/db/migrations/013_split_establishment_types.php';
if (!is_file($script)) {
    http_response_code(500);
    echo "Missing file:\n{$script}\n\nUpload db/migrations/013_split_establishment_types.php first.";
    exit;
}

ob_start();
require $script;
echo ob_get_clean();
