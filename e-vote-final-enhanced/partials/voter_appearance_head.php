<?php
declare(strict_types=1);

/**
 * Server-rendered voter appearance (page backdrop + content text only).
 */
if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

require_once dirname(__DIR__, 2) . '/tocca_admin/includes/voter_appearance.php';

if (!voter_appearance_is_configured($conn)) {
    return;
}

$appearance = voter_appearance_load($conn);
$bgColor    = $appearance['bgColor'];
$textColor  = $appearance['textColor'];
$headerLogo = $appearance['headerLogo'];
?>
<style id="voter-appearance-inline">
/* Outside the white ballot card */
body.voter-page,
body.voter-page--home,
body.voter-page--thanks {
  --voter-appearance-bg: <?php echo h($bgColor); ?>;
  --voter-appearance-text: <?php echo h($textColor); ?>;
  background: var(--voter-appearance-bg) !important;
  background-image: none !important;
  background-attachment: fixed;
}

/* Blue hero band keeps white text (do not use admin text color here) */
body.voter-page .voter-hero,
body.voter-page .voter-hero h1,
body.voter-page .voter-hero h2,
body.voter-page .voter-hero p {
  color: #fff !important;
}
body.voter-page .voter-hero p {
  color: rgba(255, 255, 255, 0.92) !important;
}

/* Main white content area + footer inside the card */
body.voter-page .voter-content,
body.voter-page .voter-footer {
  color: var(--voter-appearance-text);
}
</style>
<script>
window.__voterAppearance = <?php echo json_encode([
    'bgColor'    => $bgColor,
    'textColor'  => $textColor,
    'headerLogo' => $headerLogo,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
