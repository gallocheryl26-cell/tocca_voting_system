<?php
declare(strict_types=1);

/**
 * Admin-only live preview of the nomination form (read-only).
 */
require_once __DIR__ . '/includes/admin_init.php';
require_once __DIR__ . '/includes/nomination_form_schema.php';
require_once __DIR__ . '/../nomination/rich_text_helpers.php';
require_once __DIR__ . '/../nomination/nomination_field_helpers.php';

nomination_form_schema_ensure($conn);

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : (admin_active_event_id($conn) ?? 0);
$showInactive = !empty($_GET['show_inactive']);

$fields = nf_load_fields($conn, $eventId, [
    'active_only'      => !$showInactive,
    'include_inactive' => $showInactive,
]);
$establishmentTypes = nf_establishment_types_for_event($conn, $eventId);

$introActive = false;
$introHtml   = '';
$instActive  = false;
$instTitle   = 'Before you start';
$instBullets = [];

if ($eventId > 0) {
    $stmt = $conn->prepare("
        SELECT body_html, is_active FROM tbl_nomination_texts
        WHERE section='intro' AND (event_id = ? OR event_id IS NULL)
        ORDER BY (event_id IS NULL) ASC LIMIT 1
    ");
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $stmt->bind_result($body, $active);
    if ($stmt->fetch()) {
        $introActive = (bool) $active;
        $introHtml   = $introActive ? md_to_html_basic((string) $body) : '';
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT title, bullets_json, is_active FROM tbl_nomination_texts
        WHERE section='instructions' AND (event_id = ? OR event_id IS NULL)
        ORDER BY (event_id IS NULL) ASC LIMIT 1
    ");
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $stmt->bind_result($title, $bulletsJson, $active);
    if ($stmt->fetch()) {
        $instActive = (bool) $active;
        $instTitle  = (string) ($title ?: 'Before you start');
        if ($bulletsJson) {
            $arr = json_decode($bulletsJson, true);
            if (is_array($arr)) {
                $instBullets = array_values(array_filter(array_map('trim', $arr)));
            }
        }
    }
    $stmt->close();
}

/** @var string $nominationBannerPath from admin_init → get_logo.php */
/** @var string $nominationBgColor */

/**
 * Resolve banner src for this preview page (under /tocca_admin/).
 */
function nom_preview_banner_src(string $resolvedPath): string
{
    $resolvedPath = trim($resolvedPath);
    if ($resolvedPath === '') {
        return '';
    }
    if (preg_match('~^(https?:)?//|^/~', $resolvedPath)) {
        return $resolvedPath;
    }

    $rel = ltrim(str_replace('\\', '/', $resolvedPath), '/');
    $rel = preg_replace('#^\.\./tocca_admin/#', '', $rel) ?? $rel;

    $candidates = [$rel];
    if (strpos($rel, 'img/') !== 0) {
        $candidates[] = 'img/' . basename($rel);
    }

    foreach ($candidates as $path) {
        if (is_file(__DIR__ . '/' . $path)) {
            return $path;
        }
    }

    $imgDir = __DIR__ . '/img';
    if (is_dir($imgDir)) {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            foreach (glob($imgDir . '/*banner*.' . $ext) ?: [] as $abs) {
                return 'img/' . basename($abs);
            }
        }
    }

    return $rel;
}

$headerImage = nom_preview_banner_src($nominationBannerPath ?? '');
$bodyBg      = $nominationBgColor ?? '#f8f9fa';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Nomination Form Preview</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="../nomination/nomination_form.css"/>
  <style>
    body { --voter-bg: <?= h($bodyBg) ?>; }
    .preview-banner {
      background: linear-gradient(135deg, #1e3a5f, #2563eb);
      color: #fff;
      padding: .5rem 1rem;
      font-size: .875rem;
      text-align: center;
    }
    .nf-preview-inactive { opacity: .75; border-left: 3px solid #6c757d; padding-left: .5rem; }
    .hero-banner img { max-height: 120px; object-fit: contain; }
  </style>
</head>
<body>
  <div class="preview-banner">
    <i class="fa-solid fa-eye me-1"></i> Preview only — nominees cannot submit from this page
    <?php if ($showInactive): ?> · including hidden questions<?php endif; ?>
  </div>

  <div class="hero-banner py-3">
    <div class="container text-center">
      <?php if ($headerImage !== ''): ?>
      <img src="<?= h($headerImage) ?>" alt="Tatak Ormoc nomination banner" class="img-fluid"/>
      <?php endif; ?>
    </div>
  </div>

  <main class="container my-4">
    <?php if ($introHtml !== ''): ?>
      <div class="card section-card mb-4">
        <div class="card-body"><?= $introHtml ?></div>
      </div>
    <?php endif; ?>

    <?php if ($instActive && $instBullets !== []): ?>
      <div class="card section-card mb-4">
        <div class="card-body">
          <h2 class="h6 mb-3"><?= h($instTitle) ?></h2>
          <ol class="mb-0">
            <?php foreach ($instBullets as $li): ?>
              <li><?= md_inline_basic($li) ?></li>
            <?php endforeach; ?>
          </ol>
        </div>
      </div>
    <?php endif; ?>

    <div class="card section-card mb-4">
      <div class="card-body">
        <div class="section-head mb-3">
          <span class="section-step-pill">Step 1 of 3</span>
          <h2 class="section-title">Business Details</h2>
          <p class="section-sub text-muted">Built-in fields first, then your custom questions.</p>
        </div>

        <div class="row g-3 mb-2">
          <?php nf_render_establishment_type_field($establishmentTypes, [
            'preview'    => true,
            'h'          => 'h',
            'manage_url' => 'establishment_types.php',
          ]); ?>
        </div>

        <?php if ($fields === []): ?>
          <div class="alert alert-warning mb-0">No fields to preview for this event.</div>
        <?php else: ?>
          <?php nf_render_fields_grid($fields, ['preview' => true, 'active_only' => !$showInactive]); ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card section-card mb-4 opacity-75">
      <div class="card-body">
        <p class="text-muted small mb-0"><i class="fa-solid fa-images me-1"></i> Photos &amp; Videos and Awards steps appear on the live form but are not shown in this preview.</p>
      </div>
    </div>
  </main>
</body>
</html>
