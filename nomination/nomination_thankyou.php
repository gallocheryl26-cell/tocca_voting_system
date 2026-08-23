<?php
// nomination_thankyou.php
declare(strict_types=1);
session_start();

// POST/Redirect/GET: land on thank-you with ref in the URL (bookmarkable, safe refresh)
$refFromGet = trim((string)($_GET['ref'] ?? ''));
if ($refFromGet === '' && !empty($_SESSION['last_nom_ref'])) {
  header('Location: nomination_thankyou.php?ref=' . rawurlencode((string)$_SESSION['last_nom_ref']), true, 303);
  exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* Optional branding includes */
$adminGetLogo = dirname(__DIR__) . '/tocca_admin/get_logo.php';
if (file_exists($adminGetLogo)) {
  require_once $adminGetLogo;
}
if (!function_exists('qr_tracking_url_with_ref') && is_file(dirname(__DIR__) . '/tocca_admin/qr_url.php')) {
  require_once dirname(__DIR__) . '/tocca_admin/qr_url.php';
}

if (empty($faviconPath))          $faviconPath = 'favicon.png';
if (empty($nominationBannerPath)) $nominationBannerPath = 'img/default-banner.png';
$headerImage = $nominationBannerPath;
$headerImageWebp = $nominationBannerWebpPath ?? null;
$bodyBg      = $nominationBgColor ?? '#f8f9fa';

$nomination_id = (int)($_SESSION['last_nomination_id'] ?? 0);
$event_id      = (int)($_SESSION['last_event_id']      ?? 0);

$reference = function_exists('tocca_normalize_reference')
  ? tocca_normalize_reference($refFromGet)
  : $refFromGet;
if (isset($_SESSION['last_nom_ref'])) {
  unset($_SESSION['last_nom_ref']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Registration Submitted | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($faviconPath, ENT_QUOTES) ?>">
  <?php
    $nomPerfIconCss = 'fa';
    $nomPerfPreload = ($headerImageWebp ?: $headerImage);
    $nomPerfPreloadType = $headerImageWebp ? 'image/webp' : '';
    require __DIR__ . '/partials/nom_perf_head.php';
  ?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="nomination_form.css?v=<?= (int)(@filemtime(__DIR__ . '/nomination_form.css') ?: time()) ?>">
  <style>
    body { min-height:100vh; display:flex; flex-direction:column; --voter-bg: <?= htmlspecialchars((string)$bodyBg, ENT_QUOTES) ?>; }

    .success-card {
      max-width: 640px;
      margin: 0 auto;
      border: 1px solid var(--tocca-border);
      border-radius: var(--tocca-radius);
      box-shadow: var(--tocca-shadow);
      background: var(--tocca-surface);
      overflow: hidden;
    }
    .success-hero {
      background: linear-gradient(135deg, rgba(25,135,84,0.10), rgba(30,64,175,0.10));
      padding: 2.25rem 1.5rem 1.5rem;
      text-align: center;
      border-bottom: 1px solid var(--tocca-border);
    }
    .success-ring {
      width: 96px; height: 96px;
      border-radius: 50%;
      background: #ffffff;
      box-shadow:
        0 0 0 6px rgba(25,135,84,0.10),
        0 8px 24px rgba(25,135,84,0.18);
      display: inline-flex; align-items: center; justify-content: center;
      margin-bottom: 0.85rem;
      animation: pop .35s ease-out;
    }
    .success-ring i {
      font-size: 3rem;
      color: #198754;
    }
    @keyframes pop {
      0%   { transform: scale(0.6); opacity: 0; }
      80%  { transform: scale(1.06); opacity: 1; }
      100% { transform: scale(1); }
    }

    .success-title {
      font-weight: 800;
      letter-spacing: -0.015em;
      color: var(--tocca-navy);
      font-size: clamp(1.4rem, 3vw, 1.85rem);
      margin-bottom: 0.4rem;
    }
    .success-sub {
      color: var(--tocca-text-muted);
      font-size: 1rem;
      margin: 0 auto;
      max-width: 48ch;
      line-height: 1.55;
    }

    .reference-strip {
      margin: 1.25rem auto 0;
      padding: 0.75rem 0.9rem;
      max-width: 360px;
      border: 1px dashed rgba(30,64,175,0.35);
      background: rgba(30,64,175,0.05);
      border-radius: 0.65rem;
      display: flex; align-items: center; justify-content: space-between;
      gap: 0.5rem;
    }
    .reference-strip .label {
      font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase;
      color: var(--tocca-primary); font-weight: 700;
    }
    .reference-strip .value {
      font-family: ui-monospace, "SF Mono", Menlo, monospace;
      font-weight: 700; color: var(--tocca-navy);
    }
    .reference-strip .copy-btn {
      border: none; background: transparent; color: var(--tocca-primary);
      font-size: 0.9rem; cursor: pointer; padding: 0.25rem 0.5rem; border-radius: 0.4rem;
    }
    .reference-strip .copy-btn:hover { background: rgba(30,64,175,0.08); }

    .next-steps {
      padding: 1.5rem;
    }
    .next-steps ol {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    .next-steps li {
      display: grid;
      grid-template-columns: 36px 1fr;
      gap: 0.75rem;
      align-items: flex-start;
      padding: 0.6rem 0;
      border-bottom: 1px dashed var(--tocca-border);
    }
    .next-steps li:last-child { border-bottom: none; }
    .next-steps .step-num {
      width: 28px; height: 28px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--tocca-primary), var(--tocca-primary-soft));
      color: #fff; font-weight: 700;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 0.85rem;
    }
    .next-steps .step-title { font-weight: 700; color: var(--tocca-text); }
    .next-steps .step-desc  { color: var(--tocca-text-muted); font-size: 0.9rem; }

    .action-row {
      padding: 0 1.5rem 1.5rem;
      display: flex; flex-wrap: wrap; gap: 0.6rem; justify-content: center;
    }

    header.bg-white { background: transparent !important; }

    .feedback-prompt {
      max-width: 640px;
      margin: 1rem auto 0;
      background: rgba(30,64,175,0.05);
      border: 1px solid var(--tocca-border);
      border-radius: var(--tocca-radius);
      padding: 0.85rem 1rem;
      display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
    }
    .feedback-prompt .fb-text { flex: 1 1 220px; color: var(--tocca-text); font-size: 0.95rem; }
  </style>
</head>
<body class="nomination-thankyou-page">

  <header class="nom-page-header">
    <?php require __DIR__ . '/partials/nom_banner.php'; ?>
  </header>

  <main class="flex-grow-1 my-4 pb-2">
    <div class="container">
      <div class="success-card">
        <div class="success-hero">
          <div class="success-ring">
            <i class="fa-solid fa-circle-check"></i>
          </div>
          <h1 class="success-title">Registration Received!</h1>
          <p class="success-sub">
            Thank you for registering your business. Our team will review your submission and notify you via email once the verification process is complete.
          </p>

          <?php if ($reference !== ''): ?>
            <div class="reference-strip">
              <div>
                <div class="label">Reference</div>
                <div class="value" id="refValue"><?= htmlspecialchars($reference, ENT_QUOTES) ?></div>
              </div>
              <button type="button" class="copy-btn" id="copyRefBtn" title="Copy reference">
                <i class="fa-regular fa-copy"></i> Copy
              </button>
            </div>
          <?php endif; ?>
        </div>

        <div class="next-steps">
          <h2 class="h6 text-uppercase fw-bold text-muted mb-3" style="letter-spacing:.08em;">What happens next?</h2>
          <ol>
            <li>
              <span class="step-num">1</span>
              <div>
                <div class="step-title">Review &amp; Verification</div>
                <div class="step-desc">Our organizers verify your business details, permit, and uploaded media.</div>
              </div>
            </li>
            <li>
              <span class="step-num">2</span>
              <div>
                <div class="step-title">Email Confirmation</div>
                <div class="step-desc">We email your reference number as a receipt. You will also hear from us if we need more information, and when your business is confirmed for public voting (QR code and voting link).</div>
              </div>
            </li>
            <li>
              <span class="step-num">3</span>
              <div>
                <div class="step-title">Public Voting</div>
                <div class="step-desc">Businesses confirmed for public voting appear on the Tatak Ormoc voting site for the public to choose.</div>
              </div>
            </li>
          </ol>
        </div>

        <div class="action-row">
          <a href="<?php echo htmlspecialchars(function_exists('qr_tracking_url_with_ref') ? qr_tracking_url_with_ref($GLOBALS['conn'] ?? null, $reference) : ('/track' . ($reference !== '' ? '?ref=' . rawurlencode($reference) : '')), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">
            <i class="fa-solid fa-location-dot me-1"></i> Track My Registration
          </a>
        </div>
      </div>

      <div class="feedback-prompt">
        <i class="fa-regular fa-comment-dots fa-lg text-primary"></i>
        <div class="fb-text">
          <strong>How was your experience?</strong>
          A quick note helps us improve the registration process.
        </div>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#feedbackModal">
          Share Feedback
        </button>
      </div>
    </div>
  </main>

  <!-- Feedback Modal (now opt-in instead of auto-popup) -->
  <div class="modal fade" id="feedbackModal" tabindex="-1" aria-labelledby="feedbackModalLabel">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form id="feedbackForm" autocomplete="off">
          <div class="modal-header">
            <h5 class="modal-title" id="feedbackModalLabel">
              <i class="fa-regular fa-comment-dots me-1"></i> Share Your Feedback
            </h5>
          </div>

          <div class="modal-body">
            <textarea
              class="form-control"
              name="feedback"
              rows="5"
              placeholder="Share your experience with the registration process…"
              required></textarea>

            <fieldset class="border rounded p-3 mt-3">
              <legend class="float-none w-auto px-2 fs-6 fw-semibold">Sender visibility</legend>
              <div class="form-check mb-1">
                <input class="form-check-input" type="radio" name="fbVisibility" id="fbAnon" value="1" checked>
                <label class="form-check-label" for="fbAnon">Submit as <strong>Anonymous</strong></label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="fbVisibility" id="fbShow" value="0">
                <label class="form-check-label" for="fbShow">Show my <strong>establishment name</strong></label>
              </div>
              <div class="text-muted small mt-2">
                This only affects how your feedback appears publicly. It does not impact your registration.
              </div>
            </fieldset>

<?php if ($nomination_id <= 0): ?>
            <div class="alert alert-warning small mt-3 mb-0">
              <i class="fa-solid fa-triangle-exclamation me-1"></i>
              Registration ID not found in this session. Please submit a registration first.
            </div>
<?php endif; ?>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
              <i class="fa-regular fa-paper-plane me-1"></i> Submit Feedback
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/partials/site_footer.php'; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    // Keep thank-you as the history entry after submit (paired with location.replace on the form)
    if (window.history.replaceState) {
      history.replaceState({ nominationThankYou: true }, '', location.href);
    }

    // Copy reference number
    const copyBtn = document.getElementById('copyRefBtn');
    const refVal  = document.getElementById('refValue');
    if (copyBtn && refVal) {
      copyBtn.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(refVal.textContent.trim());
          const original = copyBtn.innerHTML;
          copyBtn.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
          setTimeout(() => { copyBtn.innerHTML = original; }, 1500);
        } catch (_) { /* clipboard blocked */ }
      });
    }

    // Feedback submission
    const fbForm = document.getElementById('feedbackForm');
    if (!fbForm) return;
    fbForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const ta = document.querySelector('#feedbackModal textarea[name="feedback"]');
      const feedback = ta.value.trim();
      if (!feedback) return;

      const isAnon = document.querySelector('input[name="fbVisibility"]:checked')?.value ?? '1';

      const params = new URLSearchParams();
      params.set('feedback', feedback);
      params.set('is_anonymous', isAnon);
      params.set('nomination_id', '<?= (int)$nomination_id ?>');
      params.set('event_id',      '<?= (int)$event_id ?>');

      fetch('save_feedback_nomination.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
        credentials: 'same-origin'
      })
      .then(r => r.text())
      .then(txt => {
        if (txt.trim() === 'success') {
          bootstrap.Modal.getInstance(document.getElementById('feedbackModal')).hide();
          const toast = document.createElement('div');
          toast.className = 'alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3 shadow';
          toast.style.zIndex = 2000;
          toast.innerHTML = '<i class="fa-solid fa-check-circle me-2"></i> Thank you for your feedback!';
          document.body.appendChild(toast);
          setTimeout(() => toast.remove(), 2500);
          ta.value = '';
        } else {
          alert('Could not save feedback: ' + txt);
        }
      })
      .catch(() => alert('Network error – please try again.'));
    });
  });
  </script>
</body>
</html>
