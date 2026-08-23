<?php
/** Shared voter page head assets. Set $pageTitle before include. */
if (!function_exists('h')) {
    function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
$voterPageTitle = $pageTitle ?? 'Tatak Ormoc Consumers\' Choice Awards';
?>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="theme-color" content="#010066" />
<meta name="color-scheme" content="light" />
<title><?php echo h($voterPageTitle); ?></title>
<?php if (function_exists('tocca_emit_asset_base_tag')) { tocca_emit_asset_base_tag(); } ?>
<?php if (!empty($faviconPath)): ?>
<link rel="icon" type="image/png" href="<?php echo h($faviconPath); ?>">
<?php endif; ?>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'" crossorigin="anonymous" referrerpolicy="no-referrer" />
<noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" /></noscript>
<link rel="stylesheet" href="css/user-style.css?v=<?php echo (int) (@filemtime(__DIR__ . '/../css/user-style.css') ?: time()); ?>" />
<link rel="stylesheet" href="css/voter-layout.css?v=<?php echo (int) (@filemtime(__DIR__ . '/../css/voter-layout.css') ?: time()); ?>" />
<link rel="stylesheet" href="css/voter-components.css?v=<?php echo (int) (@filemtime(__DIR__ . '/../css/voter-components.css') ?: time()); ?>" media="print" onload="this.media='all'" />
<link rel="stylesheet" href="css/voter-mobile.css?v=<?php echo (int) (@filemtime(__DIR__ . '/../css/voter-mobile.css') ?: time()); ?>" media="print" onload="this.media='all'" />
<noscript>
<link rel="stylesheet" href="css/voter-components.css?v=<?php echo (int) (@filemtime(__DIR__ . '/../css/voter-components.css') ?: time()); ?>" />
<link rel="stylesheet" href="css/voter-mobile.css?v=<?php echo (int) (@filemtime(__DIR__ . '/../css/voter-mobile.css') ?: time()); ?>" />
</noscript>
<?php include __DIR__ . '/voter_appearance_head.php'; ?>
<?php
$headerLogoSrc = trim((string) ($headerLogo ?? ''));
if ($headerLogoSrc === '') {
    $headerLogoSrc = trim((string) ($voterHeaderLogoPath ?? ''));
}
if ($headerLogoSrc === '') {
    $headerLogoSrc = 'img/tocca2023.jpg';
}
$isWebpLogo = (bool) preg_match('/\.webp($|\?)/i', $headerLogoSrc);
?>
<link rel="preload" as="image" href="<?php echo h($headerLogoSrc); ?>"<?php echo $isWebpLogo ? ' type="image/webp"' : ''; ?>>
<script>
(function () {
  var host = window.location.hostname;
  if (host === "localhost") {
    window.location.replace(window.location.href.replace("//localhost", "//127.0.0.1"));
  }
})();
</script>
