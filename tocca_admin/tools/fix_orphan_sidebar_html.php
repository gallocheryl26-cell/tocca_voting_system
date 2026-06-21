<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pattern = '#(<\?php include __DIR__ \. \'/partials/admin_sidebar\.php\'; \?>)\s*(?:(?!layoutSidenav_content).)*?(?=\s*<div id="layoutSidenav_content">)#s';
$replacement = "$1\n\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $content = file_get_contents($path);
    if ($content === false || strpos($content, 'partials/admin_sidebar.php') === false) {
        continue;
    }
    $new = preg_replace($pattern, $replacement, $content, 1, $count);
    if ($count && $new !== $content) {
        file_put_contents($path, $new);
        echo "Fixed: " . str_replace('\\', '/', substr($path, strlen($root) + 1)) . "\n";
    }
}
