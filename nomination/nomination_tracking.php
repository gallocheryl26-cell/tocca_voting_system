<?php
declare(strict_types=1);
if (function_exists('opcache_invalidate')) { opcache_invalidate(__FILE__, true); }
date_default_timezone_set('Asia/Manila');
$DB_CANDIDATES = [
  __DIR__ . '/db_connection.php',
  __DIR__ . '/../tocca_admin/db_connection.php',
  dirname(__DIR__) . '/tocca_admin/db_connection.php',
];
foreach ($DB_CANDIDATES as $p) { if (is_file($p)) { require_once $p; break; } }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
const DEBUG = false;
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$conn = $conn ?? null;
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($event_id <= 0 && $conn instanceof mysqli) {
  if ($st = $conn->prepare(
    "SELECT event_id FROM tbl_events WHERE is_active = 1 AND is_archived = 0 ORDER BY created_at DESC LIMIT 1"
  )) {
    $st->execute();
    $st->bind_result($foundId);
    if ($st->fetch()) { $event_id = (int)$foundId; }
    $st->close();
  }
}
$nomStart = null;
$nomEnd   = null;
if ($conn instanceof mysqli && $event_id > 0) {
  if ($st = $conn->prepare(
    "SELECT nomination_start, nomination_end FROM tbl_events WHERE event_id = ? LIMIT 1"
  )) {
    $st->bind_param('i', $event_id);
    $st->execute();
    $st->bind_result($nomStart, $nomEnd);
    $st->fetch();
    $st->close();
  }
}
$logoIncludePaths = [
  __DIR__ . '/get_logo.php',
  __DIR__ . '/../tocca_admin/get_logo.php',
  dirname(__DIR__) . '/tocca_admin/get_logo.php',
];
$logoLoaded = false;
foreach ($logoIncludePaths as $path) {
  if (is_file($path)) { require_once $path; $logoLoaded = true; break; }
}
if (!$logoLoaded) {
  $faviconPath          = 'favicon.png';
  $nominationBannerPath = 'img/default-banner.png';
  $nominationBgColor    = '#f8f9fa';
}
$tz = new DateTimeZone('Asia/Manila');
$nomStartFmt = $nomStart ? (new DateTime($nomStart, $tz))->format('F j, Y g:i A') : null;
$nomEndFmt   = $nomEnd   ? (new DateTime($nomEnd,   $tz))->format('F j, Y g:i A') : null;
$nomPeriodText = ($nomStartFmt ?: 'TBA') . ' – ' . ($nomEndFmt ?: 'TBA');
$headerImage = $nominationBannerPath ?? 'img/default-banner.png';
$bodyBg      = $nominationBgColor ?? '#f8f9fa';
function pick_answer_value(array $row): string {
  foreach (['answer','value','text_value','file_path','file','file_url','string_value','content','data','val','numeric_value'] as $k) {
    if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') return (string)$row[$k];
  }
  return '';
}
function map_featured_from_rows(array $rows): array {
  $by = [];
  foreach ($rows as $r) { $by[strtolower($r['name'] ?? '')] = $r; }
  $get = function(string $slug) use ($by): ?string {
    $k = strtolower($slug);
    if (!isset($by[$k])) return null;
    $v = (string)($by[$k]['value'] ?? '');
    return $v !== '' ? $v : null;
  };
  $findByPattern = function(string $pattern) use ($rows): ?string {
    foreach ($rows as $r) {
      $hay = strtolower((string)($r['name'] ?? '') . ' ' . (string)($r['label'] ?? ''));
      if (preg_match($pattern, $hay)) {
        $v = (string)($r['value'] ?? '');
        if ($v !== '') return $v;
      }
    }
    return null;
  };
  return [
    'business_name' => $get('official_business_name') ?: $get('business_name'),
    'owner_name'    => $get('owner_president_general_manager'),
    'mayor_permit'  => $get('mayor_s_permit_number') ?: $get('mayors_permit_number'),
    'address'       => $get('business_address') ?: $get('business_company_address'),
    'mobile_number' => $get('mobile_number'),
    'email'         => $get('email_address'),
    'website'       => $get('website'),
    '_logo_answer'  => $get('company_logo'),
    'designation'   => $findByPattern('/designation/'),
  ];
}
$reference  = trim($_GET['ref'] ?? '');
$ajax       = isset($_GET['ajax']);
$error      = '';
$data       = null;
try {
  if ($reference !== '') {
    $stmt = $conn->prepare("SELECT nomination_id, reference_no, status FROM tbl_nominations WHERE reference_no = ? LIMIT 1");
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $nomRes = $stmt->get_result();
    if (!$nomRes || $nomRes->num_rows === 0) {
      $error = 'Reference number not found.';
    } else {
      $nom = $nomRes->fetch_assoc();
      $stmt->close();
      $nominationId = (int)$nom['nomination_id'];
      $sql = "
        SELECT
          f.id         AS field_id,
          f.label      AS label,
          f.name       AS name,
          f.type       AS type,
          f.sort_order AS sort_order,
          a.*
        FROM tbl_nomination_fields f
        LEFT JOIN tbl_nomination_answers a
          ON a.field_id = f.id AND a.nomination_id = ?
        WHERE f.is_active = 1
        ORDER BY f.sort_order ASC, f.label ASC
      ";
      $as = $conn->prepare($sql);
      $as->bind_param('i', $nominationId);
      $as->execute();
      $ansRes = $as->get_result();
      $rows = [];
      $logoAnswerFallback = null;
      while ($r = $ansRes->fetch_assoc()) {
        $val = pick_answer_value($r);
        $item = [
          'field_id'   => (int)$r['field_id'],
          'label'      => (string)$r['label'],
          'name'       => (string)$r['name'],
          'type'       => (string)$r['type'],
          'sort_order' => (int)$r['sort_order'],
          'value'      => $val,
        ];
        $rows[] = $item;
        if (strtolower($r['name']) === 'company_logo' && $val !== '') {
          $logoAnswerFallback = $val;
        }
      }
      $as->close();
      $featured  = map_featured_from_rows($rows);
      $logo_path = $featured['_logo_answer'] ?? $logoAnswerFallback;
      $catSql = "
        SELECT q.question_name, c.category_name
        FROM tbl_nomination_questions nq
        JOIN tbl_questions q ON q.question_id = nq.question_id
        LEFT JOIN tbl_categories c ON c.category_id = q.category_id
        WHERE nq.nomination_id = ?
      ";
      $cs = $conn->prepare($catSql);
      $cs->bind_param('i', $nominationId);
      $cs->execute();
      $catRes     = $cs->get_result();
      $categories = $catRes ? $catRes->fetch_all(MYSQLI_ASSOC) : [];
      $cs->close();
      $data = [
        'nomination_id' => $nominationId,
        'reference_no'  => $nom['reference_no'],
        'status'        => $nom['status'],
        'logo_path'     => $logo_path ?: null,
        'business_name' => $featured['business_name'] ?? null,
        'designation'   => $featured['designation']   ?? null,
        'owner_name'    => $featured['owner_name']    ?? null,
        'mayor_permit'  => $featured['mayor_permit']  ?? null,
        'address'       => $featured['address']       ?? null,
        'email'         => $featured['email']         ?? null,
        'mobile_number' => $featured['mobile_number'] ?? null,
        'website'       => $featured['website']       ?? null,
        'fields'        => $rows,
        'categories'    => $categories,
      ];
    }
  }
} catch (Throwable $e) {
  $error = DEBUG ? ('DB error: '.$e->getMessage()) : 'Database error.';
}
if ($ajax) {
  header('Content-Type: application/json; charset=UTF-8');
  if ($error) { echo json_encode(['status' => 'error', 'message' => $error]); exit; }
  if ($data)  { echo json_encode(['status' => 'success', 'data' => $data]); exit; }
  echo json_encode(['status' => 'error', 'message' => 'Reference number required.']); exit;
}
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#1e40af" />
  <title>Track Your Nomination | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo h($faviconPath ?? 'favicon.png'); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="nomination_form.css">
  <link rel="stylesheet" href="nomination_tracking.css">
  <style>:root { --voter-bg: <?php echo h($bodyBg); ?>; }</style>
</head>
<body class="nomination-tracker-page">
<div class="hero-banner">
  <div class="track-layout banner-wrap text-center">
    <img src="<?php echo h($headerImage); ?>" alt="Tatak Ormoc nomination banner" class="track-banner-img" width="1100" height="320" decoding="async" fetchpriority="high" />
  </div>
</div>
<div class="track-layout mb-3">
  <div class="period-bar track-period-bar d-flex flex-wrap align-items-center gap-2">
    <span class="period-badge"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Nomination Period</span>
    <span class="period-text"><?php echo h($nomPeriodText); ?></span>
  </div>
</div>
<div class="track-layout track-page-main">
  <header class="track-page-header">
    <h1 class="hero-title mb-2">Track Your Nomination</h1>
    <p class="hero-sub mb-0">Enter your reference number to view status and nomination details.</p>
    <div class="track-quick-points" aria-hidden="true">
      <span class="quick-point"><i class="bi bi-lightning-charge-fill"></i> Real-time status</span>
      <span class="quick-point"><i class="bi bi-shield-check"></i> Secure reference lookup</span>
      <span class="quick-point"><i class="bi bi-trophy"></i> Awards and categories view</span>
    </div>
  </header>

  <div class="section-card track-search-card mb-4">
    <div class="card-body">
      <form id="trackForm">
        <label for="referenceInput" class="form-label mb-2">Reference number</label>
        <div class="track-input-row">
          <input
            type="text"
            class="form-control ref-input"
            id="referenceInput"
            name="ref"
            value="<?php echo h($reference); ?>"
            placeholder="e.g. NOM-2026-XXXXXX"
            autocomplete="off"
            spellcheck="false"
            autocapitalize="characters"
            inputmode="text"
            aria-describedby="trackHint"
            required
          >
          <button class="btn btn-primary btn-track" type="submit" id="trackBtn">
            <i class="bi bi-search me-1" aria-hidden="true"></i>Track
          </button>
        </div>
        <p id="trackHint" class="track-hint mb-0">You received this code after submitting your nomination.</p>
      </form>
    </div>
  </div>

  <div id="errorMsg" class="alert alert-danger shadow-sm" style="display:none;" role="alert" aria-live="polite"></div>

  <div id="resultCard" class="section-card" style="display:none;">
    <div class="card-body">
      <div class="result-header">
        <div id="referenceBadge" class="ref-chip" style="display:none;">
          <i class="bi bi-hash" aria-hidden="true"></i>
          <span id="referenceNoText"></span>
        </div>
        <h2 id="businessName"></h2>
        <p id="resultSubtext" class="result-subtext mb-0"></p>
      </div>

      <ol id="trackerSteps" class="tracker-steps" aria-label="Nomination progress">
        <li>
          <div class="step-circle"><i class="bi bi-check-lg" aria-hidden="true"></i></div>
          <span class="step-label">
            <span class="step-label-full">Submitted</span>
            <span class="step-label-short">Sent</span>
          </span>
        </li>
        <li>
          <div class="step-circle"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></div>
          <span class="step-label">
            <span class="step-label-full">In Review</span>
            <span class="step-label-short">Review</span>
          </span>
        </li>
        <li>
          <div class="step-circle"><i class="bi bi-flag-fill" aria-hidden="true"></i></div>
          <span class="step-label">
            <span class="step-label-full">Completed</span>
            <span class="step-label-short">Done</span>
          </span>
        </li>
      </ol>

      <div class="status-row">
        <span class="status-label">Current status</span>
        <span id="currentStatus" class="status-badge status-neutral">—</span>
      </div>
      <div id="trackerInsights" class="tracker-insights" aria-live="polite"></div>
      <p id="statusHelpText" class="status-help-text"></p>

      <div class="details-grid">
        <div id="logoBox" class="logo-box" role="img" aria-label="Business logo">
          <i class="bi bi-building" aria-hidden="true"></i>
          <span>No logo uploaded</span>
        </div>
        <div>
          <section class="section-block">
            <h3 class="section-title">Nomination Details</h3>
            <div id="summaryFields" class="kv-grid"></div>
          </section>
          <section id="extraSection" class="section-block" style="display:none;" aria-live="polite">
            <h3 class="section-title">Additional Information <span id="extraCount" class="section-count"></span></h3>
            <div id="extraFields" class="kv-grid"></div>
          </section>
          <section class="section-block">
            <h3 class="section-title">Categories &amp; Awards <span id="categoriesMetaCount" class="section-count"></span></h3>
            <ul id="categoryList"></ul>
          </section>
        </div>
      </div>
    </div>
  </div>

  <?php
    $nomFormUrl = 'nomination_form.php' . ($event_id > 0 ? '?event_id=' . rawurlencode((string) $event_id) : '');
  ?>
  <div class="track-footer-links portal-bottom-actions">
    <a class="btn btn-outline-primary" href="<?php echo h($nomFormUrl); ?>">
      <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Submit a nomination
    </a>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="nomination_tracking.js"></script>
</body>
</html>
