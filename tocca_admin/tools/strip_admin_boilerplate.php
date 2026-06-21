<?php
/**
 * Strip duplicate boilerplate from admin pages not yet on layout partials.
 * Run: php tools/strip_admin_boilerplate.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$skip = ['index.php', 'logout.php', 'register.php', 'events.php', 'categories.php'];

$patterns = [
    '/<script>\s*\(function\s*\(\)\s*\{[\s\S]*?darkMode[\s\S]*?\}\)\(\);\s*<\/script>\s*/i',
    '/<footer class="py-4 bg-light mt-auto">[\s\S]*?<\/footer>\s*/',
    '/<script src="https:\/\/cdn\.jsdelivr\.net\/npm\/bootstrap@5\.3\.3[^"]*"[^>]*><\/script>\s*/',
    '/<script src="js\/scripts\.js"><\/script>\s*/',
    '/<script src="logout\.js"><\/script>\s*/',
    '/<script src="js\/dark_mode_toggle\.js" defer><\/script>\s*/',
];

foreach (glob($root . '/*.php') as $path) {
    $name = basename($path);
    if (in_array($name, $skip, true)) {
        continue;
    }
    $content = file_get_contents($path);
    if ($content === false || strpos($content, 'sb-nav-fixed') === false) {
        continue;
    }
    if (strpos($content, 'admin_layout_start.php') !== false) {
        continue;
    }

    $orig = $content;
    foreach ($patterns as $pattern) {
        $content = preg_replace($pattern, '', $content) ?? $content;
    }

    if (preg_match('/<script src="https:\/\/code\.jquery\.com[^"]+"[^>]*><\/script>/', $content)
        && preg_match('/<script src="https:\/\/cdn\.datatables\.net[^"]+"[^>]*><\/script>/', $content)
    ) {
        $content = preg_replace('/<script src="https:\/\/code\.jquery\.com[^"]+"[^>]*><\/script>\s*/', '', $content) ?? $content;
        $content = preg_replace('/<script src="https:\/\/cdn\.datatables\.net[^"]+"[^>]*><\/script>\s*/', '', $content, 2) ?? $content;
    }

    if ($content !== $orig) {
        if (strpos($content, 'bootstrap.bundle.min.js') === false && strpos($content, '</body>') !== false) {
            $inject = "\n  <script src=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js\"></script>\n"
                . "  <script src=\"js/scripts.js\"></script>\n"
                . "  <script src=\"logout.js\"></script>\n";
            $content = str_replace('</body>', $inject . '</body>', $content);
        }
        file_put_contents($path, $content);
        echo "Stripped: $name\n";
    }
}

echo "Done.\n";
