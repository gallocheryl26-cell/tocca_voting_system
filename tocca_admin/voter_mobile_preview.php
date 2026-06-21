<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_nav_active('voter_mobile_preview.php', 'voter_mobile_preview');

$voterBase = '../e-vote-final-enhanced/';
$nominationBase = '../nomination/';

/** @var array<string, array{base: string, pages: array<string, string>}> */
$previewPageGroups = [
    'Voting portal' => [
        'base'  => $voterBase,
        'pages' => [
            'index.php'                => 'Voting home (login)',
            'category.php'             => 'Category list',
            'message.php'              => 'Voting closed / message',
            'terms_and_conditions.php' => 'Terms & conditions',
            'privacy_policy.php'       => 'Privacy policy',
        ],
    ],
    'Nomination portal' => [
        'base'  => $nominationBase,
        'pages' => [
            'nomination_form.php'      => 'Nomination application',
            'nomination_tracking.php'  => 'Nomination tracking',
            'nomination_message.php'   => 'Nomination status / message',
            'nomination_thankyou.php'  => 'Nomination thank you',
        ],
    ],
];

$defaultPage = 'index.php';
$defaultBase = $voterBase;

$devices = [
    'iphone_se'   => ['label' => 'iPhone SE',        'width' => 375, 'height' => 667],
    'iphone_14'   => ['label' => 'iPhone 14',        'width' => 390, 'height' => 844],
    'pixel_7'     => ['label' => 'Google Pixel 7',   'width' => 412, 'height' => 915],
    'galaxy_s20'  => ['label' => 'Samsung Galaxy',   'width' => 360, 'height' => 800],
];

$defaultDevice = 'iphone_14';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="js/instant_theme_init.js"></script>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Mobile Live Preview | Tatak Ormoc</title>
    <link rel="icon" type="image/png" href="<?php echo h($faviconPath); ?>">
    <link href="css/styles.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <?php include 'inline_style.php'; ?>
    <style>
      .mobile-preview-layout {
        display: grid;
        grid-template-columns: minmax(260px, 320px) 1fr;
        gap: 1.5rem;
        align-items: start;
      }
      @media (max-width: 991.98px) {
        .mobile-preview-layout {
          grid-template-columns: 1fr;
        }
      }
      .device-controls .form-label {
        font-weight: 600;
        font-size: 0.85rem;
      }
      .device-stage {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: 640px;
        padding: 1rem 0 2rem;
        background:
          radial-gradient(circle at 50% 0%, rgba(1, 0, 102, 0.08), transparent 55%),
          linear-gradient(180deg, #f4f6fb 0%, #e9edf5 100%);
        border-radius: 1rem;
        border: 1px solid rgba(0, 0, 0, 0.06);
        overflow: auto;
      }
      .device-scale-wrap {
        transform-origin: top center;
        transition: transform 0.2s ease;
      }
      .phone-shell {
        position: relative;
        background: #111;
        border-radius: 2.4rem;
        padding: 0.85rem 0.7rem 1.1rem;
        box-shadow:
          0 24px 60px rgba(15, 23, 42, 0.28),
          inset 0 0 0 2px rgba(255, 255, 255, 0.08);
      }
      .phone-notch {
        position: absolute;
        top: 0.55rem;
        left: 50%;
        transform: translateX(-50%);
        width: 34%;
        max-width: 120px;
        height: 22px;
        background: #111;
        border-radius: 0 0 14px 14px;
        z-index: 2;
        pointer-events: none;
      }
      .phone-screen {
        position: relative;
        overflow: hidden;
        border-radius: 1.6rem;
        background: #fff;
        box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.12);
        /* Slight inset so iframe content isn’t clipped at rounded corners */
        padding-bottom: 2px;
      }
      .phone-screen iframe {
        display: block;
        width: 100%;
        height: calc(100% - 2px);
        border: 0;
        background: #fff;
      }
      .phone-home-bar {
        width: 34%;
        height: 4px;
        margin: 0.65rem auto 0;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.35);
      }
      .preview-meta {
        font-size: 0.8rem;
        color: #6c757d;
      }
      .preview-url {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 0.75rem;
        word-break: break-all;
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
              <h1 class="admin-page-title mb-2">Mobile Live Preview</h1>
              <?php echo render_user_portal_breadcrumb([['label' => 'Mobile Live Preview']]); ?>
            </div>
          </div>

                <div class="alert alert-warning d-flex gap-2 align-items-start mt-3" role="status">
                    <i class="fas fa-mobile-screen-button mt-1" aria-hidden="true"></i>
                    <div class="small mb-0">
                        This shows the <strong>real voter or nomination site</strong> in a phone-sized frame for layout checks.
                        For <strong>OTP login, form submission, or tracking lookups</strong>, use <strong>Open in tab</strong> first — some browsers block or limit sessions inside embedded previews.
                        Firebase, reCAPTCHA, and SMS OTP work most reliably in a full browser tab or on a physical phone.
                    </div>
                </div>

                <div class="mobile-preview-layout mt-3">
                    <div class="device-controls card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title mb-3"><i class="bi bi-phone me-1"></i> Emulator controls</h5>

                            <div class="mb-3">
                                <label class="form-label" for="devicePreset">Device size</label>
                                <select class="form-select" id="devicePreset">
                                    <?php foreach ($devices as $key => $device): ?>
                                    <option value="<?php echo h($key); ?>"
                                            data-width="<?php echo (int) $device['width']; ?>"
                                            data-height="<?php echo (int) $device['height']; ?>"
                                            <?php echo $key === $defaultDevice ? 'selected' : ''; ?>>
                                        <?php echo h($device['label']); ?> (<?php echo (int) $device['width']; ?>×<?php echo (int) $device['height']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="voterPage">Portal page</label>
                                <select class="form-select" id="voterPage">
                                    <?php foreach ($previewPageGroups as $groupLabel => $group): ?>
                                    <optgroup label="<?php echo h($groupLabel); ?>">
                                        <?php foreach ($group['pages'] as $file => $label): ?>
                                        <?php
                                          $isDefault = $file === $defaultPage && $group['base'] === $defaultBase;
                                        ?>
                                        <option value="<?php echo h($file); ?>"
                                                data-base="<?php echo h($group['base']); ?>"
                                                <?php echo $isDefault ? 'selected' : ''; ?>>
                                            <?php echo h($label); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="scaleFit">
                                    Fit to panel
                                    <span class="text-muted fw-normal">(auto shrink on small screens)</span>
                                </label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="scaleFit" checked>
                                    <label class="form-check-label" for="scaleFit">Scale phone to fit</label>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="btn btn-primary btn-sm" id="reloadPreview">
                                    <i class="fas fa-rotate-right me-1"></i> Reload
                                </button>
                                <a class="btn btn-outline-secondary btn-sm" id="openExternal" href="#" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-box-arrow-up-right me-1"></i> Open in tab
                                </a>
                            </div>

                            <p class="preview-meta mb-1">Current URL</p>
                            <p class="preview-url mb-0" id="previewUrl"></p>
                        </div>
                    </div>

                    <div class="device-stage" id="deviceStage">
                        <div class="device-scale-wrap" id="deviceScaleWrap">
                            <div class="phone-shell" id="phoneShell">
                                <div class="phone-notch" aria-hidden="true"></div>
                                <div class="phone-screen" id="phoneScreen">
                                    <iframe
                                        id="voterPreviewFrame"
                                        title="Portal mobile preview"
                                        src="<?php echo h($defaultBase . $defaultPage); ?>"
                                        loading="lazy"
                                        referrerpolicy="same-origin"
                                    ></iframe>
                                </div>
                                <div class="phone-home-bar" aria-hidden="true"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <footer class="py-4 bg-light mt-auto">
            <div class="container-fluid px-4">
                <div class="small text-muted">&copy; 2026 Tatak Ormoc Consumers&rsquo; Choice Awards</div>
            </div>
        </footer>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/scripts.js"></script>
<script>
(function () {
  const defaultBase = <?php echo json_encode($defaultBase, JSON_UNESCAPED_SLASHES); ?>;
  const frame = document.getElementById('voterPreviewFrame');
  const screen = document.getElementById('phoneScreen');
  const stage = document.getElementById('deviceStage');
  const scaleWrap = document.getElementById('deviceScaleWrap');
  const devicePreset = document.getElementById('devicePreset');
  const voterPage = document.getElementById('voterPage');
  const scaleFit = document.getElementById('scaleFit');
  const reloadBtn = document.getElementById('reloadPreview');
  const openExternal = document.getElementById('openExternal');
  const previewUrl = document.getElementById('previewUrl');

  function selectedPageOption() {
    return voterPage.selectedOptions[0] || null;
  }

  function currentBase() {
    const opt = selectedPageOption();
    return (opt && opt.dataset.base) ? opt.dataset.base : defaultBase;
  }

  function currentPage() {
    return voterPage.value || 'index.php';
  }

  function currentSrc() {
    return currentBase() + currentPage();
  }

  function selectedDevice() {
    const opt = devicePreset.selectedOptions[0];
    return {
      width: parseInt(opt.dataset.width, 10) || 390,
      height: parseInt(opt.dataset.height, 10) || 844,
    };
  }

  function applyDeviceSize() {
    const { width, height } = selectedDevice();
    screen.style.width = width + 'px';
    screen.style.height = height + 'px';
    frame.style.width = width + 'px';
    frame.style.height = height + 'px';
    applyScale();
  }

  function applyScale() {
    scaleWrap.style.transform = 'none';
    if (!scaleFit.checked) {
      return;
    }
    const shell = document.getElementById('phoneShell');
    const shellRect = shell.getBoundingClientRect();
    const stageRect = stage.getBoundingClientRect();
    const maxW = stageRect.width - 32;
    const maxH = stageRect.height - 32;
    const scale = Math.min(1, maxW / shellRect.width, maxH / shellRect.height);
    if (scale < 1) {
      scaleWrap.style.transform = 'scale(' + scale.toFixed(3) + ')';
    }
  }

  function navigatePreview() {
    const src = currentSrc();
    frame.src = src;
    previewUrl.textContent = new URL(src, window.location.href).pathname;
    openExternal.href = src;
  }

  devicePreset.addEventListener('change', applyDeviceSize);
  voterPage.addEventListener('change', navigatePreview);
  scaleFit.addEventListener('change', applyScale);
  reloadBtn.addEventListener('click', function () {
    frame.src = currentSrc();
  });
  window.addEventListener('resize', applyScale);

  applyDeviceSize();
  navigatePreview();
})();
</script>
</body>
</html>
