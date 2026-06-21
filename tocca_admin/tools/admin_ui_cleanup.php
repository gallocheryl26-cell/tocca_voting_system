<?php
/**
 * Fix broken markup after topnav migration: remove orphan </div>, duplicate dark CSS, inline logout handlers.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pages = glob($root . '/*.php') ?: [];

foreach ($pages as $path) {
    $name = basename($path);
    if (in_array($name, ['index.php', 'logout.php', 'register.php'], true)) {
        continue;
    }
    $content = file_get_contents($path);
    if ($content === false || strpos($content, 'admin_topnav.php') === false) {
        continue;
    }

    $orig = $content;

    $content = preg_replace(
        '/(<\?php include __DIR__ \. \'\/partials\/admin_topnav\.php\'; \?>)\s*(?:<\/div>\s*)?(?:<!--[\s\S]*?-->\s*)?(?:<style>[\s\S]*?html\.dark-mode[\s\S]*?<\/style>\s*)*(?:<!-- Apply saved theme[\s\S]*?<\/script>\s*)*(?:<script>\s*document\.getElementById\(["\']confirmLogout["\']\)[\s\S]*?<\/script>\s*)*(?:<\/div>\s*)?/i',
        "$1\n\n",
        $content,
        1
    ) ?? $content;

    while (preg_match('/<style>[\s\S]*?html\.dark-mode[\s\S]*?<\/style>/', $content)) {
        $content = preg_replace('/<style>[\s\S]*?html\.dark-mode[\s\S]*?<\/style>\s*/', '', $content, 1) ?? $content;
    }

    $content = preg_replace(
        '/<script>\s*document\.getElementById\(["\']confirmLogout["\']\)[\s\S]*?<\/script>\s*/i',
        '',
        $content
    ) ?? $content;

    $content = preg_replace(
        '/<!--\s*Script for Logout\s*-->\s*/i',
        '',
        $content
    ) ?? $content;

    if (strpos($content, 'require_admin_page.php') !== false && strpos($content, "require_once 'session_bootstrap.php'") !== false) {
        $content = str_replace("require_once 'session_bootstrap.php';\n", '', $content);
        $content = str_replace('require_once "session_bootstrap.php";' . "\n", '', $content);
    }

    if ($content !== $orig) {
        file_put_contents($path, $content);
        echo "Cleaned: $name\n";
    }
}

echo "Done.\n";
