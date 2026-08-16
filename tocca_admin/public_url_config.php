<?php
declare(strict_types=1);

/**
 * Site root + portal share links only.
 * Establishment vote links live in File Maintenance → Establishments (same URL as QR).
 * Docs: docs/HOSTING_PUBLIC_URLS.md
 */
require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));
require_once __DIR__ . '/qr_url.php';

function public_url_config_set(mysqli $conn, string $key, string $value): bool
{
    $stmt = $conn->prepare(
        'INSERT INTO tbl_config (config_key, config_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    if (function_exists('config_invalidate_cache')) {
        config_invalidate_cache();
    }
    return $ok;
}

function public_url_config_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash_toast'] = ['message' => $message, 'type' => $type];
}

function public_url_config_redirect(): never
{
    header('Location: public_url_config.php');
    exit;
}

$filePublicSite = qr_normalize_site_root((string) (tocca_config('public_site_url') ?? ''));
$fileLocked = $filePublicSite !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_public_site_root'])) {
    if ($fileLocked) {
        public_url_config_flash(
            'Site root is locked by config.local.php (public_site_url). Edit that file on the server to change it.',
            'warning'
        );
        public_url_config_redirect();
    }

    $raw = trim((string) ($_POST['public_site_root'] ?? ''));
    $url = qr_normalize_site_root($raw);
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        public_url_config_flash('Enter a valid http(s) URL, or leave blank to auto-detect.', 'danger');
        public_url_config_redirect();
    }

    $ok1 = public_url_config_set($conn, 'voting_qr_base_url', $url);
    $ok2 = public_url_config_set($conn, 'nomination_qr_base_url', $url);
    if ($ok1 && $ok2) {
        public_url_config_flash(
            $url === ''
                ? 'Site root cleared. Short links will auto-detect until you set it again.'
                : 'Site root saved. Next: open Businesses and click Regenerate All QR Codes so posters match the new links.',
            'success'
        );
    } else {
        public_url_config_flash('Could not save site root.', 'danger');
    }
    public_url_config_redirect();
}

$resolvedRoot = qr_voting_base_url($conn);
$dbRoot = qr_normalize_site_root((string) getConfig('voting_qr_base_url', ''));
if ($dbRoot === '') {
    $dbRoot = qr_normalize_site_root((string) getConfig('nomination_qr_base_url', ''));
}

$voteUrl = qr_vote_portal_url($conn);
$registerUrl = qr_nomination_form_url($conn);
$trackUrl = qr_tracking_url($conn);

$flash = $_SESSION['flash_toast'] ?? null;
if (is_array($flash)) {
    unset($_SESSION['flash_toast']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <title>Public Share Links | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars((string) ($faviconPath ?? ''), ENT_QUOTES); ?>">
  <link href="css/styles.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <?php include 'inline_style.php'; ?>
</head>
<body class="sb-nav-fixed">
<?php include __DIR__ . '/partials/admin_topnav.php'; ?>
<div id="layoutSidenav">
  <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>
  <div id="layoutSidenav_content">
    <main>
      <div class="container-fluid px-4">
        <div class="admin-page-header mt-4 mb-4">
          <div class="min-w-0">
            <h1 class="admin-page-title mb-2">Public Share Links</h1>
            <?php echo render_customizations_breadcrumb([['label' => 'Public Share Links']]); ?>
          </div>
        </div>

        <div class="alert alert-primary border mb-4">
          <div class="fw-semibold mb-1">What this page is for</div>
          <p class="mb-0 small">
            Set the hosting <strong>site root</strong> and copy portal links (<code>/vote/</code>, <code>/register/</code>, <code>/track/</code>).
            Per-business vote links and QR codes are managed in
            <a href="choices.php" class="fw-semibold">File Maintenance → Businesses</a>
            (Copy and QR use the same URL).
          </p>
        </div>

        <div class="row g-4 mb-4">
          <div class="col-lg-5">
            <div class="card h-100">
              <div class="card-header fw-semibold"><i class="bi bi-globe2 me-1"></i> Site root (hosting domain)</div>
              <div class="card-body">
                <form method="POST" class="mb-0">
                  <input type="hidden" name="save_public_site_root" value="1">
                  <label for="public_site_root" class="form-label fw-semibold">Site root URL</label>
                  <input
                    type="url"
                    class="form-control mb-2"
                    id="public_site_root"
                    name="public_site_root"
                    placeholder="https://tatakormocawards.com"
                    value="<?php echo htmlspecialchars($fileLocked ? $filePublicSite : ($dbRoot !== '' ? $dbRoot : $resolvedRoot), ENT_QUOTES); ?>"
                    <?php echo $fileLocked ? 'readonly' : ''; ?>
                    inputmode="url"
                    autocomplete="url"
                    spellcheck="false"
                  >
                  <div class="form-text mb-3">
                    No trailing slash. Production example: <code>https://tatakormocawards.com</code>.
                    Currently used: <code><?php echo htmlspecialchars($resolvedRoot, ENT_QUOTES); ?></code>
                    <?php if ($fileLocked): ?>
                      <br><span class="text-warning">Locked by <code>config.local.php</code> → <code>public_site_url</code>.</span>
                    <?php endif; ?>
                  </div>
                  <button type="submit" class="btn btn-primary" <?php echo $fileLocked ? 'disabled' : ''; ?>>
                    <i class="fas fa-save me-1"></i> Save site root
                  </button>
                </form>
              </div>
            </div>
          </div>

          <div class="col-lg-7">
            <div class="card h-100">
              <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-1"></i> Portal links</div>
              <div class="card-body">
                <?php
                $coreLinks = [
                    ['label' => 'Voting page', 'url' => $voteUrl],
                    ['label' => 'Registration', 'url' => $registerUrl],
                    ['label' => 'Tracking', 'url' => $trackUrl],
                ];
                foreach ($coreLinks as $item):
                ?>
                  <label class="form-label fw-semibold mb-1"><?php echo htmlspecialchars($item['label'], ENT_QUOTES); ?></label>
                  <div class="input-group input-group-sm mb-3">
                    <a class="form-control text-break" href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($item['url'], ENT_QUOTES); ?></a>
                    <button type="button" class="btn btn-outline-secondary copy-public-url" data-url="<?php echo htmlspecialchars($item['url'], ENT_QUOTES); ?>" title="Copy">
                      <i class="bi bi-clipboard"></i>
                    </button>
                  </div>
                <?php endforeach; ?>
                <div class="alert alert-light border small mb-0">
                  Business vote links (e.g. <code><?php echo htmlspecialchars(rtrim($resolvedRoot, '/') . '/vote/gemma-s-store/', ENT_QUOTES); ?></code>)
                  are on <a href="choices.php" class="fw-semibold">Businesses</a> — Vote Link → Copy, and QR uses that same URL.
                  After changing the site root, click <strong>Regenerate All QR Codes</strong> there.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
    <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/scripts.js"></script>
<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 1080;">
  <div id="globalToast" class="toast align-items-center border-0 mx-auto" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="globalToastBody"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>
<script>
(function () {
  function showToast(message, type) {
    var toastEl = document.getElementById('globalToast');
    var bodyEl = document.getElementById('globalToastBody');
    if (!toastEl || !bodyEl || !window.bootstrap) return;
    var map = { success: 'text-bg-success', danger: 'text-bg-danger', warning: 'text-bg-warning', info: 'text-bg-info' };
    toastEl.className = 'toast align-items-center border-0 mx-auto ' + (map[type] || map.success);
    bodyEl.textContent = message;
    bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4500 }).show();
  }
  document.querySelectorAll('.copy-public-url').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-url') || '';
      if (!url) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () { showToast('Copied', 'success'); })
          .catch(function () { showToast('Could not copy', 'danger'); });
      } else {
        showToast('Clipboard unavailable', 'warning');
      }
    });
  });
  <?php if (is_array($flash) && !empty($flash['message'])): ?>
  document.addEventListener('DOMContentLoaded', function () {
    showToast(<?php echo json_encode((string) $flash['message']); ?>, <?php echo json_encode((string) ($flash['type'] ?? 'success')); ?>);
  });
  <?php endif; ?>
})();
</script>
</body>
</html>
