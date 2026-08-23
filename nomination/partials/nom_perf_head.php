<?php
/**
 * Shared nomination public-page head hints: fonts, async icon CSS, LCP preload.
 *
 * Optional vars before include:
 *   $nomPerfIconCss   - 'fa' (default) | 'bi' | 'none'
 *   $nomPerfPreload   - absolute-or-relative image URL to preload (LCP banner)
 *   $nomPerfPreloadType - e.g. image/webp
 */
$nomPerfIconCss = $nomPerfIconCss ?? 'fa';
$nomPerfPreload = $nomPerfPreload ?? '';
$nomPerfPreloadType = $nomPerfPreloadType ?? '';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<?php if ($nomPerfPreload !== ''): ?>
<link rel="preload" as="image" href="<?php echo htmlspecialchars($nomPerfPreload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"<?php
  if ($nomPerfPreloadType !== '') {
    echo ' type="' . htmlspecialchars($nomPerfPreloadType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
  }
?>>
<?php endif; ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"></noscript>
<?php if ($nomPerfIconCss === 'fa'): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" media="print" onload="this.media='all'" crossorigin="anonymous" referrerpolicy="no-referrer">
<noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer"></noscript>
<?php elseif ($nomPerfIconCss === 'bi'): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"></noscript>
<?php endif; ?>
