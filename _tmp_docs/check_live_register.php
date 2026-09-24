<?php
$url = 'https://tatakormocawards.com/register';
$ctx = stream_context_create([
  'http' => [
    'header' => "User-Agent: Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36\r\n",
    'timeout' => 30,
  ],
]);
$html = @file_get_contents($url, false, $ctx);
if ($html === false) {
  fwrite(STDERR, "Failed to fetch $url\n");
  exit(1);
}
echo "HTML_BYTES=" . strlen($html) . "\n";
$checks = [
  'picture' => '<picture>',
  'webp_source' => 'type="image/webp"',
  'preload' => 'rel="preload"',
  'async_css_print' => 'media="print"',
  'fonts_async' => 'fonts.googleapis.com',
  'fa_async' => 'font-awesome',
  'bootstrap' => 'bootstrap.min.css',
  'partial_marker' => 'Plus+Jakarta',
];
foreach ($checks as $label => $needle) {
  echo $label . '=' . (str_contains($html, $needle) ? 'yes' : 'no') . "\n";
}
if (preg_match('#nom_banner[^"\']+\.(webp|png|jpe?g)#i', $html, $m)) {
  echo "BANNER_IN_HTML={$m[0]}\n";
}
if (preg_match_all('#(?:src|href|srcset)="([^"]*nom_banner[^"]+)"#i', $html, $mm)) {
  foreach (array_unique($mm[1]) as $u) {
    echo "ASSET=$u\n";
    $abs = str_starts_with($u, 'http') ? $u : ('https://tatakormocawards.com/' . ltrim(preg_replace('#^\.\./#', '', $u), '/'));
    // nomination pages use ../tocca_admin/... relative to /register which is root rewrite
    if (str_contains($u, '../tocca_admin/')) {
      $abs = 'https://tatakormocawards.com/' . substr($u, strlen('../'));
    } elseif (str_starts_with($u, 'tocca_admin/') || str_starts_with($u, '/tocca_admin/')) {
      $abs = 'https://tatakormocawards.com/' . ltrim($u, '/');
    }
    $h = @get_headers($abs, 1, $ctx);
    $status = is_array($h) ? ($h[0] ?? '?') : 'fail';
    $len = is_array($h) ? ($h['Content-Length'] ?? $h['content-length'] ?? '?') : '?';
    $ct = is_array($h) ? ($h['Content-Type'] ?? $h['content-type'] ?? '?') : '?';
    $cc = is_array($h) ? ($h['Cache-Control'] ?? $h['cache-control'] ?? '-') : '-';
    if (is_array($len)) $len = end($len);
    if (is_array($ct)) $ct = end($ct);
    if (is_array($cc)) $cc = end($cc);
    echo "  URL=$abs\n  STATUS=$status\n  TYPE=$ct\n  LEN=$len\n  CACHE=$cc\n";
  }
}

// also probe css cache
$css = 'https://tatakormocawards.com/nomination/nomination_form.css';
$h = @get_headers($css, 1, $ctx);
$cc = is_array($h) ? ($h['Cache-Control'] ?? $h['cache-control'] ?? '-') : '-';
if (is_array($cc)) $cc = end($cc);
echo "CSS_CACHE=$cc\n";
echo "CSS_STATUS=" . (is_array($h) ? ($h[0] ?? '?') : 'fail') . "\n";
