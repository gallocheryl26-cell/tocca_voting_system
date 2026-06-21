<?php
/**
 * Second pass: upgrade remaining legacy admin pages to admin_init.
 * Run: php tools/normalize_admin_bootstrap.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$skip = [
    'index.php', 'logout.php', 'register.php', 'events.php', 'categories.php', 'nominations.php',
];

$fontawesomeBlocking = '<script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>';
$fontawesomeDeferred = '<script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>';

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

    if (strpos($content, "require_once __DIR__ . '/require_admin_page.php';") !== false
        && strpos($content, 'includes/admin_init.php') === false
    ) {
        $content = preg_replace(
            '/<\?php\s*\R(?:declare\(strict_types=1\);\s*\R)?require_once __DIR__ \. \'\/require_admin_page\.php\';\s*\R(?:include [\'"]get_logo\.php[\'"];\s*\R)?(?:require_once [\'"]breadcrumb\.php[\'"];\s*\R)?(?:require_once __DIR__ \. \'\/session_bootstrap\.php\';\s*\R)?(?:require_once __DIR__ \. \'\/db_connection\.php\';\s*\R)?/i',
            "<?php\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/includes/admin_init.php';\nadmin_apply_nav_from_script(basename(__FILE__));\n\n",
            $content,
            1
        ) ?? $content;
    }

    if (strpos($content, $fontawesomeBlocking) !== false) {
        $content = str_replace($fontawesomeBlocking, $fontawesomeDeferred, $content);
    }

    if (strpos($content, 'datatables-simple-demo.js') !== false
        && strpos($content, 'id="datatablesSimple"') === false
        && strpos($content, "id='datatablesSimple'") === false
    ) {
        $content = preg_replace('/<link href="https:\/\/cdn\.jsdelivr\.net\/npm\/simple-datatables[^"]+"[^>]*>\s*/', '', $content) ?? $content;
        $content = preg_replace('/<script src="https:\/\/cdn\.jsdelivr\.net\/npm\/simple-datatables[^"]+"[^>]*><\/script>\s*/', '', $content) ?? $content;
        $content = preg_replace('/<script src="js\/datatables-simple-demo\.js"><\/script>\s*/', '', $content) ?? $content;
    }

    if (strpos($content, 'admin_legacy_footer.php') === false
        && strpos($content, 'bootstrap.bundle.min.js') !== false
    ) {
        $content = preg_replace(
            '/\s*<script src="https:\/\/cdn\.jsdelivr\.net\/npm\/bootstrap@5\.3\.3[^"]*"[^>]*><\/script>\s*<script src="js\/scripts\.js"[^>]*><\/script>\s*(?:<script src="js\/dark_mode_toggle\.js" defer><\/script>\s*)?<script src="logout\.js"[^>]*><\/script>\s*/',
            "\n  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>\n",
            $content,
            1
        ) ?? $content;
    }

    if ($content !== $orig) {
        file_put_contents($path, $content);
        echo "Updated: {$name}\n";
    }
}

echo "Done.\n";
