<?php
declare(strict_types=1);
if (!function_exists('tocca_voter_public_url')) {
    require_once dirname(__DIR__) . '/lib/voter_redirect.php';
}
$year = date('Y');
$privacyUrl = $privacyUrl ?? tocca_voter_public_url('privacy_policy.php');
$termsUrl = $termsUrl ?? tocca_voter_public_url('terms_and_conditions.php');
$stiLogoFs = dirname(__DIR__, 2) . '/nomination/img/sti-college.png';
$stiLogoUrl = $stiLogoUrl ?? '../nomination/img/sti-college.png';
?>
<footer class="site-footer" role="contentinfo">
  <div class="site-footer-inner">
    <p class="site-footer-copy">&copy; <?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?> Tatak Ormoc Consumers&rsquo; Choice Awards</p>
    <p class="site-footer-powered">
      <span>Powered by STI College Ormoc</span>
      <?php if (is_file($stiLogoFs)): ?>
        <img src="<?= htmlspecialchars($stiLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="STI College" class="site-footer-powered-logo" width="72" height="32">
      <?php endif; ?>
    </p>
    <nav class="site-footer-nav" aria-label="Legal links">
      <a href="<?= htmlspecialchars($privacyUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
      <span class="site-footer-sep" aria-hidden="true">·</span>
      <a href="<?= htmlspecialchars($termsUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Terms &amp; Conditions</a>
    </nav>
  </div>
</footer>
<script>
if (typeof window.toccaVoterGo !== 'function') {
  window.toccaVoterGo = function (page) {
    window.location.href = (typeof window.toccaVoterUrl === 'function') ? window.toccaVoterUrl(page) : page;
  };
}
</script>
