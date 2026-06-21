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
<?php if (!empty($faviconPath)): ?>
<link rel="icon" type="image/png" href="<?php echo h($faviconPath); ?>">
<?php endif; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<link rel="stylesheet" href="css/user-style.css" />
<link rel="stylesheet" href="css/voter-layout.css" />
<link rel="stylesheet" href="css/voter-components.css" />
<link rel="stylesheet" href="css/voter-mobile.css" />
<?php include __DIR__ . '/voter_appearance_head.php'; ?>
<script>
(function () {
  var host = window.location.hostname;
  if (host === "localhost") {
    window.location.replace(window.location.href.replace("//localhost", "//127.0.0.1"));
  }
})();
</script>
