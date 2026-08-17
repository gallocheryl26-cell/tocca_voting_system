<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script('admin_settings.php');
require_once __DIR__ . '/qr_frame_config.php';
require_once __DIR__ . '/qr_style_config.php';
require_once __DIR__ . '/qr_frame_presets.php';
require_once __DIR__ . '/includes/report_export_helpers.php';
require_once __DIR__ . '/audit_log.php';

report_export_ensure_schema($conn);
$reportSettings = report_export_get_settings($conn);
$adminSignatureRel = report_export_get_admin_signature_rel($conn, (string) ($_SESSION['username'] ?? ''));
$adminSignatureWebPath = $adminSignatureRel ? resolveAssetPath($adminSignatureRel) : '';
$reviewerSignatureWebPath = !empty($reportSettings['reviewer_signature_path'])
    ? resolveAssetPath($reportSettings['reviewer_signature_path']) : '';
$approverSignatureWebPath = !empty($reportSettings['approver_signature_path'])
    ? resolveAssetPath($reportSettings['approver_signature_path']) : '';
$reportPdfHasPassword = $reportSettings['pdf_password'] !== '';
$reportExcelHasPassword = $reportSettings['excel_password'] !== '';

$votingQrBaseUrl = getConfig('voting_qr_base_url', '');
require_once __DIR__ . '/qr_url.php';
$votingQrBaseUrl = qr_normalize_site_root((string) $votingQrBaseUrl);

function admin_settings_flash_toast(string $message, string $type = 'success'): void
{
    $_SESSION['flash_toast'] = ['message' => $message, 'type' => $type];
}

function admin_settings_redirect(string $hash = ''): never
{
    $location = 'admin_settings.php';
    if ($hash !== '') {
        $location .= str_starts_with($hash, '#') ? $hash : '#' . $hash;
    }
    session_write_close();
    header('Location: ' . $location);
    exit;
}

function admin_set_config(string $key, string $value): bool
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

/**
 * @return list<string>
 */
function admin_validate_qr_settings_post(array $post, array $files = []): array
{
    $errors = [];
    $useFrame = isset($post['use_frame']);

    if ($useFrame) {
        $path = trim((string) ($post['frame_path'] ?? ''));
        if ($path === '') {
            $errors[] = 'Frame image path is required when the poster frame is enabled.';
        } else {
            $abs = qr_frame_absolute_path($path);
            if (!is_file($abs)) {
                $errors[] = 'Poster frame file was not found. Upload a frame image or check the path.';
            } else {
                [$fw, $fh] = qr_frame_detect_size($abs);
                $bx = (int) ($post['frame_box_x'] ?? 0);
                $by = (int) ($post['frame_box_y'] ?? 0);
                $bw = (int) ($post['frame_box_w'] ?? 0);
                $bh = (int) ($post['frame_box_h'] ?? 0);
                if ($bw < 40 || $bh < 40) {
                    $errors[] = 'QR placement box must be at least 40×40 pixels.';
                }
                if ($fw > 0 && $fh > 0 && ($bx < 0 || $by < 0 || $bx + $bw > $fw || $by + $bh > $fh)) {
                    $errors[] = 'QR placement must fit inside the frame image (' . $fw . '×' . $fh . ' px).';
                }
            }
        }
    }

    $hexFields = [
        'qr_fg_color' => 'QR foreground color',
        'qr_bg_color' => 'QR background color',
        'label_color' => 'Label color',
    ];
    foreach ($hexFields as $key => $label) {
        $raw = trim((string) ($post[$key] ?? ''));
        if ($raw === '') {
            continue;
        }
        $hex = ltrim($raw, '#');
        if (!preg_match('/^[0-9a-fA-F]{3}$|^[0-9a-fA-F]{6}$/', $hex)) {
            $errors[] = $label . ' must be a valid hex value (e.g. #010066).';
        }
    }

    if (isset($post['qr_logo_size_pct']) && $post['qr_logo_size_pct'] !== '') {
        $pct = (int) $post['qr_logo_size_pct'];
        if ($pct < 8 || $pct > 30) {
            $errors[] = 'Center logo size must be between 8% and 30%.';
        }
    }

    if (isset($files['frame_file']['error']) && (int) $files['frame_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $code = (int) $files['frame_file']['error'];
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'Frame image is too large (max 8 MB).';
        } elseif ($code !== UPLOAD_ERR_OK) {
            $errors[] = 'Frame image upload failed. Please try again.';
        }
    }

    if (isset($files['qr_logo_file']['error']) && (int) $files['qr_logo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $code = (int) $files['qr_logo_file']['error'];
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'Center logo is too large (max 4 MB).';
        } elseif ($code !== UPLOAD_ERR_OK) {
            $errors[] = 'Center logo upload failed. Use PNG, WebP, GIF, or JPEG.';
        }
    }

    return $errors;
}

function upload_frame_image_from_settings(string $inputName): ?string {
  if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) return null;

  if (!empty($_FILES[$inputName]['size']) && $_FILES[$inputName]['size'] > 8 * 1024 * 1024) {
    return null;
  }

  $targetDirAbs = __DIR__ . '/img/';
  $targetDirRel = 'img/';
  if (!is_dir($targetDirAbs)) { @mkdir($targetDirAbs, 0775, true); }

  $originalName = basename($_FILES[$inputName]['name']);
  $cleanName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $originalName);
  $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
  $allowedExt = ['jpg','jpeg','png','gif','webp'];
  if (!in_array($ext, $allowedExt, true)) return null;

  $tmp = $_FILES[$inputName]['tmp_name'];
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = $finfo ? finfo_buffer($finfo, file_get_contents($tmp)) : '';
  if ($finfo) finfo_close($finfo);
  $allowedMime = ['image/png','image/jpeg','image/gif','image/webp'];
  if (!in_array($mime, $allowedMime, true)) return null;

  $uniqueFileName = 'qr_frame_' . time() . '_' . $cleanName;
  $targetAbs = $targetDirAbs . $uniqueFileName;
  $targetRel = $targetDirRel . $uniqueFileName;

  if (move_uploaded_file($tmp, $targetAbs)) return $targetRel;
  return null;
}

// Load stored QR frame configuration
$qrFrameConfig = qr_frame_load_config($conn);
$qrFrameOptions = [
  'box_x' => (int)($qrFrameConfig['frame_box_x'] ?? 500),
  'box_y' => (int)($qrFrameConfig['frame_box_y'] ?? 1000),
  'box_w' => (int)($qrFrameConfig['frame_box_w'] ?? 660),
  'box_h' => (int)($qrFrameConfig['frame_box_h'] ?? 660),
  'card_side_pad' => (int)($qrFrameConfig['card_side_pad'] ?? 24),
  'card_top_pad' => (int)($qrFrameConfig['card_top_pad'] ?? 18),
  'label_strip_h_min' => (int)($qrFrameConfig['label_strip_h_min'] ?? 72),
  'label_strip_h_max' => (int)($qrFrameConfig['label_strip_h_max'] ?? 140),
  'gap_qr_to_label' => (int)($qrFrameConfig['gap_qr_to_label'] ?? 10),
  'qr_side_min' => (int)($qrFrameConfig['qr_side_min'] ?? 360),
  'label_side_pad' => (int)($qrFrameConfig['label_side_pad'] ?? 10),
  'label_top_pad' => (int)($qrFrameConfig['label_top_pad'] ?? 8),
  'label_bottom_pad' => (int)($qrFrameConfig['label_bottom_pad'] ?? 10),
  'label_line_spacing' => (int)($qrFrameConfig['label_line_spacing'] ?? 6),
  'label_font_size' => (int)($qrFrameConfig['label_font_size'] ?? 40),
];
$qrFrameUse = !empty($qrFrameConfig['use_frame']);
$qrFramePathRaw = $qrFrameConfig['frame_path'] ?? '';
// Use the web-relative path for <img src>, not the absolute filesystem path
$qrFramePathResolved = $qrFramePathRaw;

$qrStyleConfig = qr_style_load_config($conn);
$qrLogoPathRaw = $qrStyleConfig['logo_path'] ?? '';
$qrLogoPathResolved = $qrLogoPathRaw;

$qrActiveEventId = admin_active_event_id($conn) ?? 0;
$qrCategories = $qrActiveEventId > 0 ? qr_fetch_event_categories($conn, (int)$qrActiveEventId) : [];
$qrCategoryFrames = qr_category_frames_load($conn);
$qrDefaultPreset = (string)($qrFrameConfig['default_preset'] ?? 'standard');
$qrPresetCatalog = qr_frame_preset_catalog();

[$qrFrameNatW, $qrFrameNatH] = qr_frame_detect_size(
    qr_frame_absolute_path((string)($qrFramePathRaw ?: 'img/qr_frame.jpg'))
);
$qrPresetLayouts = [];
foreach (array_keys($qrPresetCatalog) as $presetKey) {
    $qrPresetLayouts[$presetKey] = qr_frame_preset_layout($presetKey, $qrFrameNatW, $qrFrameNatH);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_voting_qr_base_url'])) {
    require_once __DIR__ . '/qr_url.php';
    $rawUrl = trim((string) ($_POST['voting_qr_base_url'] ?? ''));
    $url = qr_normalize_site_root($rawUrl);
    $oldUrl = qr_normalize_site_root((string) getConfig('voting_qr_base_url', ''));

    if ($url !== $oldUrl && $url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        admin_settings_flash_toast('Voting base URL must be a valid http(s) URL, or leave blank for auto-detect.', 'danger');
        admin_settings_redirect('#qr-public-urls');
    }

    if ($url === $oldUrl) {
        admin_settings_flash_toast('Voting QR base URL unchanged.', 'info');
    } elseif (admin_set_config('voting_qr_base_url', $url)) {
        audit_log($conn, 'admin_settings', 'update', 'config', 'voting_qr_base_url', [
            'old' => $oldUrl,
            'new' => $url,
        ]);
        admin_settings_flash_toast(
            $url === ''
                ? 'Voting QR base URL cleared. Auto-detection will be used for establishment QRs.'
                : 'Voting QR base URL saved.',
            'success'
        );
    } else {
        admin_settings_flash_toast('Failed to save voting QR base URL.', 'danger');
    }
    admin_settings_redirect('#qr-public-urls');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_qr_frame_settings'])) {
  $qrSaveHash = '#qr-frame-settings';
  $validationErrors = admin_validate_qr_settings_post($_POST, $_FILES);
  if ($validationErrors !== []) {
    admin_settings_flash_toast(implode(' ', $validationErrors), 'danger');
    admin_settings_redirect($qrSaveHash);
  }

  $newConfig = $qrFrameConfig;
  $newConfig['use_frame'] = isset($_POST['use_frame']);
  $newConfig['show_label_on_poster'] = false;
  $allowedPresets = array_keys(qr_frame_preset_catalog());
  $presetIn = trim((string)($_POST['default_preset'] ?? 'center_fit'));
  $newConfig['default_preset'] = in_array($presetIn, $allowedPresets, true) ? $presetIn : 'center_fit';
  $newConfig['frame_path'] = trim($_POST['frame_path'] ?? $qrFramePathRaw);

  $map = [
    'frame_box_x' => 'box_x',
    'frame_box_y' => 'box_y',
    'frame_box_w' => 'box_w',
    'frame_box_h' => 'box_h',
    'card_side_pad' => 'card_side_pad',
    'card_top_pad' => 'card_top_pad',
    'label_strip_h_min' => 'label_strip_h_min',
    'label_strip_h_max' => 'label_strip_h_max',
    'gap_qr_to_label' => 'gap_qr_to_label',
    'qr_side_min' => 'qr_side_min',
    'label_side_pad' => 'label_side_pad',
    'label_top_pad' => 'label_top_pad',
    'label_bottom_pad' => 'label_bottom_pad',
    'label_line_spacing' => 'label_line_spacing',
    'label_font_size' => 'label_font_size',
  ];

  foreach ($map as $formKey => $optKey) {
    if (isset($_POST[$formKey]) && $_POST[$formKey] !== '') {
      $newConfig[$formKey] = (int)$_POST[$formKey];
      $qrFrameOptions[$optKey] = (int)$_POST[$formKey];
    }
  }

  $frameUploadAttempted = isset($_FILES['frame_file']['error'])
    && (int) $_FILES['frame_file']['error'] !== UPLOAD_ERR_NO_FILE;
  $uploaded = upload_frame_image_from_settings('frame_file');
  if ($frameUploadAttempted && (int) $_FILES['frame_file']['error'] === UPLOAD_ERR_OK && !$uploaded) {
    admin_settings_flash_toast(
      'Frame image was rejected. Use JPG, PNG, GIF, or WebP up to 8 MB.',
      'danger'
    );
    admin_settings_redirect($qrSaveHash);
  }

  if ($uploaded) {
    $newConfig['frame_path'] = $uploaded;
    $qrFramePathRaw = $uploaded;
    $qrFramePathResolved = $uploaded;
    $newConfig['default_preset'] = 'center_fit';
    $presetIn = 'center_fit';
  }

  if ($uploaded) {
    $frameAbsForPreset = qr_frame_absolute_path((string)($newConfig['frame_path'] ?? ''));
    [$presetW, $presetH] = qr_frame_detect_size($frameAbsForPreset);
    $newConfig = array_merge($newConfig, qr_frame_preset_layout($presetIn, $presetW, $presetH));
  }

  $logoUploadAttempted = isset($_FILES['qr_logo_file']['error'])
    && (int) $_FILES['qr_logo_file']['error'] !== UPLOAD_ERR_NO_FILE;
  $logoUploaded = null;
  if ($logoUploadAttempted && (int) $_FILES['qr_logo_file']['error'] === UPLOAD_ERR_OK) {
    $logoUploaded = upload_qr_center_logo('qr_logo_file');
    if (!$logoUploaded) {
      admin_settings_flash_toast(
        'Center logo was rejected. Use PNG, WebP, GIF, or JPEG up to 4 MB.',
        'danger'
      );
      admin_settings_redirect($qrSaveHash);
    }
  }

  if (qr_frame_save_config($conn, $newConfig)) {
    $stylePayload = qr_style_from_post($_POST);
    $stylePayload['logo_path'] = trim($_POST['qr_logo_path'] ?? $qrLogoPathRaw);
    if ($logoUploaded) {
      $stylePayload['logo_path'] = $logoUploaded;
    }
    $styleSaved = qr_style_save_config($conn, $stylePayload);

    $eventIdForCats = admin_active_event_id($conn) ?? 0;
    $catsForSave = $eventIdForCats > 0 ? qr_fetch_event_categories($conn, (int)$eventIdForCats) : [];
    $catsSaved = qr_category_frames_save($conn, qr_category_frames_from_post($_POST, $catsForSave));

    if ($styleSaved && $catsSaved) {
      $toastMsg = 'QR settings saved. Regenerate posters on Businesses to apply changes.';
      $toastType = 'success';
    } elseif ($styleSaved) {
      $toastMsg = 'QR frame and colors saved, but category frame overrides could not be saved.';
      $toastType = 'warning';
    } else {
      $toastMsg = 'QR frame saved, but QR colors could not be saved.';
      $toastType = 'warning';
    }
    audit_log($conn, 'admin_settings', 'update', 'config', 'qr_frame_config', [
      'use_frame' => !empty($newConfig['use_frame']),
      'frame_path' => (string) ($newConfig['frame_path'] ?? ''),
    ]);
    admin_settings_flash_toast($toastMsg, $toastType);
    admin_settings_redirect($qrSaveHash);
  } else {
    admin_settings_flash_toast('Failed to save QR frame settings. Please try again.', 'danger');
    admin_settings_redirect($qrSaveHash);
  }
}
?>

  <!DOCTYPE html>
  <html lang="en">
  <head>
    <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
      <meta http-equiv="X-UA-Compatible" content="IE=edge" />
      <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
      <meta name="description" content="" />
      <meta name="author" content="" />
      <title>Admin Settings | Tatak Ormoc</title>
      <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
      <link href="css/styles.css" rel="stylesheet" />
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
      <?php include 'inline_style.php'; ?>
      <style>
      .notif-badge { min-width: 90px; display: inline-block; text-align: center; }
      .notif-text  { flex: 1; }

/* ---------- Customizations: tabbed layout polish ---------- */
.settings-tabs {
  border: 0;
  background: rgba(15, 23, 42, 0.04);
  padding: .35rem;
  border-radius: 14px;
  gap: .35rem;
  display: inline-flex;
  flex-wrap: wrap;
  margin-bottom: 1.5rem;
}
.settings-tabs .nav-link {
  border: 0;
  border-radius: 10px;
  font-weight: 600;
  color: #475569;
  padding: .55rem 1rem;
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  transition: background-color .18s ease, color .18s ease, transform .12s ease;
}
.settings-tabs .nav-link:hover { color: #0f172a; background: rgba(15, 23, 42, 0.06); }
.settings-tabs .nav-link.active {
  background: linear-gradient(135deg, #1d4ed8, #2563eb);
  color: #fff;
  box-shadow: 0 6px 16px -8px rgba(37, 99, 235, .55);
  transform: translateY(-1px);
}
.settings-tabs .nav-link i { font-size: 1.05em; }
html.dark-mode .settings-tabs { background: rgba(148, 163, 184, 0.12); }
html.dark-mode .settings-tabs .nav-link { color: #cbd5e1; }
html.dark-mode .settings-tabs .nav-link:hover { color: #f8fafc; background: rgba(148, 163, 184, 0.18); }

.settings-card {
  border: 1px solid rgba(15, 23, 42, .08);
  border-radius: 14px;
  box-shadow: 0 6px 24px -18px rgba(15, 23, 42, .35);
  background: #fff;
  transition: box-shadow .2s ease, transform .2s ease;
}
.settings-card:hover { box-shadow: 0 10px 30px -16px rgba(15, 23, 42, .28); }
.settings-card .settings-card-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid rgba(15, 23, 42, .06);
}
.settings-card .settings-card-head h5 { margin: 0; font-size: 1.05rem; font-weight: 600; }
.settings-card .settings-card-head .head-icon {
  width: 38px; height: 38px; border-radius: 10px;
  display: inline-flex; align-items: center; justify-content: center;
  background: rgba(37, 99, 235, .08); color: #2563eb; font-size: 1.05rem;
}
.settings-card .settings-card-body { padding: 1.25rem; }
html.dark-mode .settings-card {
  background: #0b1220;
  border-color: rgba(148, 163, 184, .15);
  box-shadow: 0 6px 24px -18px rgba(0,0,0,.6);
}
html.dark-mode .settings-card .settings-card-head { border-bottom-color: rgba(148, 163, 184, .15); }
html.dark-mode .settings-card .settings-card-head .head-icon { background: rgba(96, 165, 250, .14); color: #93c5fd; }

.preview-thumb {
  display: inline-flex; align-items: center; justify-content: center;
  border: 1px dashed rgba(15, 23, 42, .15); border-radius: 10px;
  padding: .6rem; background: #f8fafc; min-height: 76px;
}
html.dark-mode .preview-thumb { background: #0f172a; border-color: rgba(148, 163, 184, .25); }

.color-swatch {
  display: inline-block; width: 18px; height: 18px;
  border-radius: 4px; border: 1px solid rgba(0,0,0,.12);
  vertical-align: middle; margin-right: 6px;
}

.palette-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 8px 12px; border-radius: 10px;
  border: 1px solid rgba(15, 23, 42, .12);
  background: #fff; color: #0f172a;
  transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
  font-size: .85rem; font-weight: 500;
}
.palette-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 18px -10px rgba(15,23,42,.4); border-color: rgba(37,99,235,.5); }
.palette-btn .swatches { display: inline-flex; gap: 2px; }
.palette-btn .swatches > span { width: 14px; height: 14px; border-radius: 3px; }
html.dark-mode .palette-btn { background: #0b1220; color: #e2e8f0; border-color: rgba(148, 163, 184, .2); }

.settings-advanced summary {
  cursor: pointer;
  color: #475569;
}
.settings-advanced[open] summary {
  margin-bottom: .25rem;
}
html.dark-mode .settings-advanced summary { color: #cbd5e1; }
html.dark-mode .settings-advanced { border-color: rgba(148, 163, 184, .2) !important; }

#qr-frame-settings .qr-setup-steps {
  display: flex;
  flex-wrap: wrap;
  gap: .5rem 1rem;
  padding: 0;
  margin: 0;
  list-style: none;
}
#qr-frame-settings .qr-setup-steps li {
  font-size: .875rem;
  color: #475569;
}
#qr-frame-settings .qr-setup-steps li strong { color: #0f172a; }
#qr-frame-settings .qr-preview-card {
  border: 2px solid rgba(56, 189, 248, 0.35);
  border-radius: 12px;
  background: #f8fafc;
}
html.dark-mode #qr-frame-settings .qr-preview-card { background: #0b1220; }
#qr-frame-settings .preview-wrapper {
  position: relative;
  min-height: 280px;
  background-color: #e2e8f0;
  background-image:
    linear-gradient(45deg, #cbd5e1 25%, transparent 25%),
    linear-gradient(-45deg, #cbd5e1 25%, transparent 25%),
    linear-gradient(45deg, transparent 75%, #cbd5e1 75%),
    linear-gradient(-45deg, transparent 75%, #cbd5e1 75%);
  background-size: 16px 16px;
  background-position: 0 0, 0 8px, 8px -8px, -8px 0;
  border: 1px solid #94a3b8;
  border-radius: 12px;
  overflow: hidden;
}
#qr-frame-settings .preview-wrapper img {
  display: block;
  width: 100%;
  height: auto;
  max-height: min(72vh, 640px);
  margin: 0 auto;
  object-fit: contain;
}
#qr-frame-settings .qr-layout-editor-card {
  background: #f8fafc;
}
html.dark-mode #qr-frame-settings .qr-layout-editor-card { background: #0f172a; }
#qr-frame-settings .qr-workspace-row .qr-layout-editor {
  max-width: 100%;
}
#qr-frame-settings .qr-workspace-row .qr-layout-editor-card,
#qr-frame-settings .qr-workspace-row .qr-preview-card {
  min-height: 100%;
}
#qr-frame-settings .qr-workspace-row .preview-wrapper {
  min-height: 220px;
}
@media (min-width: 992px) {
  #qr-frame-settings .qr-workspace-row .preview-wrapper img {
    max-height: min(65vh, 520px);
  }
}
#qr-frame-settings .qr-layout-editor-inner {
  position: relative;
  display: block;
  width: 100%;
  line-height: 0;
  user-select: none;
}
#qr-frame-settings .qr-layout-editor-bg {
  width: 100%;
  height: auto;
  display: block;
  pointer-events: none;
}
#qr-frame-settings .qr-placement-rect {
  position: absolute;
  box-sizing: border-box;
  border: 2px dashed #0ea5e9;
  border-radius: 4px;
  background: rgba(14, 165, 233, 0.15);
  cursor: move;
  touch-action: none;
  min-width: 24px;
  min-height: 24px;
  z-index: 2;
}
#qr-frame-settings .qr-placement-rect .qr-placement-label {
  position: absolute;
  top: 2px;
  left: 4px;
  font-size: 10px;
  font-weight: 600;
  color: #0369a1;
  line-height: 1.2;
  pointer-events: none;
  text-shadow: 0 0 4px #fff;
}
#qr-frame-settings .qr-placement-rect .resize-handle {
  position: absolute;
  right: -8px;
  bottom: -8px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: #0ea5e9;
  border: 2px solid #fff;
  cursor: nwse-resize;
  box-shadow: 0 1px 4px rgba(0,0,0,.25);
}
#qr-frame-settings .preview-placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #334155;
  font-size: 14px;
  text-align: center;
  padding: 1rem;
  pointer-events: none;
  background: rgba(248, 250, 252, 0.85);
}
html.dark-mode #qr-frame-settings .preview-placeholder {
  color: #e2e8f0;
  background: rgba(15, 23, 42, 0.88);
}
#qr-frame-settings .preview-placeholder.hidden { opacity: 0; visibility: hidden; }
      [data-bs-theme="dark"] #qr-frame-settings .form-control, [data-bs-theme="dark"] #qr-frame-settings .form-select { background-color: #111827; color: #e5e7eb; border-color: #334155; }
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
              <h1 class="admin-page-title mb-2">Admin Settings</h1>
              <?php echo render_customizations_breadcrumb([['label' => 'Admin Settings']]); ?>
            </div>
          </div>

                  <!-- ===== Settings Tab Navigation ===== -->
                  <ul class="nav nav-pills settings-tabs" id="settingsTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                      <button class="nav-link active" id="branding-tab" data-bs-toggle="tab" data-bs-target="#brandingPane" type="button" role="tab" aria-controls="brandingPane" aria-selected="true">
                        <i class="fas fa-image"></i> Branding
                      </button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="theme-tab" data-bs-toggle="tab" data-bs-target="#themePane" type="button" role="tab" aria-controls="themePane" aria-selected="false">
                        <i class="fas fa-palette"></i> Theme &amp; Colors
                      </button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="qr-tab" data-bs-toggle="tab" data-bs-target="#qrPane" type="button" role="tab" aria-controls="qrPane" aria-selected="false">
                        <i class="fas fa-qrcode"></i> QR Codes
                      </button>
                    </li>
                    <li class="nav-item" role="presentation">
                      <button class="nav-link" id="reports-tab" data-bs-toggle="tab" data-bs-target="#reportsPane" type="button" role="tab" aria-controls="reportsPane" aria-selected="false">
                        <i class="fas fa-file-shield"></i> Reports &amp; Exports
                      </button>
                    </li>
                  </ul>

                  <div class="tab-content" id="settingsTabsContent">

                    <!-- ============ BRANDING TAB ============ -->
                    <div class="tab-pane fade show active" id="brandingPane" role="tabpanel" aria-labelledby="branding-tab">

                      <div class="row g-3">
                        <!-- Sidebar Logo -->
                        <div class="col-12 col-lg-6">
                          <div class="settings-card h-100">
                            <div class="settings-card-head">
                              <h5>Sidebar Logo</h5>
                              <span class="head-icon"><i class="fas fa-image"></i></span>
                            </div>
                            <div class="settings-card-body">
                              <form action="upload_logo.php" method="POST" enctype="multipart/form-data">
                                <div class="mb-3">
                                  <label class="form-label fw-bold">Current Sidebar Logo</label>
                                  <div class="preview-thumb mt-1">
                                    <img src="<?php echo $logoPath; ?>" alt="Sidebar Logo" style="height: 60px;">
                                  </div>
                                </div>
                                <div class="mb-3">
                                  <label for="logo" class="form-label">Upload New Sidebar Logo</label>
                                  <input class="form-control" type="file" id="logo" name="logo" accept="image/*" required>
                                  <small class="text-muted">Recommended: square PNG with transparent background.</small>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                  <i class="fas fa-upload me-1"></i> Update Sidebar Logo
                                </button>
                              </form>
                            </div>
                          </div>
                        </div>

                        <!-- Mini Logo -->
                        <div class="col-12 col-lg-6">
                          <div class="settings-card h-100">
                            <div class="settings-card-head">
                              <h5>Navbar Mini Logo</h5>
                              <span class="head-icon"><i class="fas fa-grip-horizontal"></i></span>
                            </div>
                            <div class="settings-card-body">
                              <form action="upload_logo.php" method="POST" enctype="multipart/form-data">
                                <div class="mb-3">
                                  <label class="form-label fw-bold">Current Mini Logo</label>
                                  <div class="preview-thumb mt-1">
                                    <img src="<?php echo $miniLogoPath ?? 'img/default-mini.png'; ?>" alt="Mini Logo" style="height: 40px;">
                                  </div>
                                </div>
                                <div class="mb-3">
                                  <label for="mini_logo" class="form-label">Upload New Mini Logo</label>
                                  <input class="form-control" type="file" id="mini_logo" name="mini_logo" accept="image/*" required>
                                  <small class="text-muted">Used in the top navigation bar. Aim for 40×40px.</small>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                  <i class="fas fa-upload me-1"></i> Update Mini Logo
                                </button>
                              </form>
                            </div>
                          </div>
                        </div>

                        <!-- Favicon -->
                        <div class="col-12 col-lg-6">
                          <div class="settings-card h-100">
                            <div class="settings-card-head">
                              <h5>Tab Icon (Favicon)</h5>
                              <span class="head-icon"><i class="fas fa-window-maximize"></i></span>
                            </div>
                            <div class="settings-card-body">
                              <form action="upload_logo.php" method="POST" enctype="multipart/form-data">
                                <div class="mb-3">
                                  <label class="form-label fw-bold">Current Tab Icon</label>
                                  <div class="preview-thumb mt-1">
                                    <img src="<?php echo $faviconPath ?? 'img/default-favicon.png'; ?>" alt="Favicon" style="height: 32px; width: 32px;">
                                  </div>
                                </div>
                                <div class="mb-3">
                                  <label for="favicon" class="form-label">Upload New Favicon</label>
                                  <input class="form-control" type="file" id="favicon" name="favicon" accept="image/x-icon,image/png" required>
                                  <small class="text-muted">PNG or ICO format. Appears in the browser tab.</small>
                                </div>
                                <button type="submit" name="update_favicon" class="btn btn-primary">
                                  <i class="fas fa-upload me-1"></i> Update Favicon
                                </button>
                              </form>
                            </div>
                          </div>
                        </div>

                      </div>

                    </div><!-- /#brandingPane -->

                    <!-- ============ THEME TAB ============ -->
                    <div class="tab-pane fade" id="themePane" role="tabpanel" aria-labelledby="theme-tab">

                      <div class="row g-3">
                        <div class="col-12">
                          <div class="settings-card">
                            <div class="settings-card-head">
                              <h5>Display Mode</h5>
                              <span class="head-icon"><i class="fas fa-moon"></i></span>
                            </div>
                            <div class="settings-card-body">
                              <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="darkModeSwitch" aria-describedby="darkModeHelp">
                                <label class="form-check-label fw-semibold" for="darkModeSwitch">Dark mode</label>
                              </div>
                              <div id="darkModeHelp" class="form-text">Applies to the admin panel. You can also use the floating button at the bottom-right of any page.</div>
                            </div>
                          </div>
                        </div>
                        <div class="col-12">
                          <div class="alert alert-light border small mb-0">
                            <i class="fas fa-info-circle me-1 text-primary"></i>
                            Pick a color palette below, then click <strong>Save Color Scheme</strong>. Most admins never need custom colors.
                          </div>
                        </div>
                        <!-- Color Scheme -->
                        <div class="col-12 col-xl-8">
                          <div class="settings-card h-100">
                            <div class="settings-card-head">
                              <h5>Admin Color Scheme</h5>
                              <span class="head-icon"><i class="fas fa-palette"></i></span>
                            </div>
                            <div class="settings-card-body">
                              <div class="mb-4">
                                <label class="form-label fw-semibold">Pick a ready-made palette</label>
                                <p class="text-muted small mb-2">Click a palette to preview it on this page instantly.</p>
                                <div class="d-flex flex-wrap gap-2" id="paletteContainer"></div>
                              </div>

                              <form action="upload_logo.php" method="POST">
                                <div class="d-flex justify-content-end mb-3">
                                  <button type="submit" name="update_colors" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Save Color Scheme
                                  </button>
                                </div>

                                <details class="settings-advanced border rounded p-3">
                                  <summary class="fw-semibold user-select-none">Custom colors (optional)</summary>
                                  <p class="text-muted small mt-3 mb-3">Only use this if none of the palettes above fit your brand.</p>
                                  <div class="row g-3">
                                    <div class="col-12 col-md-4">
                                      <label class="form-label fw-semibold" for="navbarColor">Top bar</label>
                                      <input type="color" id="navbarColor" name="navbar_bg_color"
                                            value="<?php echo isset($navbarBg) ? $navbarBg : '#10172A'; ?>"
                                            class="form-control form-control-color w-100" style="height:48px;">
                                    </div>
                                    <div class="col-12 col-md-4">
                                      <label class="form-label fw-semibold" for="sidebarColor">Menu background</label>
                                      <input type="color" id="sidebarColor" name="sidebar_bg_color"
                                            value="<?php echo isset($sidebarBg) ? $sidebarBg : '#C2C2CC'; ?>"
                                            class="form-control form-control-color w-100" style="height:48px;">
                                    </div>
                                    <div class="col-12 col-md-4">
                                      <label class="form-label fw-semibold" for="sidebarTextColor">Menu text</label>
                                      <input type="color" id="sidebarTextColor" name="sidebar_text_color"
                                            value="<?php echo isset($sidebarText) ? $sidebarText : '#FFFFFF'; ?>"
                                            class="form-control form-control-color w-100" style="height:48px;">
                                    </div>
                                  </div>
                                </details>
                              </form>
                            </div>
                          </div>
                        </div>

                        <div class="col-12">
                          <div class="alert alert-light border small mb-0">
                            <i class="fas fa-flag me-1 text-primary"></i>
                            Registration form banner, colors, and registration QR URL are in
                            <a href="nomination_settings.php" class="fw-semibold">Customizations &rarr; Registration Settings</a>.
                          </div>
                        </div>
                      </div>

                    </div><!-- /#themePane -->

                    <!-- ============ QR CODES TAB ============ -->
                    <div class="tab-pane fade" id="qrPane" role="tabpanel" aria-labelledby="qr-tab">

                      <div class="row g-3 mb-3" id="qr-public-urls">
                        <div class="col-12">
                          <div class="settings-card">
                            <div class="settings-card-head">
                              <h5>Public share links</h5>
                              <span class="head-icon"><i class="fas fa-link"></i></span>
                            </div>
                            <div class="settings-card-body">
                              <p class="small text-muted mb-2">
                                Site root and short URLs (<code>/vote/</code>, <code>/register/</code>, <code>/track/</code>, <code>/vote/{business}/</code>)
                                are edited in one place only.
                              </p>
                              <a class="btn btn-primary btn-sm" href="public_url_config.php">
                                <i class="bi bi-link-45deg me-1"></i> Open Public Share Links
                              </a>
                            </div>
                          </div>
                        </div>
                      </div>

                  <!-- QR poster design (single place to customize voting QR posters) -->
                  <div class="settings-card mb-4" id="qr-frame-settings">
                    <div class="settings-card-head">
                      <h5 class="d-flex align-items-center gap-2 mb-0">
                        QR poster design
                        <span class="badge bg-secondary">Admin-wide</span>
                      </h5>
                      <span class="head-icon"><i class="fas fa-qrcode"></i></span>
                    </div>
                    <div class="settings-card-body">
                    <form method="POST" enctype="multipart/form-data" class="row g-3" id="qrFrameForm">
                      <input type="hidden" name="save_qr_frame_settings" value="1">
                      <input type="hidden" name="frame_path" id="frame_path" value="<?php echo htmlspecialchars($qrFramePathRaw ?? '', ENT_QUOTES); ?>">

                      <div class="col-12">
                        <div class="alert alert-light border small mb-2">
                          <i class="fas fa-info-circle me-1 text-primary"></i>
                          Configure poster frames and QR colors here for <strong>all establishments</strong>. The live preview beside the position editor uses the
                          <strong>same generator</strong> as <a href="choices.php" class="fw-semibold">Businesses</a> &rarr; Generate / Regenerate QR.
                          Unsaved changes appear in the preview immediately; click <strong>Save QR Settings</strong>, then regenerate posters on Businesses.
                        </div>
                        <ol class="qr-setup-steps mb-0">
                          <li><strong>1.</strong> Upload frame artwork</li>
                          <li><strong>2.</strong> Drag the blue box onto the white/open area of your frame</li>
                          <li><strong>3.</strong> Check live preview, then save</li>
                          <li><strong>4.</strong> Generate on Businesses</li>
                        </ol>
                      </div>

                      <div class="col-12 col-md-7">
                        <label for="frame_file" class="form-label fw-semibold">Upload poster frame</label>
                        <input class="form-control" type="file" id="frame_file" name="frame_file" accept="image/png,image/jpeg,image/webp,image/gif">
                        <small class="text-muted">PNG/JPG poster artwork. Template size is shown in the position editor below.</small>

                        <div class="mt-3">
                          <label class="form-label fw-semibold" for="default_preset">Poster layout preset</label>
                          <select class="form-select" id="default_preset" name="default_preset">
                            <?php foreach ($qrPresetCatalog as $presetKey => $presetMeta): ?>
                              <option value="<?php echo htmlspecialchars($presetKey, ENT_QUOTES); ?>" <?php echo $qrDefaultPreset === $presetKey ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($presetMeta['label'], ENT_QUOTES); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <small class="text-muted" id="presetDescription">
                            <?php echo htmlspecialchars($qrPresetCatalog[$qrDefaultPreset]['description'] ?? '', ENT_QUOTES); ?>
                          </small>
                          <p class="form-text mb-0">Presets are a starting point — drag the blue box to match your frame&rsquo;s QR opening, then check the preview on the right.</p>
                        </div>
                        <input type="hidden" name="current_frame_path" value="<?php echo htmlspecialchars($qrFramePathRaw ?? '', ENT_QUOTES); ?>">
                      </div>

                      <div class="col-12 col-md-5 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                          <input class="form-check-input" type="checkbox" role="switch" id="use_frame_toggle" name="use_frame" <?php echo !empty($qrFrameUse) ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="use_frame_toggle">Use poster frame on generated QR codes</label>
                        </div>
                      </div>

                      <div class="col-12">
                        <div class="row g-3 align-items-stretch qr-workspace-row">
                          <div class="col-12 col-lg-6">
                            <div class="qr-layout-editor-card border rounded p-3 h-100">
                              <h6 class="fw-semibold mb-1"><i class="fas fa-arrows-alt me-1 text-primary"></i> Position QR on frame</h6>
                              <p class="text-muted small mb-2">Drag and resize the <strong>blue box</strong> over the area where the QR should print. The box stays <strong>square</strong> (same as the QR). The preview on the right updates automatically.</p>
                              <div id="qrLayoutEditorEmpty" class="alert alert-warning small py-2 mb-2 <?php echo !empty($qrFramePathResolved) ? 'd-none' : ''; ?>">Upload a frame image to enable positioning.</div>
                              <div class="qr-layout-editor mx-auto" id="qrLayoutEditor" <?php echo empty($qrFramePathResolved) ? 'hidden' : ''; ?>>
                                <div class="qr-layout-editor-inner" id="qrLayoutEditorInner">
                                  <img id="qrLayoutEditorImg" src="<?php echo !empty($qrFramePathResolved) ? htmlspecialchars($qrFramePathResolved, ENT_QUOTES) : ''; ?>" alt="Frame for QR positioning" class="qr-layout-editor-bg">
                                  <div id="qrPlacementRect" class="qr-placement-rect" role="application" aria-label="QR placement area — drag to move, corner handle to resize">
                                    <span class="qr-placement-label">QR here</span>
                                    <div class="resize-handle" aria-hidden="true"></div>
                                  </div>
                                </div>
                              </div>
                              <p class="small text-muted mt-2 mb-0" id="qrPlacementCoords">
                                <?php if (!empty($qrFramePathResolved)): ?>
                                  Template <?php echo (int)$qrFrameNatW; ?>×<?php echo (int)$qrFrameNatH; ?> px —
                                  placement: X <?php echo (int)($qrFrameOptions['box_x'] ?? 0); ?>,
                                  Y <?php echo (int)($qrFrameOptions['box_y'] ?? 0); ?>,
                                  W <?php echo (int)($qrFrameOptions['box_w'] ?? 0); ?>,
                                  H <?php echo (int)($qrFrameOptions['box_h'] ?? 0); ?>
                                <?php endif; ?>
                              </p>
                            </div>
                          </div>

                          <div class="col-12 col-lg-6">
                            <div class="qr-preview-card p-3 h-100 d-flex flex-column">
                              <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                <h6 class="fw-semibold mb-0">
                                  <i class="fas fa-eye me-1 text-primary"></i> Live poster preview
                                </h6>
                                <span class="badge bg-secondary" id="qrPreviewStatusBadge">Loading…</span>
                              </div>
                              <p class="text-muted small mb-2">
                                Final composed poster (frame + QR). Should match the blue box on the left.
                              </p>
                              <div class="d-flex flex-wrap gap-2 mb-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="qrPreviewRefreshBtn">
                                  <i class="fas fa-sync-alt me-1"></i> Refresh preview
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="applyLayoutPresetBtn">
                                  <i class="fas fa-magic me-1"></i> Re-apply layout preset
                                </button>
                              </div>
                              <div class="preview-wrapper p-2 flex-grow-1">
                                <div class="preview-placeholder" id="qrPreviewPlaceholder">Building preview…</div>
                                <img id="qrPreviewImage" alt="Composed QR poster preview" src="" loading="lazy">
                              </div>
                              <p class="small text-muted mt-2 mb-0" id="qrPreviewFootnote"></p>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="col-12">
                        <h6 class="fw-semibold mb-2">QR appearance</h6>
                        <p class="text-muted small mb-2">Colors and center logo apply to <strong>voting</strong> posters and <strong>registration</strong> event QRs. Public URLs are configured separately above and in Registration Settings.</p>
                        <div class="row g-3 align-items-end">
                          <div class="col-12 col-md-4">
                            <div class="form-check form-switch mb-2">
                              <input class="form-check-input" type="checkbox" role="switch" id="use_center_logo" name="use_center_logo" <?php echo !empty($qrStyleConfig['use_center_logo']) ? 'checked' : ''; ?>>
                              <label class="form-check-label" for="use_center_logo">Show logo inside QR</label>
                            </div>
                            <?php if (!empty($qrLogoPathResolved)): ?>
                              <div class="border rounded p-2 bg-light-subtle text-center mb-2">
                                <img id="qrLogoThumb" src="<?php echo htmlspecialchars($qrLogoPathResolved, ENT_QUOTES); ?>" alt="Center logo" class="img-fluid" style="max-height:72px;object-fit:contain;">
                              </div>
                            <?php else: ?>
                              <div class="border rounded p-2 bg-light-subtle text-center mb-2 text-muted small" id="qrLogoThumbWrap">No logo uploaded</div>
                              <img id="qrLogoThumb" src="" alt="" class="img-fluid d-none" style="max-height:72px;object-fit:contain;">
                            <?php endif; ?>
                            <input type="hidden" name="qr_logo_path" id="qr_logo_path" value="<?php echo htmlspecialchars($qrLogoPathRaw, ENT_QUOTES); ?>">
                            <input class="form-control form-control-sm" type="file" name="qr_logo_file" id="qr_logo_file" accept="image/png,image/webp,image/gif">
                            <p class="form-text small mb-0">Use a <strong>PNG with transparent background</strong>. JPEG or opaque exports often show a black or white box behind the logo.</p>
                          </div>
                          <div class="col-6 col-md-2">
                            <label class="form-label" for="qr_fg_color">QR color</label>
                            <input type="color" class="form-control form-control-color w-100" id="qr_fg_color" name="qr_fg_color" value="<?php echo htmlspecialchars($qrStyleConfig['fg_color'] ?? '#000000', ENT_QUOTES); ?>">
                          </div>
                          <div class="col-6 col-md-2">
                            <label class="form-label" for="qr_bg_color">Background</label>
                            <input type="color" class="form-control form-control-color w-100" id="qr_bg_color" name="qr_bg_color" value="<?php echo htmlspecialchars($qrStyleConfig['bg_color'] ?? '#ffffff', ENT_QUOTES); ?>">
                          </div>
                          <div class="col-12 col-md-4">
                            <label class="form-label" for="qr_logo_size_pct">Logo size</label>
                            <input type="range" class="form-range" id="qr_logo_size_pct" name="qr_logo_size_pct" min="8" max="30" step="1" value="<?php echo (int)($qrStyleConfig['logo_size_pct'] ?? 18); ?>">
                            <div class="small text-muted"><span id="qr_logo_size_val"><?php echo (int)($qrStyleConfig['logo_size_pct'] ?? 18); ?></span>% of QR — keep at 20% or below for reliable scanning</div>
                          </div>
                        </div>
                        <input type="hidden" id="label_color" name="label_color" value="<?php echo htmlspecialchars($qrStyleConfig['label_color'] ?? '#000000', ENT_QUOTES); ?>">
                      </div>

                      <div class="d-none" id="qrLayoutFields" aria-hidden="true">
                        <input type="number" id="box_x" name="frame_box_x" value="<?php echo (int)($qrFrameOptions['box_x'] ?? 500); ?>" min="0">
                        <input type="number" id="box_y" name="frame_box_y" value="<?php echo (int)($qrFrameOptions['box_y'] ?? 1000); ?>" min="0">
                        <input type="number" id="box_w" name="frame_box_w" value="<?php echo (int)($qrFrameOptions['box_w'] ?? 660); ?>" min="1">
                        <input type="number" id="box_h" name="frame_box_h" value="<?php echo (int)($qrFrameOptions['box_h'] ?? 660); ?>" min="1">
                        <input type="number" id="card_side_pad" name="card_side_pad" value="<?php echo (int)($qrFrameOptions['card_side_pad'] ?? 24); ?>" min="0">
                        <input type="number" id="card_top_pad" name="card_top_pad" value="<?php echo (int)($qrFrameOptions['card_top_pad'] ?? 18); ?>" min="0">
                        <input type="number" id="gap_qr_to_label" name="gap_qr_to_label" value="<?php echo (int)($qrFrameOptions['gap_qr_to_label'] ?? 10); ?>" min="0">
                        <input type="number" id="qr_side_min" name="qr_side_min" value="<?php echo (int)($qrFrameOptions['qr_side_min'] ?? 360); ?>" min="1">
                        <input type="number" id="label_strip_h_min" name="label_strip_h_min" value="<?php echo (int)($qrFrameOptions['label_strip_h_min'] ?? 72); ?>" min="0">
                        <input type="number" id="label_strip_h_max" name="label_strip_h_max" value="<?php echo (int)($qrFrameOptions['label_strip_h_max'] ?? 140); ?>" min="0">
                        <input type="number" id="label_side_pad" name="label_side_pad" value="<?php echo (int)($qrFrameOptions['label_side_pad'] ?? 10); ?>" min="0">
                        <input type="number" id="label_top_pad" name="label_top_pad" value="<?php echo (int)($qrFrameOptions['label_top_pad'] ?? 8); ?>" min="0">
                        <input type="number" id="label_bottom_pad" name="label_bottom_pad" value="<?php echo (int)($qrFrameOptions['label_bottom_pad'] ?? 10); ?>" min="0">
                        <input type="number" id="label_line_spacing" name="label_line_spacing" value="<?php echo (int)($qrFrameOptions['label_line_spacing'] ?? 6); ?>" min="0">
                        <input type="number" id="label_font_size" name="label_font_size" value="<?php echo (int)($qrFrameOptions['label_font_size'] ?? 40); ?>" min="1">
                      </div>

                      <div class="col-12">
                        <details class="settings-advanced border rounded p-3">
                          <summary class="fw-semibold user-select-none">Advanced QR options (optional)</summary>
                          <div class="mt-3">
                            <label class="form-label" for="label_color_visible">Label text color</label>
                            <input type="color" class="form-control form-control-color mb-3" id="label_color_visible" value="<?php echo htmlspecialchars($qrStyleConfig['label_color'] ?? '#000000', ENT_QUOTES); ?>">
                            <p class="small text-muted mb-3">Use different poster frames per award category. Leave blank to use the default frame above.</p>
                            <?php if (empty($qrCategories)): ?>
                              <p class="small text-muted mb-0">No categories found for the active event.</p>
                            <?php else: ?>
                              <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-3">
                                  <thead class="table-light">
                                    <tr>
                                      <th>Category</th>
                                      <th style="min-width:180px">Upload frame</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    <?php foreach ($qrCategories as $cat):
                                      $catId = (int)$cat['category_id'];
                                      $catFramePath = $qrCategoryFrames[(string)$catId]['frame_path'] ?? '';
                                    ?>
                                      <tr>
                                        <td><?php echo htmlspecialchars($cat['category_name'], ENT_QUOTES); ?></td>
                                        <td>
                                          <input type="hidden" name="category_frame_path[<?php echo $catId; ?>]" value="<?php echo htmlspecialchars($catFramePath, ENT_QUOTES); ?>">
                                          <input type="file" class="form-control form-control-sm"
                                            name="category_frame_file_<?php echo $catId; ?>"
                                            accept="image/png,image/jpeg,image/webp,image/gif">
                                        </td>
                                      </tr>
                                    <?php endforeach; ?>
                                  </tbody>
                                </table>
                              </div>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="resetLayoutBtn">
                              <i class="fas fa-undo me-1"></i> Reset layout to defaults
                            </button>
                          </div>
                        </details>
                      </div>

                      <div class="col-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                          <i class="fas fa-save me-1"></i> Save QR Settings
                        </button>
                      </div>
                    </form>
                    </div><!-- /.settings-card-body -->
                  </div><!-- /#qr-frame-settings -->

                    </div><!-- /#qrPane -->

                    <!-- ============ REPORTS & EXPORTS TAB ============ -->
                    <div class="tab-pane fade" id="reportsPane" role="tabpanel" aria-labelledby="reports-tab">
                      <div class="row g-3">
                        <div class="col-12 col-xl-7">
                          <div class="settings-card h-100">
                            <div class="settings-card-head">
                              <h5>Official Report Letterhead</h5>
                              <span class="head-icon"><i class="fas fa-building"></i></span>
                            </div>
                            <div class="settings-card-body">
                              <form action="save_report_settings.php" method="POST">
                                <input type="hidden" name="save_report_export_settings" value="1">

                                <div class="mb-3">
                                  <label for="report_org_line" class="form-label fw-semibold">Organization line</label>
                                  <input type="text" class="form-control" id="report_org_line" name="report_org_line"
                                         value="<?php echo htmlspecialchars($reportSettings['org_line'], ENT_QUOTES); ?>"
                                         maxlength="180" required>
                                  <small class="text-muted">Shown under the title on PDF, Excel, and CSV exports.</small>
                                </div>

                                <div class="row g-3">
                                  <div class="col-md-6">
                                    <label for="report_reviewer_label" class="form-label fw-semibold">Reviewed by label</label>
                                    <input type="text" class="form-control" id="report_reviewer_label" name="report_reviewer_label"
                                           value="<?php echo htmlspecialchars($reportSettings['reviewer_label'], ENT_QUOTES); ?>"
                                           maxlength="80">
                                  </div>
                                  <div class="col-md-6">
                                    <label for="report_approver_label" class="form-label fw-semibold">Approved by label</label>
                                    <input type="text" class="form-control" id="report_approver_label" name="report_approver_label"
                                           value="<?php echo htmlspecialchars($reportSettings['approver_label'], ENT_QUOTES); ?>"
                                           maxlength="80">
                                  </div>
                                </div>

                                <hr class="my-4">

                                <h6 class="fw-semibold mb-3"><i class="fas fa-lock me-1"></i> Export protection</h6>

                                <div class="form-check form-switch mb-3">
                                  <input class="form-check-input" type="checkbox" role="switch" id="report_pdf_password_enabled"
                                         name="report_pdf_password_enabled" <?php echo $reportSettings['pdf_password_enabled'] ? 'checked' : ''; ?>>
                                  <label class="form-check-label" for="report_pdf_password_enabled">
                                    Require password to <strong>open</strong> PDF exports
                                  </label>
                                </div>
                                <div class="mb-3">
                                  <label for="report_pdf_password" class="form-label">PDF open password</label>
                                  <input type="password" class="form-control" id="report_pdf_password" name="report_pdf_password"
                                         autocomplete="new-password" placeholder="<?php echo $reportPdfHasPassword ? 'Leave blank to keep current password' : 'Set a password'; ?>">
                                  <?php if ($reportPdfHasPassword): ?>
                                    <small class="text-muted">A password is already saved. Leave blank to keep it.</small>
                                  <?php endif; ?>
                                </div>

                                <div class="form-check form-switch mb-3">
                                  <input class="form-check-input" type="checkbox" role="switch" id="report_excel_protect_enabled"
                                         name="report_excel_protect_enabled" <?php echo $reportSettings['excel_protect_enabled'] ? 'checked' : ''; ?>>
                                  <label class="form-check-label" for="report_excel_protect_enabled">
                                    Require password to <strong>edit</strong> Excel exports
                                  </label>
                                </div>
                                <div class="mb-3">
                                  <label for="report_excel_password" class="form-label">Excel edit password</label>
                                  <input type="password" class="form-control" id="report_excel_password" name="report_excel_password"
                                         autocomplete="new-password" placeholder="<?php echo $reportExcelHasPassword ? 'Leave blank to keep current password' : 'Set a password'; ?>">
                                  <small class="text-muted">Excel files can still be opened for viewing; editing and structural changes require this password.</small>
                                </div>

                                <div class="d-flex justify-content-end">
                                  <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Save Report Settings
                                  </button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>

                        <div class="col-12 col-xl-5">
                          <div class="settings-card h-100">
                            <div class="settings-card-head">
                              <h5>Your export signature</h5>
                              <span class="head-icon"><i class="fas fa-signature"></i></span>
                            </div>
                            <div class="settings-card-body">
                              <p class="text-muted small">
                                Upload a PNG or JPG signature image for this admin account
                                (<strong><?php echo htmlspecialchars((string) ($_SESSION['username'] ?? ''), ENT_QUOTES); ?></strong>).
                                Downloaded reports no longer include a certification or signature block.
                              </p>

                              <?php if ($adminSignatureWebPath): ?>
                                <div class="border rounded p-3 mb-3 bg-light-subtle text-center">
                                  <img src="<?php echo htmlspecialchars($adminSignatureWebPath, ENT_QUOTES); ?>"
                                       alt="Current signature" style="max-height: 80px; max-width: 100%; object-fit: contain;">
                                </div>
                              <?php else: ?>
                                <div class="alert alert-light border small mb-3">No signature uploaded yet.</div>
                              <?php endif; ?>

                              <form action="upload_admin_signature.php" method="POST" enctype="multipart/form-data" class="mb-3">
                                <input type="hidden" name="upload_admin_signature" value="1">
                                <div class="mb-3">
                                  <label for="admin_signature" class="form-label">Upload signature image</label>
                                  <input class="form-control" type="file" id="admin_signature" name="admin_signature"
                                         accept="image/png,image/jpeg,image/webp" required>
                                  <small class="text-muted">Transparent PNG recommended. Max 2 MB.</small>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                  <i class="fas fa-upload me-1"></i> Upload Signature
                                </button>
                              </form>

                              <?php if ($adminSignatureWebPath): ?>
                                <form action="upload_admin_signature.php" method="POST" onsubmit="return confirm('Remove your saved signature?');">
                                  <input type="hidden" name="remove_admin_signature" value="1">
                                  <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="fas fa-trash me-1"></i> Remove Signature
                                  </button>
                                </form>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>

                        <div class="col-12">
                          <div class="settings-card">
                            <div class="settings-card-head">
                              <h5>Official reviewer &amp; approver signatures</h5>
                              <span class="head-icon"><i class="fas fa-file-signature"></i></span>
                            </div>
                            <div class="settings-card-body">
                              <p class="text-muted small mb-4">
                                These organization-wide signatures are stored with report settings.
                                Downloaded reports no longer include a certification or signature block.
                              </p>
                              <div class="row g-4">
                                <div class="col-md-6">
                                  <h6 class="fw-semibold">Reviewer signature</h6>
                                  <?php if ($reviewerSignatureWebPath): ?>
                                    <div class="border rounded p-3 mb-3 bg-light-subtle text-center">
                                      <img src="<?php echo htmlspecialchars($reviewerSignatureWebPath, ENT_QUOTES); ?>"
                                           alt="Reviewer signature" style="max-height: 80px; max-width: 100%; object-fit: contain;">
                                    </div>
                                  <?php else: ?>
                                    <div class="alert alert-light border small mb-3">No reviewer signature uploaded.</div>
                                  <?php endif; ?>
                                  <form action="upload_org_report_signatures.php" method="POST" enctype="multipart/form-data" class="mb-2">
                                    <input type="hidden" name="upload_reviewer_signature" value="1">
                                    <div class="mb-2">
                                      <input class="form-control form-control-sm" type="file" name="reviewer_signature"
                                             accept="image/png,image/jpeg,image/webp" required>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary">
                                      <i class="fas fa-upload me-1"></i> Upload reviewer signature
                                    </button>
                                  </form>
                                  <?php if ($reviewerSignatureWebPath): ?>
                                    <form action="upload_org_report_signatures.php" method="POST" class="d-inline"
                                          onsubmit="return confirm('Remove the reviewer signature?');">
                                      <input type="hidden" name="remove_reviewer_signature" value="1">
                                      <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-trash me-1"></i> Remove
                                      </button>
                                    </form>
                                  <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                  <h6 class="fw-semibold">Approver signature</h6>
                                  <?php if ($approverSignatureWebPath): ?>
                                    <div class="border rounded p-3 mb-3 bg-light-subtle text-center">
                                      <img src="<?php echo htmlspecialchars($approverSignatureWebPath, ENT_QUOTES); ?>"
                                           alt="Approver signature" style="max-height: 80px; max-width: 100%; object-fit: contain;">
                                    </div>
                                  <?php else: ?>
                                    <div class="alert alert-light border small mb-3">No approver signature uploaded.</div>
                                  <?php endif; ?>
                                  <form action="upload_org_report_signatures.php" method="POST" enctype="multipart/form-data" class="mb-2">
                                    <input type="hidden" name="upload_approver_signature" value="1">
                                    <div class="mb-2">
                                      <input class="form-control form-control-sm" type="file" name="approver_signature"
                                             accept="image/png,image/jpeg,image/webp" required>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary">
                                      <i class="fas fa-upload me-1"></i> Upload approver signature
                                    </button>
                                  </form>
                                  <?php if ($approverSignatureWebPath): ?>
                                    <form action="upload_org_report_signatures.php" method="POST" class="d-inline"
                                          onsubmit="return confirm('Remove the approver signature?');">
                                      <input type="hidden" name="remove_approver_signature" value="1">
                                      <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-trash me-1"></i> Remove
                                      </button>
                                    </form>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div><!-- /#reportsPane -->

                  </div><!-- /.tab-content -->

              </div>
              
              </main>
      <footer class="py-4 bg-light mt-auto">
        <div class="container-fluid px-4">
          <div class="small text-muted">&copy; 2026 Tatak Ormoc Consumers&rsquo; Choice Awards</div>
        </div>
      </footer>
</div>
      </div>

<!-- Toast container (center) -->
<div class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1080">
  <div id="globalToast" class="toast align-items-center text-bg-success border-0 mx-auto" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div id="globalToastBody" class="toast-body">Success</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<!-- Scripts -->
<script>
/* ---------- QR poster preview (same compose path as Establishments generate) ---------- */
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("qrFrameForm");
  const previewImg = document.getElementById("qrPreviewImage");
  const placeholderEl = document.getElementById("qrPreviewPlaceholder");
  const statusBadge = document.getElementById("qrPreviewStatusBadge");
  const footnoteEl = document.getElementById("qrPreviewFootnote");
  const refreshBtn = document.getElementById("qrPreviewRefreshBtn");
  const applyPresetBtn = document.getElementById("applyLayoutPresetBtn");
  const layoutEditorImg = document.getElementById("qrLayoutEditorImg");
  const layoutEditor = document.getElementById("qrLayoutEditor");
  const layoutEditorEmpty = document.getElementById("qrLayoutEditorEmpty");
  const placementRect = document.getElementById("qrPlacementRect");
  const placementCoordsEl = document.getElementById("qrPlacementCoords");
  const frameFileInput = document.getElementById("frame_file");
  const useFrameToggle = document.getElementById("use_frame_toggle");

  if (!form) return;

  form.addEventListener("submit", (e) => {
    const errs = [];
    const hexOk = (v) => /^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(String(v || "").trim());

    if (useFrameToggle && useFrameToggle.checked) {
      const bx = num("box_x");
      const by = num("box_y");
      const bw = num("box_w");
      const bh = num("box_h");
      const side = Math.min(bw, bh);
      if (side < 40) {
        errs.push("QR placement box must be at least 40×40 pixels.");
      }
      if (frameNaturalW > 0 && frameNaturalH > 0) {
        if (bx < 0 || by < 0 || bx + bw > frameNaturalW || by + bh > frameNaturalH) {
          errs.push("QR placement must fit inside the frame image.");
        }
      }
      const framePathEl = document.getElementById("frame_path");
      if (framePathEl && !String(framePathEl.value || "").trim()) {
        errs.push("Upload a poster frame or turn off “Use poster frame”.");
      }
    }

    ["qr_fg_color", "qr_bg_color", "label_color"].forEach((id) => {
      const el = document.getElementById(id);
      if (el && String(el.value || "").trim() !== "" && !hexOk(el.value)) {
        errs.push("Enter valid hex colors (e.g. #010066).");
      }
    });

    const logoPctEl = document.getElementById("qr_logo_size_pct");
    if (logoPctEl) {
      const pct = parseInt(logoPctEl.value, 10);
      if (Number.isNaN(pct) || pct < 8 || pct > 30) {
        errs.push("Center logo size must be between 8% and 30%.");
      }
    }

    if (errs.length) {
      e.preventDefault();
      showToast(errs[0], "danger");
    }
  });

  if (!previewImg) return;

  const presetLayouts = <?php echo json_encode($qrPresetLayouts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const presetDescriptions = <?php
    $descOut = [];
    foreach ($qrPresetCatalog as $k => $m) {
      $descOut[$k] = $m['description'] ?? '';
    }
    echo json_encode($descOut, JSON_UNESCAPED_UNICODE);
  ?>;

  let frameNaturalW = <?php echo (int)$qrFrameNatW; ?>;
  let frameNaturalH = <?php echo (int)$qrFrameNatH; ?>;

  const layoutFieldMap = [
    ["box_x", "frame_box_x"],
    ["box_y", "frame_box_y"],
    ["box_w", "frame_box_w"],
    ["box_h", "frame_box_h"],
    ["card_side_pad", "card_side_pad"],
    ["card_top_pad", "card_top_pad"],
    ["gap_qr_to_label", "gap_qr_to_label"],
    ["qr_side_min", "qr_side_min"],
    ["label_strip_h_min", "label_strip_h_min"],
    ["label_strip_h_max", "label_strip_h_max"],
    ["label_side_pad", "label_side_pad"],
    ["label_top_pad", "label_top_pad"],
    ["label_bottom_pad", "label_bottom_pad"],
    ["label_line_spacing", "label_line_spacing"],
    ["label_font_size", "label_font_size"],
  ];

  const setNum = (id, value) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.value = String(Math.max(0, Math.round(Number(value) || 0)));
  };

  const num = (id) => {
    const el = document.getElementById(id);
    return el ? Number(el.value || 0) : 0;
  };

  function getLayoutScale() {
    if (!layoutEditorImg || !frameNaturalW) return 1;
    const w = layoutEditorImg.getBoundingClientRect().width;
    return w > 0 ? w / frameNaturalW : 1;
  }

  function normalizeSquarePlacement() {
    const side = Math.max(40, Math.min(num("box_w"), num("box_h")));
    setNum("box_w", side);
    setNum("box_h", side);
    return side;
  }

  function updatePlacementCoordsLabel() {
    if (!placementCoordsEl) return;
    const side = Math.min(num("box_w"), num("box_h"));
    placementCoordsEl.textContent =
      "Template " + frameNaturalW + "×" + frameNaturalH + " px — placement: " +
      "X " + num("box_x") + ", Y " + num("box_y") + ", size " + side + "×" + side + " px";
  }

  function syncPadsForPlacement() {
    const boxW = num("box_w");
    const boxH = num("box_h");
    const pad = Math.max(2, Math.round(Math.min(boxW, boxH) * 0.015));
    setNum("card_side_pad", pad);
    setNum("card_top_pad", pad);
    setNum("gap_qr_to_label", 0);
    setNum("label_strip_h_min", 0);
    setNum("label_strip_h_max", 0);
  }

  function syncOverlayFromFields() {
    if (!placementRect || !layoutEditorImg) return;
    const side = normalizeSquarePlacement();
    const scale = getLayoutScale();
    const x = num("box_x");
    const y = num("box_y");
    placementRect.style.left = (x * scale) + "px";
    placementRect.style.top = (y * scale) + "px";
    placementRect.style.width = (side * scale) + "px";
    placementRect.style.height = (side * scale) + "px";
    updatePlacementCoordsLabel();
  }

  function syncFieldsFromOverlay() {
    if (!placementRect) return;
    const scale = getLayoutScale();
    if (scale <= 0) return;
    const maxW = frameNaturalW || 1600;
    const maxH = frameNaturalH || 2000;
    let x = Math.round(parseFloat(placementRect.style.left || "0") / scale);
    let y = Math.round(parseFloat(placementRect.style.top || "0") / scale);
    let side = Math.round(parseFloat(placementRect.style.width || "0") / scale);
    const minSize = 40;
    side = Math.max(minSize, side);
    x = Math.max(0, Math.min(x, maxW - side));
    y = Math.max(0, Math.min(y, maxH - side));
    setNum("box_x", x);
    setNum("box_y", y);
    setNum("box_w", side);
    setNum("box_h", side);
    syncPadsForPlacement();
    updatePlacementCoordsLabel();
  }

  function showLayoutEditor(hasFrame) {
    if (layoutEditor) layoutEditor.hidden = !hasFrame;
    if (layoutEditorEmpty) layoutEditorEmpty.classList.toggle("d-none", !!hasFrame);
  }

  function setupPlacementDrag() {
    if (!placementRect) return;

    const handle = placementRect.querySelector(".resize-handle");
    let pointerId = null;
    let mode = "move";
    let startX = 0;
    let startY = 0;
    let startLeft = 0;
    let startTop = 0;
    let startW = 0;
    let startH = 0;

    placementRect.addEventListener("pointerdown", (e) => {
      if (!layoutEditorImg || !layoutEditorImg.src) return;
      mode = handle && e.target === handle ? "resize" : "move";
      pointerId = e.pointerId;
      startX = e.clientX;
      startY = e.clientY;
      startLeft = parseFloat(placementRect.style.left || "0");
      startTop = parseFloat(placementRect.style.top || "0");
      startW = placementRect.offsetWidth;
      startH = placementRect.offsetHeight;
      placementRect.setPointerCapture(pointerId);
      e.preventDefault();
    });

    placementRect.addEventListener("pointermove", (e) => {
      if (pointerId === null || !placementRect.hasPointerCapture(pointerId)) return;
      const scale = getLayoutScale();
      const maxDispW = layoutEditorImg.getBoundingClientRect().width;
      const maxDispH = layoutEditorImg.getBoundingClientRect().height;
      const dx = e.clientX - startX;
      const dy = e.clientY - startY;

      if (mode === "move") {
        let left = startLeft + dx;
        let top = startTop + dy;
        left = Math.max(0, Math.min(left, maxDispW - startW));
        top = Math.max(0, Math.min(top, maxDispH - startH));
        placementRect.style.left = left + "px";
        placementRect.style.top = top + "px";
      } else {
        const delta = Math.max(dx, dy);
        let side = Math.max(24, Math.max(startW, startH) + delta);
        if (startLeft + side > maxDispW) side = maxDispW - startLeft;
        if (startTop + side > maxDispH) side = maxDispH - startTop;
        placementRect.style.width = side + "px";
        placementRect.style.height = side + "px";
      }
    });

    const endDrag = () => {
      if (pointerId !== null && placementRect.hasPointerCapture(pointerId)) {
        placementRect.releasePointerCapture(pointerId);
      }
      pointerId = null;
      syncFieldsFromOverlay();
      schedulePreviewRefresh();
    };

    placementRect.addEventListener("pointerup", endDrag);
    placementRect.addEventListener("pointercancel", endDrag);
  }

  setupPlacementDrag();

  if (layoutEditorImg) {
    layoutEditorImg.addEventListener("load", () => {
      frameNaturalW = layoutEditorImg.naturalWidth || frameNaturalW;
      frameNaturalH = layoutEditorImg.naturalHeight || frameNaturalH;
      syncOverlayFromFields();
    });
  }

  window.addEventListener("resize", () => {
    syncOverlayFromFields();
  });

  function setPreviewStatus(kind, text) {
    if (!statusBadge) return;
    statusBadge.textContent = text;
    statusBadge.className = "badge " + (
      kind === "ok" ? "bg-success" :
      kind === "warn" ? "bg-warning text-dark" :
      kind === "err" ? "bg-danger" : "bg-secondary"
    );
  }

  function computePresetLayout(name, w, h) {
    w = Math.max(400, w || 1600);
    h = Math.max(400, h || 2000);
    let boxW, boxH, side;
    switch (name) {
      case "center_fit": {
        const sideLen = Math.round(Math.min(w, h) * 0.88);
        const pad = Math.max(8, Math.round(sideLen * 0.035));
        const boxX = Math.round((w - sideLen) / 2);
        const boxY = Math.round((h - sideLen) / 2);
        return {
          frame_box_x: boxX,
          frame_box_y: boxY,
          frame_box_w: sideLen,
          frame_box_h: sideLen,
          card_side_pad: pad,
          card_top_pad: pad,
          qr_side_min: Math.max(40, Math.round(sideLen * 0.82)),
          label_strip_h_min: 0,
          label_strip_h_max: 0,
          gap_qr_to_label: 0,
        };
      }
      case "compact":
        boxW = Math.round(w * 0.7);
        boxH = Math.round(h * 0.7);
        side = Math.round(Math.min(boxW, boxH) * 0.25);
        break;
      case "large_qr":
        boxW = Math.round(w * 0.8);
        boxH = Math.round(h * 0.8);
        side = Math.round(Math.min(boxW, boxH) * 0.5);
        break;
      case "social": {
        const sideLen = Math.round(Math.min(w, h) * 0.72);
        boxW = sideLen;
        boxH = sideLen;
        side = Math.round(sideLen * 0.62);
        const layout = {
          frame_box_x: Math.round((w - sideLen) / 2),
          frame_box_y: Math.round((h - sideLen) / 2),
          frame_box_w: sideLen,
          frame_box_h: sideLen,
          card_side_pad: Math.round((sideLen - side) / 2),
          card_top_pad: Math.max(12, Math.round(sideLen * 0.08)),
          qr_side_min: Math.max(40, side),
          label_strip_h_min: 56,
          label_strip_h_max: 100,
        };
        return layout;
      }
      case "standard":
      default:
        boxW = Math.round(w * 0.8);
        boxH = Math.round(h * 0.8);
        side = Math.round(Math.min(boxW, boxH) * 0.35);
        break;
    }
    const boxX = Math.round((w - boxW) / 2);
    const boxY = Math.round((h - boxH) / 2);
    const sidePad = Math.round((boxW - side) / 2);
    const topPad = Math.round((boxH - side) / 2);
    return {
      frame_box_x: boxX,
      frame_box_y: boxY,
      frame_box_w: boxW,
      frame_box_h: boxH,
      card_side_pad: Math.max(0, sidePad),
      card_top_pad: Math.max(0, topPad),
      qr_side_min: Math.max(40, side),
      label_strip_h_min: 72,
      label_strip_h_max: 140,
    };
  }

  function applyLayoutFromPreset(name) {
    const layout = computePresetLayout(name, frameNaturalW, frameNaturalH) || presetLayouts[name];
    if (!layout) return;
    layoutFieldMap.forEach(([inputId, layoutKey]) => {
      if (layout[layoutKey] !== undefined) {
        setNum(inputId, layout[layoutKey]);
      }
    });
    normalizeSquarePlacement();
    syncPadsForPlacement();
    syncOverlayFromFields();
  }

  function refreshPreview() {
    const fd = new FormData(form);
    const useCenterLogoToggle = document.getElementById("use_center_logo");
    fd.set("use_frame", useFrameToggle && useFrameToggle.checked ? "1" : "0");
    fd.set("use_center_logo", useCenterLogoToggle && useCenterLogoToggle.checked ? "1" : "0");
    fd.set("show_label_on_poster", "0");
    fd.set("label", "");

    if (placeholderEl) {
      placeholderEl.textContent = "Building preview…";
      placeholderEl.classList.remove("hidden");
    }
    setPreviewStatus("wait", "Generating…");

    fetch("qr_frame_preview.php", { method: "POST", body: fd, credentials: "same-origin" })
      .then(res => {
        if (!res.ok) {
          return res.json().catch(() => ({})).then(data => {
            throw new Error(data.message || `HTTP ${res.status}`);
          });
        }
        return res.json();
      })
      .then(data => {
        if (data && data.status === "ok" && data.image) {
          previewImg.src = data.image;
          if (placeholderEl) placeholderEl.classList.add("hidden");
          if (data.usedFrame) {
            setPreviewStatus("ok", "Frame applied — matches generated posters");
            if (footnoteEl) {
              footnoteEl.textContent = "This preview uses the same compose step as Businesses → Generate / Regenerate QR. Save QR Settings, then regenerate existing posters.";
            }
          } else {
            setPreviewStatus("warn", "Plain QR (no frame)");
            if (footnoteEl) {
              footnoteEl.textContent = "Frame was not applied. Turn on “Use poster frame”, upload a frame image, and ensure the layout preset fits your template size (" + frameNaturalW + "×" + frameNaturalH + " px).";
            }
          }
        } else {
          throw new Error((data && data.message) ? data.message : "Failed to build preview.");
        }
      })
      .catch((err) => {
        if (placeholderEl) {
          placeholderEl.textContent = err && err.message ? err.message : "Network error while generating preview.";
          placeholderEl.classList.remove("hidden");
        }
        setPreviewStatus("err", "Preview failed");
        if (footnoteEl) footnoteEl.textContent = "";
      });
  }

  let previewTimer = null;
  function schedulePreviewRefresh() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(refreshPreview, 400);
  }

  const presetSelect = document.getElementById("default_preset");
  const presetDescEl = document.getElementById("presetDescription");
  const resetLayoutBtn = document.getElementById("resetLayoutBtn");

  function onPresetChosen(name) {
    applyLayoutFromPreset(name);
    if (presetDescEl && presetDescriptions[name]) {
      presetDescEl.textContent = presetDescriptions[name];
    }
    requestAnimationFrame(() => {
      syncOverlayFromFields();
      schedulePreviewRefresh();
    });
  }

  if (presetSelect) {
    presetSelect.addEventListener("change", () => onPresetChosen(presetSelect.value));
  }
  if (applyPresetBtn && presetSelect) {
    applyPresetBtn.addEventListener("click", () => onPresetChosen(presetSelect.value));
  }
  if (resetLayoutBtn && presetSelect) {
    resetLayoutBtn.addEventListener("click", () => onPresetChosen(presetSelect.value));
  }

  function loadFrameDimensionsFromImage(img, onReady) {
    if (!img || !img.src) {
      showLayoutEditor(false);
      onReady(frameNaturalW, frameNaturalH);
      return;
    }
    showLayoutEditor(true);
    const probe = new Image();
    probe.onload = () => {
      frameNaturalW = probe.naturalWidth || frameNaturalW;
      frameNaturalH = probe.naturalHeight || frameNaturalH;
      onReady(frameNaturalW, frameNaturalH);
    };
    probe.onerror = () => onReady(frameNaturalW, frameNaturalH);
    probe.src = img.src;
  }

  if (frameFileInput) {
    frameFileInput.addEventListener("change", (e) => {
      const file = e.target.files && e.target.files[0];
      if (!file) return;
      const url = URL.createObjectURL(file);
      if (layoutEditorImg) {
        layoutEditorImg.src = url;
      }
      showLayoutEditor(true);
      const probe = new Image();
      probe.onload = () => {
        frameNaturalW = probe.naturalWidth || frameNaturalW;
        frameNaturalH = probe.naturalHeight || frameNaturalH;
        if (presetSelect) {
          presetSelect.value = "center_fit";
        }
        onPresetChosen(presetSelect ? presetSelect.value : "center_fit");
        URL.revokeObjectURL(url);
      };
      probe.src = url;
    });
  }

  if (useFrameToggle) useFrameToggle.addEventListener("change", schedulePreviewRefresh);
  if (refreshBtn) refreshBtn.addEventListener("click", refreshPreview);

  const useCenterLogoEl = document.getElementById("use_center_logo");
  const qrLogoSizeEl = document.getElementById("qr_logo_size_pct");
  const qrLogoSizeValEl = document.getElementById("qr_logo_size_val");
  const qrLogoFileEl = document.getElementById("qr_logo_file");
  const qrLogoThumbEl = document.getElementById("qrLogoThumb");

  if (useCenterLogoEl) useCenterLogoEl.addEventListener("change", schedulePreviewRefresh);
  ["qr_fg_color", "qr_bg_color"].forEach((id) => {
    document.getElementById(id)?.addEventListener("input", schedulePreviewRefresh);
  });
  const labelColorHidden = document.getElementById("label_color");
  const labelColorVisible = document.getElementById("label_color_visible");
  if (labelColorVisible && labelColorHidden) {
    labelColorVisible.addEventListener("input", () => {
      labelColorHidden.value = labelColorVisible.value;
      schedulePreviewRefresh();
    });
  }
  if (qrLogoSizeEl) {
    qrLogoSizeEl.addEventListener("input", () => {
      if (qrLogoSizeValEl) qrLogoSizeValEl.textContent = qrLogoSizeEl.value;
      schedulePreviewRefresh();
    });
  }
  if (qrLogoFileEl) {
    qrLogoFileEl.addEventListener("change", (e) => {
      const file = e.target.files && e.target.files[0];
      if (file && qrLogoThumbEl) {
        qrLogoThumbEl.src = URL.createObjectURL(file);
        qrLogoThumbEl.classList.remove("d-none");
        const wrap = document.getElementById("qrLogoThumbWrap");
        if (wrap) wrap.classList.add("d-none");
      }
      schedulePreviewRefresh();
    });
  }

  const qrTabBtn = document.getElementById("qr-tab");
  if (qrTabBtn) {
    qrTabBtn.addEventListener("shown.bs.tab", () => {
      window.requestAnimationFrame(() => {
        syncOverlayFromFields();
        refreshPreview();
      });
    });
  }

  loadFrameDimensionsFromImage(layoutEditorImg, () => {
    if (placementRect && num("box_w") < 40) {
      onPresetChosen(presetSelect ? presetSelect.value : "center_fit");
    } else {
      syncOverlayFromFields();
      refreshPreview();
    }
  });
});

/* ---------- Palettes ---------- */
const palettes = [
  { n:"Navy", navbar:"#0F172A", sidebar:"#1E293B", text:"#E2E8F0" },
  { n:"Deep Blue", navbar:"#0D47A1", sidebar:"#1976D2", text:"#FFFFFF" },
  { n:"Royal", navbar:"#1D4ED8", sidebar:"#60A5FA", text:"#F9FAFB" },
  { n:"Forest", navbar:"#065F46", sidebar:"#10B981", text:"#ECFDF5" },
  { n:"Crimson", navbar:"#7F1D1D", sidebar:"#DC2626", text:"#FEE2E2" },
  { n:"Navy 2", navbar:"#0F172A", sidebar:"#1E293B", text:"#111827" },
  { n:"Deep Blue 2", navbar:"#0D47A1", sidebar:"#1976D2", text:"#111827" },
  { n:"Royal 2", navbar:"#1D4ED8", sidebar:"#60A5FA", text:"#111827" },
  { n:"Sky Blue", navbar:"#0284C7", sidebar:"#38BDF8", text:"#1E293B" },
  { n:"Ocean", navbar:"#075985", sidebar:"#0EA5E9", text:"#1F2937" },
  { n:"Steel Blue", navbar:"#1E40AF", sidebar:"#3B82F6", text:"#111827" },
  { n:"Slate Blue", navbar:"#334155", sidebar:"#64748B", text:"#0F172A" },
  { n:"Cool Aqua", navbar:"#0369A1", sidebar:"#22D3EE", text:"#1E293B" },
  { n:"Classic Blue", navbar:"#1A365D", sidebar:"#2B6CB0", text:"#111827" },
  { n:"Denim", navbar:"#1E3A8A", sidebar:"#2563EB", text:"#111827" },
  { n:"Indigo", navbar:"#312E81", sidebar:"#4338CA", text:"#111827" },
  { n:"Cerulean", navbar:"#1E6091", sidebar:"#76C893", text:"#111827" },
  { n:"Teal Blue", navbar:"#134E4A", sidebar:"#14B8A6", text:"#1E293B" },
  { n:"Midnight Blue", navbar:"#001F3F", sidebar:"#0074D9", text:"#111827" },
  { n:"Marine", navbar:"#003366", sidebar:"#3399FF", text:"#111827" },
  { n:"Azure", navbar:"#007FFF", sidebar:"#66B2FF", text:"#111827" },
  { n:"Cobalt", navbar:"#0047AB", sidebar:"#6495ED", text:"#111827" },
  { n:"Prussian Blue", navbar:"#002147", sidebar:"#1F497D", text:"#111827" },
  { n:"Sapphire", navbar:"#082567", sidebar:"#4169E1", text:"#111827" },
  { n:"Lagoon", navbar:"#006994", sidebar:"#20B2AA", text:"#111827" },
  { n:"Arctic", navbar:"#0EA5E9", sidebar:"#7DD3FC", text:"#111827" },
  { n:"Blue Gray", navbar:"#475569", sidebar:"#94A3B8", text:"#111827" },
  { n:"Cloudy Sky", navbar:"#1E293B", sidebar:"#CBD5E1", text:"#111827" }
];

const paletteContainer   = document.getElementById("paletteContainer");
const topnav             = document.querySelector(".sb-topnav");
const sidenavWrap        = document.getElementById("layoutSidenav_nav");
const sidenavNav         = document.getElementById("sidenavAccordion");

const navbarInput        = document.getElementById("navbarColor");
const sidebarBgInput     = document.getElementById("sidebarColor");
const sidebarTextInput   = document.getElementById("sidebarTextColor");

/* ---------- Save + Load from localStorage ---------- */
function saveColors(navbar, sidebarBg, sidebarText) {
  try {
    localStorage.setItem("navbarColor", navbar);
    localStorage.setItem("sidebarColor", sidebarBg);
    localStorage.setItem("sidebarTextColor", sidebarText);
  } catch (e) {}
}

function loadColors() {
  return {
    navbar: localStorage.getItem("navbarColor")      || (navbarInput ? navbarInput.value : ""),
    sidebar:localStorage.getItem("sidebarColor")     || (sidebarBgInput ? sidebarBgInput.value : ""),
    text:   localStorage.getItem("sidebarTextColor") || (sidebarTextInput ? sidebarTextInput.value : "")
  };
}

function hexLuminance(hex) {
  if (!hex) return 0.5;
  let h = String(hex).trim().replace(/^#/, "");
  if (h.length === 3) h = h.split("").map(c => c + c).join("");
  if (h.length !== 6) return 0.5;
  const r = parseInt(h.slice(0, 2), 16) / 255;
  const g = parseInt(h.slice(2, 4), 16) / 255;
  const b = parseInt(h.slice(4, 6), 16) / 255;
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function pickSidebarTextColor(bgHex, preferredText) {
  const bgLum = hexLuminance(bgHex);
  const text = preferredText || "#ffffff";
  const textLum = hexLuminance(text);
  if (bgLum < 0.45 && textLum < 0.45) return "#ffffff";
  if (bgLum >= 0.55 && textLum > 0.55) return "#1e293b";
  return text;
}

function sidebarStateColors(bgHex) {
  if (hexLuminance(bgHex) < 0.45) {
    return {
      activeTop: "rgba(255, 255, 255, 0.22)",
      activeNested: "rgba(255, 255, 255, 0.30)",
      openParent: "rgba(255, 255, 255, 0.10)",
      hover: "rgba(255, 255, 255, 0.12)"
    };
  }
  return {
    activeTop: "rgba(15, 23, 42, 0.12)",
    activeNested: "rgba(15, 23, 42, 0.16)",
    openParent: "rgba(15, 23, 42, 0.06)",
    hover: "rgba(15, 23, 42, 0.08)"
  };
}

/* ---------- Apply Sidebar theme (preview only; saved values go to DB on submit) ---------- */
function applySidebarTextColor(color, bgHex) {
  const sidebarBg = bgHex || (sidebarBgInput ? sidebarBgInput.value : "");
  const safeText = pickSidebarTextColor(sidebarBg, color);
  const states = sidebarStateColors(sidebarBg);
  let styleTag = document.getElementById("sidebarTextDynamic");
  if (!styleTag) {
    styleTag = document.createElement("style");
    styleTag.id = "sidebarTextDynamic";
    document.head.appendChild(styleTag);
  }
  styleTag.textContent = `
    :root {
      --admin-sidebar-text: ${safeText};
      --admin-sidebar-active-top: ${states.activeTop};
      --admin-sidebar-active-nested: ${states.activeNested};
      --admin-sidebar-open-parent: ${states.openParent};
      --admin-sidebar-hover: ${states.hover};
    }
    #layoutSidenav_nav,
    #layoutSidenav_nav .sb-sidenav,
    #layoutSidenav_nav .nav-link,
    #layoutSidenav_nav .nav-link i,
    #layoutSidenav_nav .sb-sidenav-collapse-arrow,
    #layoutSidenav_nav .sb-nav-link-icon {
      color: ${safeText} !important;
      fill: ${safeText} !important;
    }
  `;
  return safeText;
}

/* ---------- Preview (single clean version) ---------- */
function applyPreview({ navbar, sidebar, text }) {
  const root = document.documentElement;
  const sidebarBg = sidebar || (sidebarBgInput ? sidebarBgInput.value : "");
  let textColor = text || (sidebarTextInput ? sidebarTextInput.value : "");

  if (navbar && topnav) {
    topnav.style.setProperty("background-color", navbar, "important");
    root.style.setProperty("--admin-navbar-bg", navbar);
  }
  if (sidebarBg) {
    if (sidenavWrap) sidenavWrap.style.setProperty("background-color", sidebarBg, "important");
    if (sidenavNav)  sidenavNav.style.setProperty("background-color", sidebarBg, "important");
    root.style.setProperty("--admin-sidebar-bg", sidebarBg);
  }
  if (textColor || sidebarBg) {
    textColor = applySidebarTextColor(textColor, sidebarBg);
    if (sidebarTextInput) sidebarTextInput.value = textColor;
    if (sidenavWrap) sidenavWrap.style.setProperty("color", textColor, "important");
    if (sidenavNav)  sidenavNav.style.setProperty("color", textColor, "important");
    root.style.setProperty("--admin-sidebar-text", textColor);
  }

  if (navbar || sidebarBg || textColor) {
    saveColors(
      navbar ?? (navbarInput ? navbarInput.value : ""),
      sidebarBg,
      textColor
    );
  }
}

/* ---------- Manual Color Pickers ---------- */
if (navbarInput) {
  navbarInput.addEventListener("input", () => {
    applyPreview({
      navbar: navbarInput.value,
      sidebar: sidebarBgInput ? sidebarBgInput.value : undefined,
      text: sidebarTextInput ? sidebarTextInput.value : undefined
    });
  });
}
if (sidebarBgInput) {
  sidebarBgInput.addEventListener("input", () => {
    applyPreview({
      navbar: navbarInput ? navbarInput.value : undefined,
      sidebar: sidebarBgInput.value,
      text: sidebarTextInput ? sidebarTextInput.value : undefined
    });
  });
}
if (sidebarTextInput) {
  sidebarTextInput.addEventListener("input", () => {
    applyPreview({
      navbar: navbarInput ? navbarInput.value : undefined,
      sidebar: sidebarBgInput ? sidebarBgInput.value : undefined,
      text: sidebarTextInput.value
    });
  });
}

/* ---------- Restore on page load ---------- */
document.addEventListener("DOMContentLoaded", () => {
  const saved = loadColors();
  if (navbarInput)      navbarInput.value      = saved.navbar || navbarInput.value;
  if (sidebarBgInput)   sidebarBgInput.value   = saved.sidebar || sidebarBgInput.value;
  if (sidebarTextInput) sidebarTextInput.value = saved.text || sidebarTextInput.value;

  applyPreview({
    navbar: navbarInput ? navbarInput.value : saved.navbar,
    sidebar: sidebarBgInput ? sidebarBgInput.value : saved.sidebar,
    text: sidebarTextInput ? sidebarTextInput.value : saved.text
  });
});

/* ---------- Render palettes ---------- */
(function renderPalettes() {
  if (!paletteContainer || !Array.isArray(palettes)) return;
  paletteContainer.innerHTML = "";
  palettes.forEach(p => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "palette-btn";
    btn.title = `${p.n} — Navbar ${p.navbar}, Sidebar ${p.sidebar}, Text ${p.text}`;

    const swatches = document.createElement("span");
    swatches.className = "swatches";

    [p.navbar, p.sidebar, p.text].forEach(color => {
      const dot = document.createElement("span");
      dot.style.background = color;
      swatches.appendChild(dot);
    });

    const label = document.createElement("span");
    label.textContent = p.n;

    btn.append(swatches, label);

    btn.addEventListener("click", () => {
      applyPreview({ navbar: p.navbar, sidebar: p.sidebar, text: p.text });
      if (navbarInput)      navbarInput.value      = p.navbar;
      if (sidebarBgInput)   sidebarBgInput.value   = p.sidebar;
      if (sidebarTextInput) sidebarTextInput.value = p.text;

      // Mark this palette as active
      paletteContainer.querySelectorAll('.palette-btn.active').forEach(el => el.classList.remove('active'));
      btn.classList.add('active');
    });

    paletteContainer.appendChild(btn);
  });
})();
</script>
<script>
function showToast(message, type = 'success', delay = 3000) {
  const toastEl = document.getElementById('globalToast');
  const bodyEl  = document.getElementById('globalToastBody');
  if (!toastEl || !bodyEl) return;

  const classes = {
    success: 'text-bg-success',
    danger:  'text-bg-danger',
    info:    'text-bg-info',
    warning: 'text-bg-warning'
  };
  const bg = classes[type] || classes.success;

  toastEl.className = 'toast align-items-center border-0 ' + bg;
  bodyEl.textContent = message;

  const t = new bootstrap.Toast(toastEl, { delay });
  t.show();
}
</script>
<?php
if (!empty($_SESSION['flash_toast'])) {
    $msg  = $_SESSION['flash_toast']['message'] ?? 'Done';
    $type = $_SESSION['flash_toast']['type'] ?? 'success';
    unset($_SESSION['flash_toast']);
    echo '<script>document.addEventListener("DOMContentLoaded",function(){ showToast('
         . json_encode($msg) . ', ' . json_encode($type) . '); });</script>';
}
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const tabByHash = {
    '#brandingPane': 'branding-tab',
    '#themePane': 'theme-tab',
    '#qrPane': 'qr-tab',
    '#qr-frame-settings': 'qr-tab',
    '#qr-public-urls': 'qr-tab',
    '#reportsPane': 'reports-tab',
  };

  const settingsTabs = document.getElementById('settingsTabs');
  if (settingsTabs) {
    settingsTabs.querySelectorAll('[data-bs-toggle="tab"]').forEach((btn) => {
      btn.addEventListener('shown.bs.tab', (ev) => {
        const target = ev.target.getAttribute('data-bs-target');
        if (target) {
          history.replaceState(null, '', target);
        }
      });
    });
  }

  const hash = window.location.hash;
  const tabId = tabByHash[hash];
  if (!tabId) return;

  const tabBtn = document.getElementById(tabId);
  if (tabBtn && window.bootstrap && bootstrap.Tab) {
    bootstrap.Tab.getOrCreateInstance(tabBtn).show();
  }

  if (hash === '#qr-frame-settings' || hash === '#qr-public-urls') {
    const target = document.querySelector(hash);
    if (!target) return;
    const scrollToSection = function () {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    tabBtn?.addEventListener('shown.bs.tab', scrollToSection, { once: true });
    window.setTimeout(scrollToSection, 350);
  }
});
</script>


  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
  </html>
