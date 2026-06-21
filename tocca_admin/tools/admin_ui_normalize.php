<?php
/**
 * Normalize admin UI shells: auth guard, shared topnav, remove duplicate dark-mode CSS.
 * Run: php tools/admin_ui_normalize.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$skip = [
    'index.php', 'logout.php', 'register.php', 'partials/', 'includes/', 'tools/',
    'qr_lib/', 'event.php', 'category.php', 'choice.php', 'voter.php', 'question.php',
    'result.php', 'communication.php', 'comm_', 'nomination.php', 'get_', 'save_',
    'download_', 'generate_', 'upload_', 'send_', 'process_', 'register_api',
    'notifications_stream', 'admin_audit_logs.php', 'audit_log.php', 'qr_frame_settings.php',
];

$topnav = "<?php include __DIR__ . '/partials/admin_topnav.php'; ?>\n";
$authBootstrap = "<?php\nrequire_once __DIR__ . '/require_admin_page.php';\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$stats = ['auth' => 0, 'nav' => 0, 'dark' => 0];

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    foreach ($skip as $prefix) {
        if (str_starts_with($rel, $prefix)) {
            continue 2;
        }
    }
    if (strpos($rel, 'layoutSidenav') === false && strpos(file_get_contents($file->getPathname()) ?: '', 'sb-nav-fixed') === false) {
        continue;
    }

    $path = $file->getPathname();
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    $orig = $content;

    if (strpos($content, 'require_admin_page.php') === false
        && strpos($content, 'includes/admin_init.php') === false
        && preg_match('/^<\?php/m', $content)
    ) {
        $content = preg_replace(
            '/^<\?php\s*/',
            $authBootstrap,
            $content,
            1
        ) ?? $content;
        if ($content !== $orig) {
            $stats['auth']++;
            $orig = $content;
        }
    }

    if (strpos($content, 'partials/admin_topnav.php') === false
        && preg_match('/<nav class="sb-topnav[\s\S]*?<\/nav>/', $content)
    ) {
        $content = preg_replace(
            '/<nav class="sb-topnav[\s\S]*?<\/nav>\s*/',
            $topnav,
            $content,
            1
        ) ?? $content;
        if ($content !== $orig) {
            $stats['nav']++;
            $orig = $content;
        }
    }

    if (strpos($content, 'admin_logout_modal.php') === false
        && preg_match('/id="logoutModal"/', $content)
    ) {
        $content = preg_replace(
            '/<!--\s*Logout(?: Confirmation)? Modal\s*-->[\s\S]*?<\/div>\s*<\/div>\s*<\/div>\s*(?:<script>document\.getElementById\("confirmLogout"\)[\s\S]*?<\/script>\s*)?/i',
            '',
            $content,
            1
        ) ?? $content;
    }

    $content = preg_replace(
        '/<style>\s*html\.dark-mode[\s\S]*?<\/style>\s*/',
        '',
        $content,
        -1,
        $darkCount
    ) ?? $content;
    if ($darkCount > 0) {
        $stats['dark'] += $darkCount;
    }

    $content = preg_replace(
        '/<script>\(function\(\)\{try\{if\(localStorage\.getItem\(\'darkMode\'\)[\s\S]*?\}\)\(\);<\/script>\s*/',
        '',
        $content
    ) ?? $content;

    if ($content !== file_get_contents($path)) {
        file_put_contents($path, $content);
        echo "OK $rel\n";
    }
}

echo "\nAuth added: {$stats['auth']}, Nav replaced: {$stats['nav']}, Dark blocks removed: {$stats['dark']}\n";
