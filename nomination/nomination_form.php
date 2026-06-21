<?php
header('Content-Type: text/html; charset=UTF-8');
date_default_timezone_set('Asia/Manila');
session_start();
require_once __DIR__ . '/rich_text_helpers.php';
require_once __DIR__ . '/nomination_field_helpers.php';

function h($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function parse_options_to_array($raw): array {
  if ($raw === null) return [];
  if (is_array($raw)) {
    return array_values(array_filter(array_map('trim', $raw), fn($v)=>$v!==''));
  }
  $s = trim((string)$raw);
  if ($s === '') return [];

  $try = json_decode($s, true);
  if (is_array($try)) {
    return array_values(array_filter(array_map('trim', $try), fn($v)=>$v!==''));
  }

  $parts = preg_split('/[\r\n,\|]+/u', $s);
  return array_values(array_filter(array_map('trim', $parts), fn($v)=>$v!==''));
}

$conn = null;
$DB_CONN_PATHS = [
  __DIR__ . '/../tocca_admin/db_connection.php',
  dirname(__DIR__) . '/tocca_admin/db_connection.php',
  __DIR__ . '/tocca_admin/db_connection.php',
];
foreach ($DB_CONN_PATHS as $p) {
  if (is_file($p)) { require_once $p; break; }
}
if (!isset($conn) || !($conn instanceof mysqli)) {
  $conn = null;
}

$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($event_id <= 0 && $conn instanceof mysqli) {
  if ($st = $conn->prepare("
      SELECT event_id
      FROM tbl_events
      WHERE is_active = 1 AND is_archived = 0
      ORDER BY created_at DESC
      LIMIT 1
  ")) {
    $st->execute();
    $st->bind_result($foundId);
    if ($st->fetch()) $event_id = (int)$foundId;
    $st->close();
  }
}

$logoIncludeTried = [
  __DIR__ . '/get_logo.php',
  __DIR__ . '/../tocca_admin/get_logo.php',
  dirname(__DIR__) . '/tocca_admin/get_logo.php',
];
$logoLoaded = false;
foreach ($logoIncludeTried as $path) {
  if (is_file($path)) { require_once $path; $logoLoaded = true; break; }
}
if (!$logoLoaded) {
  $faviconPath          = 'favicon.png';
  $nominationBannerPath = 'img/default-banner.png';
  $nominationBgColor    = '#f8f9fa';
}

function parse_sql_dt(?string $s): ?DateTimeImmutable {
  if (!$s) return null;
  $s = trim($s);
  $fmts = ['Y-m-d H:i:s','Y-m-d H:i','Y-m-d\TH:i','Y-m-d\TH:i:s'];
  $tz   = new DateTimeZone('Asia/Manila');
  foreach ($fmts as $fmt) {
    $d = DateTimeImmutable::createFromFormat($fmt, $s, $tz);
    if ($d instanceof DateTimeImmutable) return $d;
  }
  try { return new DateTimeImmutable($s, $tz); } catch (\Throwable $e) {}
  return null;
}

$nomStatus = 'open';
$nomStart = null;
$nomEnd   = null;

if ($conn instanceof mysqli) {
  if ($st = $conn->prepare("
      SELECT nomination_start, nomination_end
      FROM tbl_events
      WHERE event_id = ?
      LIMIT 1
  ")) {
    $st->bind_param('i', $event_id);
    $st->execute();
    $st->bind_result($nomStart, $nomEnd);
    if ($st->fetch()) {
      $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
      $ns  = parse_sql_dt($nomStart);
      $ne  = parse_sql_dt($nomEnd);

      if ($ns && $ne) {
        if     ($now < $ns) $nomStatus = 'upcoming';
        elseif ($now >= $ne) $nomStatus = 'closed';
        else                 $nomStatus = 'open';
      } elseif ($ns && !$ne) {
        $nomStatus = ($now < $ns) ? 'upcoming' : 'open';
      } elseif (!$ns && $ne) {
        $nomStatus = ($now >= $ne) ? 'closed' : 'open';
      } else {
        $nomStatus = 'open';
      }
    }
    $st->close();
  }
}

if ($nomStatus !== 'open') {
  header('Location: nomination_message.php?reason=' . urlencode($nomStatus));
  exit;
}

if (!($conn instanceof mysqli) || $event_id <= 0) {
  header('Location: nomination_message.php?reason=error');
  exit;
}

$nomStartFmt = $nomStart ? (new DateTime($nomStart, new DateTimeZone('Asia/Manila')))->format('F j, Y g:i A') : null;
$nomEndFmt   = $nomEnd   ? (new DateTime($nomEnd,   new DateTimeZone('Asia/Manila')))->format('F j, Y g:i A') : null;
$nomPeriodText = ($nomStartFmt ?: 'TBA') . ' – ' . ($nomEndFmt ?: 'TBA');
$trackingUrl   = 'nomination_tracking.php' . ($event_id ? '?event_id=' . rawurlencode((string)$event_id) : '');


$admin_fields = ($conn instanceof mysqli)
  ? nf_load_fields($conn, (int) $event_id, ['active_only' => true])
  : [];

$introActive = false;
$introRaw = '';
$instActive = false;
$instTitle = 'Before you start';
$instBullets = [];

if ($conn instanceof mysqli) {
  if ($st = $conn->prepare("
      SELECT body_html, is_active
      FROM tbl_nomination_texts
      WHERE section='intro' AND (event_id = ? OR event_id IS NULL)
      ORDER BY (event_id IS NULL) ASC
      LIMIT 1
  ")) {
    $st->bind_param('i', $event_id);
    $st->execute();
    $st->bind_result($body_html, $is_active);
    if ($st->fetch()) {
      $introActive = (bool)$is_active;
      $introRaw    = (string)$body_html;
    }
    $st->close();
  }
  if ($st = $conn->prepare("
      SELECT title, bullets_json, is_active
      FROM tbl_nomination_texts
      WHERE section='instructions' AND (event_id = ? OR event_id IS NULL)
      ORDER BY (event_id IS NULL) ASC
      LIMIT 1
  ")) {
    $st->bind_param('i', $event_id);
    $st->execute();
    $st->bind_result($title, $bullets_json, $is_active);
    if ($st->fetch()) {
      $instActive = (bool)$is_active;
      if (!empty($title)) $instTitle = (string)$title;
      if (!empty($bullets_json)) {
        $arr = json_decode($bullets_json, true);
        if (is_array($arr)) $instBullets = array_values(array_filter(array_map('trim', $arr)));
      }
    }
    $st->close();
  }
}

$headerImage = $nominationBannerPath ?? 'img/default-banner.png';
$bodyBg      = $nominationBgColor ?? '#f8f9fa';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
  <meta name="theme-color" content="#1e40af"/>
  <title>Nomination Form</title>
  <link rel="icon" type="image/png" href="<?php echo h($faviconPath ?? ''); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
  <link rel="stylesheet" href="nomination_form.css">
  <style>
    body { --voter-bg: <?php echo h($bodyBg); ?>; }
  </style>
</head>
<body class="nomination-form-page">
  <header class="nom-page-header">
    <div class="hero-banner">
      <div class="nom-banner-wrap">
        <img
          src="<?php echo h($headerImage); ?>"
          alt="Tatak Ormoc nomination banner"
          class="nom-banner-img"
          width="1100"
          height="320"
          decoding="async"
          fetchpriority="high"
        />
      </div>
    </div>

    <div class="container px-2 px-sm-3 nom-period-wrap">
      <div class="period-bar period-bar-mobile d-flex align-items-center flex-wrap gap-2">
        <div class="period-info d-flex align-items-center flex-wrap gap-2 min-w-0">
          <span class="badge period-badge shrink-0"><i class="fa-regular fa-calendar me-1" aria-hidden="true"></i> Nomination Period</span>
          <span class="period-text"><?php echo h($nomPeriodText); ?></span>
        </div>
        <a class="btn btn-outline-primary btn-sm tracking-btn ms-md-auto" href="<?php echo h($trackingUrl); ?>">
          <i class="fa-solid fa-location-dot me-1" aria-hidden="true"></i><span class="tracking-btn-label">Track</span><span class="tracking-btn-label-long"> Nomination</span>
        </a>
      </div>
    </div>
  </header>

  <main class="container my-2 my-md-4 px-2 px-sm-3 nomination-form-main">
    <div class="stepper-shell sticky-top mb-2 mb-md-4">
      <div class="card section-card mb-0">
        <div class="card-body py-3">
          <div id="stepper" class="stepper" role="list" aria-label="Application progress">
            <div class="step active" data-step="0" role="listitem" aria-current="step">
              <span class="bubble"><span class="bubble-num">1</span><i class="fa-solid fa-check d-none"></i></span>
              <span class="step-label">
                <span class="step-label-full">Business Details</span>
                <span class="step-label-short" aria-hidden="true">Details</span>
              </span>
            </div>
            <div class="divider" aria-hidden="true"></div>
            <div class="step" data-step="1" role="listitem">
              <span class="bubble"><span class="bubble-num">2</span><i class="fa-solid fa-check d-none"></i></span>
              <span class="step-label">
                <span class="step-label-full">Awards</span>
                <span class="step-label-short" aria-hidden="true">Awards</span>
              </span>
            </div>
            <div class="divider" aria-hidden="true"></div>
            <div class="step" data-step="2" role="listitem">
              <span class="bubble"><span class="bubble-num">3</span><i class="fa-solid fa-check d-none"></i></span>
              <span class="step-label">
                <span class="step-label-full">Review &amp; Submit</span>
                <span class="step-label-short" aria-hidden="true">Review</span>
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php
      $hasPreflight = ($introActive && $introRaw !== '')
        || ($instActive && !empty($instBullets));
      $preflightTitle = 'Before you start';
      if ($instActive && !empty($instBullets)) {
          $t = trim((string) $instTitle);
          if ($t !== '' && strcasecmp($t, 'Instructions') !== 0) {
              $preflightTitle = $t;
          }
      } elseif ($introActive && $introRaw !== '') {
          $preflightTitle = 'Welcome';
      }
    ?>
    <?php if ($hasPreflight): ?>
    <details class="nom-preflight mb-2 mb-md-4">
      <summary class="nom-preflight-summary">
        <i class="fa-solid fa-circle-info me-2 text-primary" aria-hidden="true"></i>
        <?= h($preflightTitle); ?>
      </summary>
      <div class="nom-preflight-body">
        <?php if ($introActive && $introRaw !== ''): ?>
          <div class="overview-card nom-preflight-intro">
            <?= easy_rich_to_html($introRaw); ?>
          </div>
        <?php endif; ?>
        <?php if ($instActive && !empty($instBullets)): ?>
          <div class="nom-preflight-instructions">
            <?php if (!$introActive || $introRaw === ''): ?>
              <h2 class="h6 mb-2"><?= h($instTitle ?: 'Instructions'); ?></h2>
            <?php endif; ?>
            <ol id="instructionList" class="mb-0">
              <?php foreach ($instBullets as $li): ?>
                <li><?= md_inline_to_html($li); ?></li>
              <?php endforeach; ?>
            </ol>
          </div>
        <?php endif; ?>
      </div>
    </details>
    <?php endif; ?>

    <form
      id="nominationForm"
      action="submit_nomination.php"
      method="post"
      enctype="multipart/form-data"
      novalidate
      class="needs-validation"
      data-event-id="<?php echo h((string)$event_id); ?>"
    >
      <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
      <input type="hidden" id="selectedAwardsInput" name="selected_awards_json">
      <input type="hidden" name="event_id" id="event_id" value="<?php echo h($event_id ?: ''); ?>">

      <section class="form-step active" data-step="0">
        <div class="card section-card mb-4">
          <div class="card-body">
            <div class="section-head mb-3">
              <span class="section-step-pill">Step 1 of 3</span>
              <h2 class="section-title">Business Details</h2>
              <p class="section-sub">Tell us about your establishment. Fields marked with <span class="text-danger fw-bold">*</span> are required.</p>
            </div>

            <div class="row g-3 mb-2">
  <div class="col-12 nom-field-select-wrap nom-field-establishment">
    <label class="form-label" for="establishmentTypeSelect">
      <i class="fa-solid fa-building me-1 text-muted" aria-hidden="true"></i>
      Establishment Type
    </label>
    <select
      id="establishmentTypeSelect"
      name="establishment_type_id"
      class="form-select w-100"
      required
      aria-describedby="establishmentTypeHelp"
    >
      <option value="">Select type…</option>
    </select>

    <div id="establishmentTypeHelp" class="form-text">
      Selecting a type will <strong>limit the awards you can choose</strong> on the next step.
    </div>

    <div class="invalid-feedback js-field-error" role="alert">Please choose an establishment type.</div>
  </div>
</div>

            <?php if (empty($admin_fields)): ?>
              <div class="alert alert-info">
                No fields are configured for the nomination form. Please ask an admin to add fields via File Maintenance → Nomination Form.
              </div>
            <?php else: ?>
              <?php nf_render_fields_grid($admin_fields, ['h' => 'h']); ?>
            <?php endif; ?>

          </div>
        </div>

        <!-- Photos & Videos: collected here, propagated to tbl_choice_media on approval -->
        <div class="card section-card mb-4">
          <div class="card-body">
            <div class="section-head mb-3">
              <span class="section-step-pill optional">Optional</span>
              <h2 class="section-title">Photos &amp; Videos</h2>
              <p class="section-sub">
                Help voters recognize your business — upload up to <strong>8</strong> photos or short videos
                (e.g. storefront, products, team).
              </p>
              <div class="d-flex flex-wrap gap-2 mt-2 small">
                <span class="hint-chip"><i class="fa-solid fa-image me-1"></i> Images: PNG, JPG, WEBP, GIF · 10&nbsp;MB</span>
                <span class="hint-chip"><i class="fa-solid fa-video me-1"></i> Videos: MP4, WebM, OGG, MOV · 100&nbsp;MB</span>
              </div>
            </div>

            <label for="nominationMediaInput"
                   id="nominationMediaDropzone"
                   class="dropzone w-100 d-flex flex-column align-items-center justify-content-center text-center p-4 mb-3"
                   tabindex="0">
              <i class="fa-solid fa-cloud-arrow-up fa-2x mb-2 text-primary"></i>
              <span class="fw-semibold">Click here or drag &amp; drop your photos / videos</span>
              <span class="text-muted small mt-1">You can add multiple files at once.</span>
              <input type="file" id="nominationMediaInput" name="nomination_media[]"
                     accept="image/*,video/*" multiple class="visually-hidden">
            </label>

            <div id="nominationMediaList" class="row g-3"></div>
            <div id="nominationMediaSummary" class="form-text mt-2 d-none">
              <span id="nominationMediaCount">0</span> file(s) selected
              · <span id="nominationMediaSize">0</span> total
            </div>
            <div id="nominationMediaError" class="text-danger small mt-2 d-none" role="alert"></div>
          </div>
        </div>

        <div class="nom-step-actions d-flex justify-content-end flex-wrap gap-2">
          <button type="button" class="btn btn-primary next-step">
            Next <i class="fa-solid fa-arrow-right ms-1"></i>
          </button>
        </div>
      </section>

      <section class="form-step" data-step="1">
        <div class="card section-card mb-4">
          <div class="card-body">
            <div class="section-head mb-3">
              <span class="section-step-pill">Step 2 of 3</span>
              <h2 class="section-title">Choose Your Awards</h2>
              <p class="section-sub">Pick every category your business should be considered for. Only awards available for your establishment type are listed.</p>
            </div>

            <div class="row g-3 align-items-end mb-3 awards-toolbar">
              <div class="col-12 col-md-7">
                <label class="form-label small text-muted mb-1" for="awardsSearch">Search</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                  <input type="text" id="awardsSearch" class="form-control" placeholder="Type to filter awards…" autocomplete="off">
                </div>
              </div>
              <div class="col-12 col-md-5">
                <div class="d-flex flex-wrap gap-2 justify-content-md-end awards-toolbar-actions">
                  <input class="btn-check" type="checkbox" id="showSelectedOnly" autocomplete="off">
                  <label class="btn btn-sm btn-outline-primary awards-selected-toggle" for="showSelectedOnly">
                    <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Selected only
                  </label>
                  <button type="button" class="btn btn-sm btn-outline-primary flex-fill flex-md-grow-0" id="selectAllAwards">
                    <i class="fa-solid fa-check-double me-1"></i>Select all
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary flex-fill flex-md-grow-0" id="clearAllAwards">
                    <i class="fa-solid fa-eraser me-1"></i>Clear
                  </button>
                </div>
              </div>
            </div>

            <div id="awardsStepError" class="alert alert-danger d-none mb-3" role="alert"></div>
            <div id="awards" class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-2"></div>
            <div id="awardsCountWrapper" class="awards-count-bar mt-3 d-none">
              <i class="fa-solid fa-trophy text-warning me-1"></i>
              <strong><span id="awardsCount">0</span></strong> award(s) selected
            </div>
          </div>
        </div>
        <div class="nom-step-actions d-flex justify-content-between flex-wrap gap-2">
          <button type="button" class="btn btn-outline-secondary prev-step">
            <i class="fa-solid fa-arrow-left me-1"></i> Back
          </button>
          <button type="button" class="btn btn-primary next-step">
            Next <i class="fa-solid fa-arrow-right ms-1"></i>
          </button>
        </div>
      </section>

      <section class="form-step" data-step="2">
        <div class="card section-card mb-4">
          <div class="card-body">
            <div class="section-head mb-3">
              <span class="section-step-pill">Step 3 of 3</span>
              <h2 class="section-title">Review &amp; Submit</h2>
              <p class="section-sub">Double-check everything below. You can still go back to fix any detail before submitting.</p>
            </div>

            <div id="reviewSummary" class="review-box"></div>

            <div id="logoTempReminder" class="alert alert-warning d-none mt-3" role="alert">
              <i class="fa-solid fa-triangle-exclamation me-1"></i>
              Logo preview detected but the file is missing. Please reselect it before submitting if you want to include a logo.
            </div>

            <div class="consent-block mt-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="confirmAccuracy" required
                  aria-label="Accuracy confirmation">
                <label class="form-check-label" for="confirmAccuracy" data-short-label="Accuracy confirmation">
                  I confirm the information above is accurate and I have permission to submit this nomination.
                  <span class="text-danger">*</span>
                </label>
                <div class="invalid-feedback js-field-error" role="alert"></div>
              </div>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="agreePrivacy" name="agreePrivacy" required
                  aria-label="Privacy Policy agreement">
                <label class="form-check-label" for="agreePrivacy" data-short-label="Privacy Policy agreement">
                  I agree to the collection and processing of my personal data in compliance with the
                  <a href="https://www.privacy.gov.ph/data-privacy-act/" target="_blank">Philippines Data Privacy Act</a>
                  and the
                  <a href="#" target="_blank">Privacy Policy</a>.
                  <span class="text-danger">*</span>
                </label>
                <div class="invalid-feedback js-field-error" role="alert"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="nom-step-actions d-flex justify-content-between flex-wrap gap-2">
          <button type="button" class="btn btn-outline-secondary prev-step">
            <i class="fa-solid fa-arrow-left me-1"></i> Back
          </button>
          <button type="submit" class="btn btn-success btn-submit" id="submitBtn">
            <span class="btn-label"><i class="fa-solid fa-paper-plane me-1"></i> Submit Nomination</span>
            <span class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
          </button>
        </div>
      </section>
    </form>
  </main>

  <div id="toastContainer" class="position-fixed start-50 translate-middle-x p-3" style="top: 0.75rem;">
    <div id="toastMsg" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body" id="toastBody">Saved.</div>
      </div>
    </div>
  </div>
<script>
    window.EVENT_ID = <?php echo json_encode($event_id > 0 ? $event_id : null); ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="nomination_form.js"></script>
</body>
</html>
