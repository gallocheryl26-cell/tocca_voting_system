<?php
/**
 * LCP registration banner with WebP when available.
 *
 * Expects:
 *   $headerImage (string) — fallback PNG/JPEG URL
 *   $headerImageWebp (string|null) — optional WebP URL
 *   $bannerAlt (string|null)
 */
$bannerSrc = (string) ($headerImage ?? '');
$bannerWebp = trim((string) ($headerImageWebp ?? ''));
$bannerAlt = (string) ($bannerAlt ?? 'Tatak Ormoc registration banner');
$hBanner = static function (string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<div class="hero-banner">
  <div class="nom-banner-wrap">
    <?php if ($bannerWebp !== ''): ?>
    <picture>
      <source srcset="<?php echo $hBanner($bannerWebp); ?>" type="image/webp">
      <img
        src="<?php echo $hBanner($bannerSrc); ?>"
        alt="<?php echo $hBanner($bannerAlt); ?>"
        class="nom-banner-img"
        width="1600"
        height="640"
        decoding="async"
        fetchpriority="high"
      >
    </picture>
    <?php else: ?>
    <img
      src="<?php echo $hBanner($bannerSrc); ?>"
      alt="<?php echo $hBanner($bannerAlt); ?>"
      class="nom-banner-img"
      width="1600"
      height="640"
      decoding="async"
      fetchpriority="high"
    >
    <?php endif; ?>
  </div>
</div>
