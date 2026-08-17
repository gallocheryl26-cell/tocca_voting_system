<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));
require_once __DIR__ . '/audit_log.php';

function nom_settings_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash_toast'] = ['message' => $message, 'type' => $type];
}

function nom_settings_redirect(string $hash = ''): never
{
    $location = 'nomination_settings.php';
    if ($hash !== '') {
        $location .= str_starts_with($hash, '#') ? $hash : '#' . $hash;
    }
    session_write_close();
    header('Location: ' . $location);
    exit;
}

function nom_sanitize_hex(string $input, string $fallback = '#ffffff'): string
{
    $input = trim($input);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $input)) {
        return $input;
    }
    if (preg_match('/^#[0-9a-fA-F]{3}$/', $input)) {
        return $input;
    }

    return $fallback;
}

function nom_validate_base_url(string $raw): ?string
{
    require_once __DIR__ . '/qr_url.php';
    $url = qr_normalize_site_root($raw);
    if ($url === '') {
        return '';
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    return $url;
}

const NOM_BANNER_MAX_BYTES = 8 * 1024 * 1024;
const NOM_BANNER_MIN_WIDTH = 320;
const NOM_BANNER_MIN_HEIGHT = 60;
const NOM_BANNER_MAX_WIDTH = 6000;
const NOM_BANNER_MAX_HEIGHT = 3000;

/** @return list<string> Allowed MIME types for banner uploads */
function nom_banner_allowed_mimes(): array
{
    return ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
}

/**
 * Validate banner file upload. Returns null if valid, or an error message.
 *
 * @param array<string, mixed> $file
 */
function nom_validate_banner_file(array $file): ?string
{
    if (!isset($file['error'])) {
        return null;
    }

    $err = (int) $file['error'];
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($err !== UPLOAD_ERR_OK) {
        return match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Banner exceeds the server upload limit. Use a file under 8 MB.',
            UPLOAD_ERR_PARTIAL => 'Banner upload was interrupted. Please try again.',
            default => 'Banner upload failed. Please try again.',
        };
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return 'Banner file is empty.';
    }
    if ($size > NOM_BANNER_MAX_BYTES) {
        return 'Banner must be 8 MB or smaller.';
    }

    $originalName = basename((string) ($file['name'] ?? ''));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return 'Banner must be JPG, PNG, GIF, or WebP.';
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return 'Invalid banner upload.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if (!in_array($mime, nom_banner_allowed_mimes(), true)) {
        return 'Banner file type is not allowed. Use JPG, PNG, GIF, or WebP.';
    }

    $dims = @getimagesize($tmp);
    if ($dims === false) {
        return 'Uploaded file is not a valid image.';
    }

    $width = (int) ($dims[0] ?? 0);
    $height = (int) ($dims[1] ?? 0);
    if ($width < NOM_BANNER_MIN_WIDTH || $height < NOM_BANNER_MIN_HEIGHT) {
        return 'Banner is too small. Use at least '
            . NOM_BANNER_MIN_WIDTH . '×' . NOM_BANNER_MIN_HEIGHT
            . ' pixels (recommended ~1600×300).';
    }
    if ($width > NOM_BANNER_MAX_WIDTH || $height > NOM_BANNER_MAX_HEIGHT) {
        return 'Banner dimensions are too large (maximum '
            . NOM_BANNER_MAX_WIDTH . '×' . NOM_BANNER_MAX_HEIGHT . ' pixels).';
    }

    return null;
}

/**
 * @param array<string, mixed> $file Validated $_FILES entry
 */
function nom_save_banner_file(array $file): ?string
{
    $targetDirAbs = __DIR__ . '/img/';
    $targetDirRel = 'img/';
    if (!is_dir($targetDirAbs)) {
        @mkdir($targetDirAbs, 0755, true);
    }

    $originalName = basename((string) ($file['name'] ?? 'banner.png'));
    $cleanName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $originalName);
    $uniqueFileName = 'nom_banner_' . time() . '_' . $cleanName;
    $targetAbs = $targetDirAbs . $uniqueFileName;
    $tmp = (string) $file['tmp_name'];

    if (move_uploaded_file($tmp, $targetAbs)) {
        return $targetDirRel . $uniqueFileName;
    }

    return null;
}

function nom_set_config(string $key, string $value): bool
{
    global $conn;
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

    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_nomination_settings'])) {
    $errors = [];

    $bgColor = nom_sanitize_hex((string) ($_POST['nomination_bg_color'] ?? '#f8f9fa'), '#f8f9fa');
    $textColor = nom_sanitize_hex((string) ($_POST['nomination_text_color'] ?? '#000000'), '#000000');

    $bannerFile = $_FILES['nomination_banner'] ?? null;
    if (is_array($bannerFile)) {
        $bannerError = nom_validate_banner_file($bannerFile);
        if ($bannerError !== null) {
            $errors[] = $bannerError;
        } elseif ((int) ($bannerFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $bannerPath = nom_save_banner_file($bannerFile);
            if (!$bannerPath || !nom_set_config('nominationBanner', $bannerPath)) {
                $errors[] = 'Could not save registration banner to disk.';
            }
        }
    }

    if ($errors !== []) {
        nom_settings_flash(implode(' ', $errors), 'danger');
        nom_settings_redirect('#registration-appearance');
    }

    $okBg = nom_set_config('nominationBgColor', $bgColor);
    $okText = nom_set_config('nominationTextColor', $textColor);

    if ($okBg && $okText) {
        audit_log($conn, 'nomination_settings', 'update', 'config', 'nomination_appearance', [
            'bg_color' => $bgColor,
            'text_color' => $textColor,
        ]);
        nom_settings_flash('Registration settings saved.', 'success');
    } else {
        nom_settings_flash('Some registration settings could not be saved.', 'warning');
    }
    nom_settings_redirect();
}

$nominationBannerPath = $nominationBannerPath ?? 'img/default-banner.png';
$nominationBgColor = $nominationBgColor ?? '#f8f9fa';
$nominationTextColor = getConfig('nominationTextColor', '#000000');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <title>Registration Settings | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($faviconPath ?? '', ENT_QUOTES); ?>">
  <link href="css/styles.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <?php include 'inline_style.php'; ?>
  <style>
    .ns-card {
      border: 1px solid rgba(15, 23, 42, .08);
      border-radius: 14px;
      background: #fff;
      box-shadow: 0 6px 24px -18px rgba(15, 23, 42, .35);
    }
    .ns-card-head {
      display: flex; align-items: center; justify-content: space-between; gap: 1rem;
      padding: 1rem 1.25rem; border-bottom: 1px solid rgba(15, 23, 42, .06);
    }
    .ns-card-head h5 { margin: 0; font-weight: 600; font-size: 1.05rem; }
    .ns-card-head .head-icon {
      width: 38px; height: 38px; border-radius: 10px; display: inline-flex;
      align-items: center; justify-content: center;
      background: rgba(37, 99, 235, .08); color: #2563eb;
    }
    .ns-card-body { padding: 1.25rem; }
    html.dark-mode .ns-card {
      background: #0b1220; border-color: rgba(148, 163, 184, .15);
    }
    html.dark-mode .ns-card-head { border-bottom-color: rgba(148, 163, 184, .15); }
    .preview-thumb {
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px dashed rgba(15, 23, 42, .15); border-radius: 10px;
      padding: .6rem; background: #f8fafc; min-height: 76px;
    }
    html.dark-mode .preview-thumb { background: #0f172a; border-color: rgba(148, 163, 184, .25); }
    #saveNominationSettingsBtn[aria-busy="true"] {
      opacity: 0.92;
      cursor: wait;
    }
    #nominationSettingsForm .is-invalid {
      border-color: var(--bs-form-invalid-border-color, #dc3545);
    }
  </style>
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
              <h1 class="admin-page-title mb-2">Registration Settings</h1>
              <?php echo render_customizations_breadcrumb([['label' => 'Registration Settings']]); ?>
            </div>
          </div>

          <div class="alert alert-light border small mb-3">
            <i class="fas fa-info-circle me-1 text-primary"></i>
            Customize the <strong>public registration form</strong> appearance (banner, colors).
            Form fields:
            <a href="nomination_fields.php" class="fw-semibold">File Maintenance &rarr; Registration Form</a>.
            Public share links (<code>/register</code>, <code>/vote</code>, <code>/track</code>):
            <a href="public_url_config.php" class="fw-semibold">Public Share Links</a>.
          </div>

          <form method="POST" action="nomination_settings.php" enctype="multipart/form-data"
                id="nominationSettingsForm" novalidate>
            <input type="hidden" name="save_nomination_settings" value="1">

            <div class="row g-3">
              <div class="col-12" id="registration-appearance">
                <div class="ns-card mb-3">
                  <div class="ns-card-head">
                    <h5>Appearance</h5>
                    <span class="head-icon"><i class="fas fa-paint-brush"></i></span>
                  </div>
                  <div class="ns-card-body">
                    <div class="mb-3">
                      <label class="form-label fw-bold">Current banner</label>
                      <div class="preview-thumb w-100">
                        <img src="<?php echo htmlspecialchars($nominationBannerPath, ENT_QUOTES); ?>"
                             alt="Registration banner" style="max-height: 80px; width: auto;">
                      </div>
                    </div>
                    <div class="mb-3">
                      <label for="nomination_banner" class="form-label">Upload new banner</label>
                      <input class="form-control" type="file" id="nomination_banner" name="nomination_banner"
                             accept="image/png,image/jpeg,image/webp,image/gif,.png,.jpg,.jpeg,.webp,.gif"
                             aria-describedby="nominationBannerHelp nominationBannerFeedback">
                      <div id="nominationBannerHelp" class="form-text">
                        JPG, PNG, GIF, or WebP · max 8 MB · at least 320×60 px · recommended wide banner (~1600×300 px).
                      </div>
                      <div id="nominationBannerFeedback" class="invalid-feedback"></div>
                      <div id="nominationBannerPreview" class="preview-thumb w-100 mt-2 d-none" aria-hidden="true">
                        <img id="nominationBannerPreviewImg" src="" alt="New banner preview" style="max-height:80px;width:auto;">
                      </div>
                    </div>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label for="nomination_bg_color" class="form-label fw-semibold">Page background</label>
                        <input type="color" class="form-control form-control-color w-100" style="height:48px;"
                               id="nomination_bg_color" name="nomination_bg_color"
                               value="<?php echo htmlspecialchars($nominationBgColor, ENT_QUOTES); ?>">
                      </div>
                      <div class="col-md-6">
                        <label for="nomination_text_color" class="form-label fw-semibold">Body text color</label>
                        <input type="color" class="form-control form-control-color w-100" style="height:48px;"
                               id="nomination_text_color" name="nomination_text_color"
                               value="<?php echo htmlspecialchars($nominationTextColor, ENT_QUOTES); ?>">
                      </div>
                    </div>
                  </div>
                </div>

                <div class="d-flex justify-content-end">
                  <button type="submit" class="btn btn-primary" id="saveNominationSettingsBtn">
                    <i class="fas fa-save me-1" aria-hidden="true"></i> Save registration settings
                  </button>
                </div>
              </div>
            </div>
          </form>
        </div>
      </main>
      <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/scripts.js"></script>
  <script src="js/admin_topbar_notifications.js"></script>
  <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 1080; pointer-events: none;">
    <div id="globalToast" class="toast align-items-center border-0 mx-auto" role="alert" aria-live="assertive" aria-atomic="true" style="pointer-events: auto; min-width: 240px; max-width: min(92vw, 480px);">
      <div class="d-flex">
        <div class="toast-body" id="globalToastBody"></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>
  <script>
  (function () {
    const BANNER_MAX_BYTES = <?php echo (int) NOM_BANNER_MAX_BYTES; ?>;
    const BANNER_MIN_W = <?php echo (int) NOM_BANNER_MIN_WIDTH; ?>;
    const BANNER_MIN_H = <?php echo (int) NOM_BANNER_MIN_HEIGHT; ?>;
    const BANNER_MAX_W = <?php echo (int) NOM_BANNER_MAX_WIDTH; ?>;
    const BANNER_MAX_H = <?php echo (int) NOM_BANNER_MAX_HEIGHT; ?>;
    const BANNER_EXT = /\.(jpe?g|png|gif|webp)$/i;
    const BANNER_MIME = /^image\/(jpeg|png|gif|webp)$/i;

    const form = document.getElementById('nominationSettingsForm');
    const saveBtn = document.getElementById('saveNominationSettingsBtn');
    const bannerInput = document.getElementById('nomination_banner');
    let saving = false;

    function showToast(message, type, delay) {
      type = type || 'success';
      delay = delay || 4500;
      const toastEl = document.getElementById('globalToast');
      const bodyEl = document.getElementById('globalToastBody');
      if (!toastEl || !bodyEl || !window.bootstrap) return;
      const classes = {
        success: 'text-bg-success',
        danger: 'text-bg-danger',
        warning: 'text-bg-warning',
        info: 'text-bg-info'
      };
      toastEl.className = 'toast align-items-center border-0 mx-auto ' + (classes[type] || classes.success);
      bodyEl.textContent = message;
      bootstrap.Toast.getOrCreateInstance(toastEl, { delay: delay }).show();
    }
    window.showToast = showToast;

    function setFieldError(input, feedbackEl, message) {
      if (!input) return false;
      if (message) {
        input.classList.add('is-invalid');
        if (feedbackEl) {
          feedbackEl.textContent = message;
          feedbackEl.classList.add('d-block');
        }
        return false;
      }
      input.classList.remove('is-invalid');
      if (feedbackEl) {
        feedbackEl.textContent = '';
        feedbackEl.classList.remove('d-block');
      }
      return true;
    }

    function scrollToInvalid(el) {
      if (!el) return;
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function setSavingState(active) {
      saving = active;
      if (!saveBtn) return;
      if (active) {
        if (!saveBtn.dataset.originalHtml) {
          saveBtn.dataset.originalHtml = saveBtn.innerHTML;
        }
        saveBtn.disabled = true;
        saveBtn.setAttribute('aria-busy', 'true');
        saveBtn.innerHTML =
          '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Saving…';
        return;
      }
      saveBtn.disabled = false;
      saveBtn.removeAttribute('aria-busy');
      if (saveBtn.dataset.originalHtml) {
        saveBtn.innerHTML = saveBtn.dataset.originalHtml;
      }
    }

    async function submitNominationSettings() {
      const postUrl = (form?.getAttribute('action') || 'nomination_settings.php').split('#')[0];
      const res = await fetch(postUrl, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        redirect: 'follow',
      });
      if (!res.ok && res.redirected !== true) {
        throw new Error('Save failed with status ' + res.status);
      }
      window.location.assign(res.url || postUrl);
    }

    function validateBannerFile(file) {
      const input = document.getElementById('nomination_banner');
      const fb = document.getElementById('nominationBannerFeedback');
      const previewWrap = document.getElementById('nominationBannerPreview');
      const previewImg = document.getElementById('nominationBannerPreviewImg');

      if (!file) {
        if (previewWrap) previewWrap.classList.add('d-none');
        if (input) delete input.dataset.bannerValidated;
        return setFieldError(input, fb, '');
      }

      if (!BANNER_MIME.test(file.type || '') && !BANNER_EXT.test(file.name || '')) {
        if (previewWrap) previewWrap.classList.add('d-none');
        if (input) delete input.dataset.bannerValidated;
        return setFieldError(input, fb, 'Banner must be JPG, PNG, GIF, or WebP.');
      }
      if (file.size > BANNER_MAX_BYTES) {
        if (previewWrap) previewWrap.classList.add('d-none');
        if (input) delete input.dataset.bannerValidated;
        return setFieldError(input, fb, 'Banner must be 8 MB or smaller.');
      }

      return new Promise(function (resolve) {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = function () {
          const w = img.naturalWidth;
          const h = img.naturalHeight;
          if (w < BANNER_MIN_W || h < BANNER_MIN_H) {
            URL.revokeObjectURL(url);
            if (previewWrap) previewWrap.classList.add('d-none');
            if (input) delete input.dataset.bannerValidated;
            resolve(setFieldError(
              input,
              fb,
              'Banner is too small. Use at least ' + BANNER_MIN_W + '×' + BANNER_MIN_H + ' pixels.'
            ));
            return;
          }
          if (w > BANNER_MAX_W || h > BANNER_MAX_H) {
            URL.revokeObjectURL(url);
            if (previewWrap) previewWrap.classList.add('d-none');
            if (input) delete input.dataset.bannerValidated;
            resolve(setFieldError(
              input,
              fb,
              'Banner is too large (max ' + BANNER_MAX_W + '×' + BANNER_MAX_H + ' pixels).'
            ));
            return;
          }
          if (previewImg && previewWrap) {
            previewImg.onload = function () {
              URL.revokeObjectURL(url);
            };
            previewImg.src = url;
            previewWrap.classList.remove('d-none');
            input.dataset.bannerValidated = '1';
          } else {
            URL.revokeObjectURL(url);
          }
          resolve(setFieldError(input, fb, ''));
        };
        img.onerror = function () {
          URL.revokeObjectURL(url);
          if (previewWrap) previewWrap.classList.add('d-none');
          if (input) delete input.dataset.bannerValidated;
          resolve(setFieldError(input, fb, 'Could not read image file.'));
        };
        img.src = url;
      });
    }

    bannerInput?.addEventListener('change', function () {
      const file = bannerInput.files && bannerInput.files[0];
      delete bannerInput.dataset.bannerValidated;
      validateBannerFile(file || null);
    });

    form?.addEventListener('submit', function (e) {
      e.preventDefault();
      if (saving) return;

      const file = bannerInput?.files && bannerInput.files[0];
      const runSave = async function () {
        if (file) {
          const bannerOk = await validateBannerFile(file);
          if (!bannerOk) {
            showToast('Fix the banner upload before saving.', 'danger');
            bannerInput?.focus();
            scrollToInvalid(bannerInput);
            return;
          }
        }

        setSavingState(true);
        try {
          await submitNominationSettings();
        } catch (err) {
          console.error(err);
          setSavingState(false);
          showToast('Could not save settings. Please try again.', 'danger');
        }
      };

      runSave();
    });
  })();
  </script>
  <?php
  if (!empty($_SESSION['flash_toast'])) {
      $msg = $_SESSION['flash_toast']['message'] ?? 'Done';
      $type = $_SESSION['flash_toast']['type'] ?? 'success';
      unset($_SESSION['flash_toast']);
      echo '<script>document.addEventListener("DOMContentLoaded",function(){showToast('
          . json_encode($msg) . ',' . json_encode($type) . ');});</script>';
  }
  if (!empty($_SERVER['REQUEST_URI']) && str_contains((string) $_SERVER['REQUEST_URI'], '#')) {
      // hash scroll handled below
  }
  ?>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    const hash = window.location.hash;
    if (hash) {
      const el = document.querySelector(hash);
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });
  </script>
</body>
</html>
