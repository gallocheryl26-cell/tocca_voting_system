<?php
declare(strict_types=1);

require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/choice_token.php';
require_once __DIR__ . '/../tocca_admin/qr_url.php';
require_once __DIR__ . '/../tocca_admin/qr_utils.php';

$token = trim((string)($_GET['t'] ?? ''));
$row = $token !== '' ? choice_token_lookup($conn, $token) : null;

if (!$row) {
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Portal unavailable | Tatak Ormoc</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh;">
  <div class="container py-5">
    <div class="mx-auto text-center" style="max-width:520px;">
      <h1 class="h3 mb-3">Link not found</h1>
      <p class="text-muted mb-0">This nominee portal link is invalid or has expired. Please contact the TOCCA team for assistance.</p>
    </div>
  </div>
</body>
</html>
    <?php
    exit;
}

$choiceId   = (int)$row['choice_id'];
$choiceName = (string)$row['choice_name'];
$voteUrl    = qr_vote_url_for_choice($choiceId, $conn);
$portalUrl  = qr_portal_url_for_token($token, $conn);

$filenameSafe = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $choiceName));
$posterAbs    = __DIR__ . '/../tocca_admin/qrcodes/' . $filenameSafe . '.png';
$posterReady  = is_file($posterAbs);
$downloadBase = 'download.php?t=' . rawurlencode($token);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($choiceName) ?> | TOCCA Nominee Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    body { background: linear-gradient(180deg, #eff6ff 0%, #f8fafc 40%); min-height: 100vh; }
    .hero-card { border: 0; border-radius: 1rem; box-shadow: 0 20px 45px -28px rgba(15,23,42,.45); }
    .preview-box { background: #fff; border: 1px dashed #cbd5e1; border-radius: .75rem; min-height: 220px; display:flex; align-items:center; justify-content:center; padding: 1rem; }
    .preview-box img { max-width: 100%; max-height: 360px; object-fit: contain; }
    .asset-btn { text-align: left; }
  </style>
</head>
<body>
  <div class="container py-4 py-md-5" style="max-width:920px;">
    <div class="text-center mb-4">
      <div class="text-uppercase small text-primary fw-semibold mb-1">Tatak Ormoc Consumers' Choice Awards</div>
      <h1 class="h3 mb-1">Nominee QR Portal</h1>
      <p class="text-muted mb-0"><?= h($choiceName) ?></p>
    </div>

    <div class="card hero-card mb-4">
      <div class="card-body p-4">
        <div class="row g-4 align-items-center">
          <div class="col-md-6">
            <div class="preview-box">
              <?php if ($posterReady): ?>
                <img src="<?= h($downloadBase . '&type=poster&inline=1') ?>" alt="QR poster for <?= h($choiceName) ?>">
              <?php else: ?>
                <div class="text-center text-muted">
                  <i class="bi bi-hourglass-split fs-2 d-block mb-2"></i>
                  Poster not generated yet.<br>
                  <span class="small">Ask admin to run Generate QR, then refresh this page.</span>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-md-6">
            <h2 class="h5 mb-3">Your materials</h2>
            <p class="text-muted small">Download, print, and display these at your entrance or counter so customers can scan and vote.</p>

            <div class="d-grid gap-2 mb-4">
              <a class="btn btn-primary asset-btn" href="<?= h($downloadBase . '&type=poster') ?>" <?= $posterReady ? '' : 'aria-disabled="true" tabindex="-1" style="pointer-events:none;opacity:.55;"' ?>>
                <i class="bi bi-file-earmark-image me-2"></i> Download poster (A4-ready)
              </a>
              <a class="btn btn-outline-primary asset-btn" href="<?= h($downloadBase . '&type=sticker') ?>">
                <i class="bi bi-qr-code me-2"></i> Download QR sticker (square)
              </a>
              <a class="btn btn-outline-primary asset-btn" href="<?= h($downloadBase . '&type=social_poster') ?>">
                <i class="bi bi-instagram me-2"></i> Download social square poster
              </a>
              <a class="btn btn-outline-secondary asset-btn" href="<?= h($downloadBase . '&type=qr') ?>">
                <i class="bi bi-download me-2"></i> Download QR code only
              </a>
            </div>

            <label class="form-label small fw-semibold">Direct voting link</label>
            <div class="input-group">
              <input type="text" class="form-control form-control-sm" id="voteLink" readonly value="<?= h($voteUrl) ?>">
              <button class="btn btn-outline-secondary btn-sm" type="button" id="copyBtn"><i class="bi bi-clipboard"></i> Copy</button>
            </div>
            <div class="form-text">Share this link if email attachments do not work.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <h2 class="h6 mb-2">Quick tips</h2>
        <ul class="small text-muted mb-0 ps-3">
          <li>Print the poster at A4 and post it where customers can easily scan.</li>
          <li>Use the sticker or social square for counters and social media.</li>
          <li>Bookmark this page — you can come back anytime to re-download your QR.</li>
        </ul>
      </div>
    </div>

    <p class="text-center text-muted small mt-4 mb-0">Portal link: <?= h($portalUrl) ?></p>
  </div>

  <script>
    document.getElementById('copyBtn')?.addEventListener('click', async () => {
      const input = document.getElementById('voteLink');
      if (!input) return;
      try {
        await navigator.clipboard.writeText(input.value);
        const btn = document.getElementById('copyBtn');
        btn.innerHTML = '<i class="bi bi-check2"></i> Copied';
        setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy'; }, 1800);
      } catch (e) {
        input.select();
        document.execCommand('copy');
      }
    });
  </script>
</body>
</html>
