<?php
declare(strict_types=1);

/**
 * Admin-only live preview frame for Voter Portal settings (same session as admin UI).
 */
require_once __DIR__ . '/includes/admin_init.php';
require_once __DIR__ . '/includes/voter_portal_copy.php';
require_once __DIR__ . '/includes/voter_appearance.php';
require_once dirname(__DIR__) . '/nomination/rich_text_helpers.php';

if (!($_SESSION['loggedin'] ?? false)) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unauthorized — please log in to the admin portal again.';
    exit;
}

$activeEvent = admin_get_active_event($conn);
$eventId     = $activeEvent ? (int) ($activeEvent['event_id'] ?? 0) : 0;
$copy        = voter_portal_copy_load($conn, $eventId);
$appearance  = voter_appearance_load($conn);

$introTitleHtml = htmlspecialchars((string) $copy['intro_title'], ENT_QUOTES, 'UTF-8');
$introBodyHtml  = voter_portal_copy_intro_html($copy);
$howToTitleHtml = htmlspecialchars((string) $copy['how_to_title'], ENT_QUOTES, 'UTF-8');
$howToLeadHtml  = htmlspecialchars((string) $copy['how_to_lead'], ENT_QUOTES, 'UTF-8');
$footerNoteHtml = htmlspecialchars((string) $copy['footer_note'], ENT_QUOTES, 'UTF-8');
$headerLogo     = $appearance['headerLogo'];
$bgColor        = $appearance['bgColor'];
$textColor      = $appearance['textColor'];

$voterBase = '../e-vote-final-enhanced/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Voter portal preview</title>
  <base href="<?php echo h($voterBase); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link rel="stylesheet" href="css/user-style.css" />
  <link rel="stylesheet" href="css/voter-layout.css" />
  <link rel="stylesheet" href="css/voter-components.css" />
  <style id="voter-appearance-inline">
    body.voter-page,
    body.voter-page--home {
      --voter-appearance-bg: <?php echo h($bgColor); ?>;
      --voter-appearance-text: <?php echo h($textColor); ?>;
      background: var(--voter-appearance-bg) !important;
      background-image: none !important;
      background-attachment: fixed;
    }
    body.voter-page .voter-hero,
    body.voter-page .voter-hero h1,
    body.voter-page .voter-hero h2,
    body.voter-page .voter-hero p {
      color: #fff !important;
    }
    body.voter-page .voter-hero p {
      color: rgba(255, 255, 255, 0.92) !important;
    }
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
</head>
<body class="voter-page voter-page--home voter-admin-preview">
<div class="voter-admin-preview-banner" role="status">Live preview — unsaved edits show here before you save</div>

<div class="modal fade voter-modal" id="introModal" tabindex="-1" aria-labelledby="introModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content overflow-hidden">
      <div class="modal-header">
        <h5 class="modal-title" id="introModalLabel"><?php echo $introTitleHtml; ?></h5>
      </div>
      <div class="modal-body">
        <div id="introModalCopy"><?php echo $introBodyHtml; ?></div>
        <div class="intro-consent-box">
          <p class="mb-2 fw-semibold">Please review and acknowledge before proceeding:</p>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" value="" id="agreeTerms" disabled />
            <label class="form-check-label" for="agreeTerms">Terms and Conditions (preview)</label>
          </div>
          <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" value="" id="agreePrivacy" disabled />
            <label class="form-check-label" for="agreePrivacy">Privacy Policy (preview)</label>
          </div>
        </div>
      </div>
      <div class="modal-footer modal-footer-custom justify-content-end">
        <button type="button" class="btn btn-tocca-close disabled" disabled>Close</button>
      </div>
    </div>
  </div>
</div>

<div id="vpcPreviewBallotView" class="voter-shell main-container d-none" aria-hidden="true">
  <header class="voter-header">
    <img id="headerLogoBallot" src="<?php echo h($headerLogo); ?>" alt="" class="header-logo" />
  </header>
  <?php $voterStep = 1; include __DIR__ . '/../e-vote-final-enhanced/partials/voter_steps.php'; ?>
  <section class="voter-hero">
    <h1>Category</h1>
    <p>Preview: voters pick a category, then vote on each award, then review the summary.</p>
  </section>
  <section class="voter-content">
    <p class="text-muted text-center py-4 mb-0">Category grid loads after mobile verification (not shown in preview).</p>
  </section>
</div>

<div id="vpcPreviewLandingView" class="voter-shell main-container">
  <header class="voter-header">
    <img id="headerLogo" src="<?php echo h($headerLogo); ?>" alt="TOCCA Header Image" class="header-logo" />
  </header>

  <section class="voter-hero">
    <h1><?php echo $howToTitleHtml; ?></h1>
    <p><?php echo $howToLeadHtml; ?></p>
  </section>

  <section class="voter-content">
    <ol class="voter-instruction-steps">
      <?php foreach ($copy['steps'] as $idx => $step): ?>
      <li class="voter-instruction-step">
        <span class="step-num" aria-hidden="true"><?php echo (int) $idx + 1; ?></span>
        <div class="step-body">
          <strong><?php echo htmlspecialchars((string) $step['title'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if ((string) ($step['body'] ?? '') !== ''): ?> — <?php echo htmlspecialchars((string) $step['body'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
    <p class="voter-instruction-note"><?php echo $footerNoteHtml; ?></p>
    <button type="button" class="proceed-button btn-voter-primary" aria-hidden="true" tabindex="-1">
      <i class="fa-solid fa-arrow-right" aria-hidden="true"></i> Proceed to Voting
    </button>
  </section>

</div><!-- /vpcPreviewLandingView -->

<?php include dirname(__DIR__) . '/e-vote-final-enhanced/partials/voter_footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="apply_voter_style.js"></script>
<script>window.TOCCA_ADMIN_PREVIEW = true;</script>
<script src="js/voter_admin_preview.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.body.classList.add('voter-appearance-custom');
  if (window.__voterAppearance && typeof window.applyVoterAppearance === 'function') {
    window.applyVoterAppearance(window.__voterAppearance);
  }
});
</script>
</body>
</html>
