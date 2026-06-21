<?php
/**
 * Restore footer + core scripts on admin pages after boilerplate strip.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$year = date('Y');

$footer = <<<HTML
      <footer class="py-4 bg-light mt-auto">
        <div class="container-fluid px-4">
          <div class="small text-muted">&copy; {$year} Tatak Ormoc Consumers&rsquo; Choice Awards</div>
        </div>
      </footer>

HTML;

$coreScripts = <<<HTML

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  <script src="js/scripts.js"></script>
  <script src="js/dark_mode_toggle.js" defer></script>
  <script src="logout.js"></script>

HTML;

foreach (glob($root . '/*.php') as $path) {
    $name = basename($path);
    if (in_array($name, ['index.php', 'logout.php', 'register.php'], true)) {
        continue;
    }
    $content = file_get_contents($path);
    if ($content === false || strpos($content, 'sb-nav-fixed') === false) {
        continue;
    }
    if (strpos($content, 'admin_layout_end.php') !== false) {
        continue;
    }

    $orig = $content;

    if (strpos($content, 'py-4 bg-light mt-auto') === false && preg_match('/<\/main>/', $content)) {
        $content = preg_replace(
            '/(\s*)<\/main>\s*(<\/div>\s*<\/div>)/',
            "$1</main>\n{$footer}$2",
            $content,
            1
        ) ?? $content;
    }

    $content = preg_replace(
        '/bootstrap@5\.2\.3\/dist\/js\/bootstrap\.bundle\.min\.js/',
        'bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
        $content
    ) ?? $content;

    if (strpos($content, 'logout.js') === false && strpos($content, '</body>') !== false) {
        $content = str_replace('</body>', $coreScripts . '</body>', $content);
    } elseif (strpos($content, 'logout.js') !== false && strpos($content, 'js/scripts.js') === false) {
        $content = preg_replace(
            '/(<script src="logout\.js"><\/script>)/',
            "  <script src=\"js/scripts.js\"></script>\n  $1",
            $content,
            1
        ) ?? $content;
    }

    if ($content !== $orig) {
        file_put_contents($path, $content);
        echo "Repaired: $name\n";
    }
}

echo "Done.\n";
