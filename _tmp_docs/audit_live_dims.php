<?php
$pages = [
  'https://tatakormocawards.com/',
  'https://tatakormocawards.com/register',
  'https://tatakormocawards.com/vote',
];
$ctx = stream_context_create([
  'http' => [
    'timeout' => 30,
    'header' => "User-Agent: Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36\r\n",
  ],
]);

foreach ($pages as $url) {
  $html = @file_get_contents($url, false, $ctx);
  if ($html === false) {
    echo "FAIL $url\n";
    continue;
  }
  echo "=== $url bytes=" . strlen($html) . "\n";
  preg_match_all('/<img\b[^>]*>/i', $html, $imgs);
  $missing = 0;
  $ok = 0;
  foreach ($imgs[0] as $tag) {
    $hasW = preg_match('/\bwidth\s*=/i', $tag);
    $hasH = preg_match('/\bheight\s*=/i', $tag);
    if ($hasW && $hasH) {
      $ok++;
    } else {
      $missing++;
      if (preg_match('/src=["\']([^"\']+)/', $tag, $m)) {
        echo '  NO_DIMS: ' . substr($m[1], 0, 100) . "\n";
      }
    }
  }
  echo "  imgs ok=$ok missing=$missing\n";
  echo '  scripts=' . preg_match_all('/<script\b/i', $html) . ' css_links=' . preg_match_all('/rel=["\']stylesheet/i', $html) . "\n";

  // heavy image candidates
  if (preg_match_all('/(?:src|srcset)=["\']([^"\']+\.(?:png|jpe?g|webp|gif))["\']/i', $html, $mm)) {
    foreach (array_unique($mm[1]) as $src) {
      $abs = $src;
      if (str_starts_with($src, '../')) {
        $abs = 'https://tatakormocawards.com/' . substr($src, 3);
      } elseif (str_starts_with($src, '/')) {
        $abs = 'https://tatakormocawards.com' . $src;
      } elseif (!str_starts_with($src, 'http')) {
        $base = rtrim($url, '/');
        if (!str_contains($base, '/register') && !str_contains($base, '/vote')) {
          $abs = 'https://tatakormocawards.com/' . ltrim($src, '/');
        } else {
          $folder = str_contains($url, '/register') ? 'nomination' : 'e-vote-final-enhanced';
          $abs = "https://tatakormocawards.com/$folder/" . ltrim($src, '/');
        }
      }
      $h = @get_headers($abs, 1, $ctx);
      if (!is_array($h)) continue;
      $len = $h['Content-Length'] ?? $h['content-length'] ?? null;
      if (is_array($len)) $len = end($len);
      if ($len && (int)$len > 80000) {
        echo "  HEAVY=" . (int)$len . " $abs\n";
      }
    }
  }
}
