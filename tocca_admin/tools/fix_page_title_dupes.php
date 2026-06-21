<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = glob($root . '/*.php') ?: [];
$crumbLine = '/<\?php echo render_\w+_breadcrumb\(/';

foreach ($files as $path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }
    $seen = [];
    $out = [];
    $removed = 0;
    foreach ($lines as $line) {
        if (preg_match($crumbLine, $line)) {
            $key = preg_replace('/\s+/', ' ', trim($line));
            if (isset($seen[$key])) {
                $removed++;
                continue;
            }
            $seen[$key] = true;
        }
        $out[] = $line;
    }
    if ($removed > 0) {
        file_put_contents($path, implode("\n", $out) . "\n");
        echo 'Deduped ' . $removed . ' in ' . basename($path) . "\n";
    }
}
