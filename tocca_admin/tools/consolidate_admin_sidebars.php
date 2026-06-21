<?php
/**
 * One-time helper: replace duplicated inline sidebars with the shared partial.
 * Run: php tools/consolidate_admin_sidebars.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$replace = '<?php include __DIR__ . \'/partials/admin_sidebar.php\'; ?>';
// Match outer sidebar wrapper only (up to layoutSidenav_content, not the first nested </nav>).
$pattern = '#<div id="layoutSidenav_nav">[\s\S]*?(?=\s*<div id="layoutSidenav_content">)#';

$skip = ['partials/admin_sidebar.php', 'tools/consolidate_admin_sidebars.php', 'dashboard.php'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$changed = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (in_array($rel, $skip, true)) {
        continue;
    }
    $content = file_get_contents($file->getPathname());
    if ($content === false || strpos($content, 'id="layoutSidenav_nav"') === false) {
        continue;
    }
    if (strpos($content, 'partials/admin_sidebar.php') !== false) {
        continue;
    }
    $new = preg_replace($pattern, $replace, $content, 1, $count);
    if ($count !== 1 || $new === null || $new === $content) {
        fwrite(STDERR, "SKIP (no match): $rel\n");
        continue;
    }
    file_put_contents($file->getPathname(), $new);
    $changed[] = $rel;
}

echo "Updated " . count($changed) . " file(s):\n";
foreach ($changed as $f) {
    echo "  - $f\n";
}
