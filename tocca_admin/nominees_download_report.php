<?php
// nominees_download_report.php
// Registration list export for TOCCA in PDF, Excel, or CSV.

// Shared includes may emit whitespace; keep binary downloads free of it.
ob_start();

require_once __DIR__ . '/require_admin_session.php';
require_once __DIR__ . '/audit_log.php';
// get_logo.php provides getConfig() (used to look up the configured logo path)
// and resolveAssetPath(). It is procedural and safe to include multiple times.
require_once __DIR__ . '/get_logo.php';
require_once __DIR__ . '/includes/report_export_helpers.php';
require_once __DIR__ . '/includes/establishment_type_event_helpers.php';
tocca_admin_require_login(false);

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/includes/admin_active_event.php';
require_once __DIR__ . '/includes/nomination_report_data.php';

// ---------------------------------------------------------------------------
// Composer autoload (PhpSpreadsheet + mPDF live here)
// ---------------------------------------------------------------------------
$autoloadCandidates = [
  __DIR__ . '/vendor/autoload.php',
  dirname(__DIR__) . '/vendor/autoload.php',
  dirname(__DIR__, 2) . '/vendor/autoload.php',
  ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/autoload.php',
];
$autoloadLoaded = null;
foreach ($autoloadCandidates as $p) {
  if (is_file($p)) { require_once $p; $autoloadLoaded = $p; break; }
}

// ---- Debug probe ----------------------------------------------------------
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
  ob_clean();
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Autoload loaded from: " . ($autoloadLoaded ?: 'none') . PHP_EOL;
  echo "class_exists('\\\\Mpdf\\\\Mpdf'): " . (class_exists('\\Mpdf\\Mpdf') ? 'YES' : 'NO') . PHP_EOL;
  echo "class_exists('\\\\PhpOffice\\\\PhpSpreadsheet\\\\Spreadsheet'): "
       . (class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet') ? 'YES' : 'NO') . PHP_EOL;
  exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function text_error(string $message, int $code = 400): void {
  ob_clean();
  http_response_code($code);
  header('Content-Type: text/plain; charset=UTF-8');
  echo $message;
  exit;
}
function column_exists(mysqli $conn, string $table, string $column): bool {
  $stmt = $conn->prepare(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
  );
  if (!$stmt) return false;
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $stmt->store_result();
  $ok = $stmt->num_rows > 0;
  $stmt->close();
  return $ok;
}
function lookup_scalar(mysqli $conn, string $sql, string $types, array $params): ?string {
  $stmt = $conn->prepare($sql);
  if (!$stmt) return null;
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $val = null;
  if ($res && ($row = $res->fetch_row())) $val = $row[0];
  $stmt->close();
  return $val !== null ? (string)$val : null;
}
function safe_filename(string $s): string {
  $s = trim(preg_replace('/\s+/', ' ', $s));
  $s = preg_replace('/[\\\\\\/:*?"<>|]+/', '_', $s);
  return $s === '' ? 'Registration_List' : $s;
}
function send_secure_headers(string $contentType, string $disposition): void {
  ob_clean();
  header('Content-Type: ' . $contentType);
  header('Content-Disposition: ' . $disposition);
  header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
}

/**
 * @param list<int> $ids
 * @return list<array<string,mixed>>
 */
function nominees_export_fetch_in(mysqli $conn, string $sql, array $ids): array {
  $ids = array_values(array_filter(array_map('intval', $ids)));
  if ($ids === []) {
    return [];
  }
  $out = [];
  foreach (array_chunk($ids, 200) as $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $st = $conn->prepare(str_replace('{in}', $ph, $sql));
    if (!$st) {
      continue;
    }
    $types = str_repeat('i', count($chunk));
    $st->bind_param($types, ...$chunk);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
      $out[] = $row;
    }
    $st->close();
  }
  return $out;
}

function nominees_export_upper(string $value): string {
  $value = trim($value);
  if ($value === '') {
    return '';
  }
  return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

/**
 * Distinct fill per line of business (Excel unique-value grouping).
 *
 * @param array<string,string> $assigned
 */
function nominees_export_lob_fill(string $lob, array &$assigned): string {
  $key = nominees_export_upper($lob);
  if ($key === '') {
    return 'FFFFFFFF';
  }
  $primary = trim(explode(',', $key)[0]);
  if ($primary !== '') {
    $key = $primary;
  }
  $preset = [
    'BAKESHOP'   => 'FFFFFF99',
    'BARBERSHOP' => 'FFC6EFCE',
    'BBQ HOUSE'  => 'FFFFFFFF',
    'BBQ'        => 'FFFFFFFF',
    'CAFE'       => 'FFF4C7EA',
    'CAFÉ'       => 'FFF4C7EA',
    'EATERY'     => 'FFC6EFCE',
  ];
  if (isset($preset[$key])) {
    return $preset[$key];
  }
  if (!isset($assigned[$key])) {
    $palette = [
      'FFFFFF99',
      'FFC6EFCE',
      'FFFFFFFF',
      'FFF4C7EA',
      'FFBDD7EE',
      'FFFFE699',
      'FFD9EAD3',
      'FFFCE4D6',
      'FFE2D5F1',
      'FFDDEBF7',
    ];
    $assigned[$key] = $palette[count($assigned) % count($palette)];
  }
  return $assigned[$key];
}

/**
 * @param list<int> $nomIds
 * @return array<int,string>
 */
function nominees_export_line_of_business_map(mysqli $conn, array $nomIds): array {
  $names = [];
  $append = static function (int $nid, string $name) use (&$names): void {
    $name = trim($name);
    if ($nid <= 0 || $name === '') {
      return;
    }
    if (!isset($names[$nid])) {
      $names[$nid] = [];
    }
    if (!in_array($name, $names[$nid], true)) {
      $names[$nid][] = $name;
    }
  };

  if ($nomIds === []) {
    return [];
  }

  et_ensure_m2m_schema($conn);

  foreach (nominees_export_fetch_in(
    $conn,
    'SELECT net.nomination_id, t.type_name
     FROM tbl_nomination_establishment_types net
     INNER JOIN tbl_establishment_types t ON t.type_id = net.type_id
     WHERE net.nomination_id IN ({in})
     ORDER BY t.type_name ASC',
    $nomIds
  ) as $row) {
    $append((int) $row['nomination_id'], (string) $row['type_name']);
  }

  $missing = static function (array $ids, array $names): array {
    return array_values(array_filter($ids, static fn ($id) => empty($names[(int) $id])));
  };

  $gap = $missing($nomIds, $names);
  if ($gap !== [] && column_exists($conn, 'tbl_nominations', 'establishment_type_id')) {
    foreach (nominees_export_fetch_in(
      $conn,
      'SELECT n.nomination_id, t.type_name
       FROM tbl_nominations n
       INNER JOIN tbl_establishment_types t ON t.type_id = n.establishment_type_id
       WHERE n.nomination_id IN ({in})',
      $gap
    ) as $row) {
      $append((int) $row['nomination_id'], (string) $row['type_name']);
    }
  }

  $gap = $missing($nomIds, $names);
  if ($gap !== [] && column_exists($conn, 'tbl_nominations', 'merged_choice_id')) {
    foreach (nominees_export_fetch_in(
      $conn,
      'SELECT n.nomination_id, t.type_name
       FROM tbl_nominations n
       INNER JOIN tbl_choice_establishment_types cet ON cet.choice_id = n.merged_choice_id
       INNER JOIN tbl_establishment_types t ON t.type_id = cet.type_id
       WHERE n.nomination_id IN ({in})
       ORDER BY t.type_name ASC',
      $gap
    ) as $row) {
      $append((int) $row['nomination_id'], (string) $row['type_name']);
    }

    $gap = $missing($nomIds, $names);
    if ($gap !== [] && column_exists($conn, 'tbl_choices', 'establishment_type_id')) {
      foreach (nominees_export_fetch_in(
        $conn,
        'SELECT n.nomination_id, t.type_name
         FROM tbl_nominations n
         INNER JOIN tbl_choices c ON c.choice_id = n.merged_choice_id
         INNER JOIN tbl_establishment_types t ON t.type_id = c.establishment_type_id
         WHERE n.nomination_id IN ({in})',
        $gap
      ) as $row) {
        $append((int) $row['nomination_id'], (string) $row['type_name']);
      }
    }
  }

  $out = [];
  foreach ($names as $nid => $list) {
    $out[(int) $nid] = nominees_export_upper(implode(', ', $list));
  }
  return $out;
}

// ---------------------------------------------------------------------------
// Inputs
// ---------------------------------------------------------------------------
date_default_timezone_set('Asia/Manila');

// Table downloads submit a snapshot of the applied filters and matching IDs.
// GET remains supported for existing direct report links.
$reportInput = $_POST + $_GET;

$format      = isset($reportInput['format']) ? strtolower(trim($reportInput['format'])) : 'excel';
$scope       = isset($reportInput['scope']) ? strtolower(trim($reportInput['scope'])) : 'all';
$event_id    = (isset($reportInput['event_id']) && $reportInput['event_id'] !== '') ? (int)$reportInput['event_id'] : null;
$category_id = (isset($reportInput['category_id']) && $reportInput['category_id'] !== '') ? (int)$reportInput['category_id'] : null;
$question_id = (isset($reportInput['question_id']) && $reportInput['question_id'] !== '') ? (int)$reportInput['question_id'] : null;
$status      = isset($reportInput['status']) ? strtolower(trim($reportInput['status'])) : '';
$searchText  = trim((string) ($reportInput['search'] ?? ''));
$tableIds    = null;
if ($scope === 'table') {
  $tableIds = json_decode((string) ($reportInput['nomination_ids'] ?? ''), true);
  if (!is_array($tableIds) || !array_is_list($tableIds) || count($tableIds) > 10000) {
    text_error('Invalid table selection. Refresh the report and try again.');
  }
  foreach ($tableIds as $id) {
    if (!is_int($id) || $id <= 0) {
      text_error('Invalid registration ID in the table selection.');
    }
  }
  if (count(array_unique($tableIds)) !== count($tableIds)) {
    text_error('Duplicate registration IDs in the table selection.');
  }
}

$allowedStatuses = ['pending','in_review','needs_info','approved','rejected','merged'];
if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
  $status = '';
}

// Registration reports and exports are scoped to the single active event.
$activeEventId = admin_get_active_event_id($conn);
if ($activeEventId === null || $activeEventId <= 0) {
  text_error('No active event. Activate an event under File Maintenance → Events before exporting.', 403);
}
if (!$event_id || $event_id <= 0) {
  $event_id = $activeEventId;
}
if ((int) $event_id !== (int) $activeEventId) {
  text_error('Exports are limited to the currently active event.', 403);
}

// Normalize scope (current requires question_id)
if ($scope === 'current' && empty($question_id)) {
  $scope = 'all';
}

// Lightweight preflight — returns export passwords to the requesting admin only.
if (isset($reportInput['preflight']) && $reportInput['preflight'] === '1') {
  ob_clean();
  header('Content-Type: application/json; charset=UTF-8');
  if (!in_array($format, ['pdf', 'excel', 'csv'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Unsupported format.']);
    exit;
  }
  $settings = report_export_get_settings($conn);
  echo json_encode([
    'status'      => 'success',
    'format'      => $format,
    'credentials' => report_export_build_credentials_payload($settings, $format),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------------------------------------------------------------------------
// Resolve human labels (for the header band)
// ---------------------------------------------------------------------------
$eventLabel    = null;
$eventYear     = null;
$categoryLabel = null;
$awardLabel    = null;
$statusLabelMap = [
  'pending'    => 'Pending',
  'in_review'  => 'In Review',
  'needs_info' => 'Needs Info',
  'approved'   => 'Approved',
  'rejected'   => 'Rejected',
  'merged'     => 'Merged',
];
$statusLabel  = $status ? ($statusLabelMap[$status] ?? ucwords(str_replace('_',' ',$status))) : 'All';

if ($event_id) {
  $row = null;
  $stmt = $conn->prepare("SELECT event_name, year FROM tbl_events WHERE event_id = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
  }
  if ($row) {
    $eventLabel = (string)($row['event_name'] ?? '');
    $eventYear  = !empty($row['year']) ? (int)$row['year'] : null;
  }
}
if ($category_id) {
  $categoryLabel = lookup_scalar(
    $conn,
    "SELECT category_name FROM tbl_categories WHERE category_id = ? LIMIT 1",
    'i',
    [$category_id]
  );
}
if ($question_id) {
  $awardLabel = lookup_scalar(
    $conn,
    "SELECT question_name FROM tbl_questions WHERE question_id = ? LIMIT 1",
    'i',
    [$question_id]
  );
}

// ---------------------------------------------------------------------------
// Shared report data keeps the table and downloads consistent.
// ---------------------------------------------------------------------------
try {
  $rawRows = nomination_report_fetch_rows($conn, $event_id, $status, $category_id, $question_id);
} catch (Throwable $e) {
  error_log('[nominees_download_report] ' . $e->getMessage());
  text_error('Server error (cannot load registration records).', 500);
}
if ($tableIds !== null) {
  $byId = array_column($rawRows, null, 'nomination_id');
  $selectedRows = [];
  foreach ($tableIds as $id) {
    if (!isset($byId[$id])) {
      text_error('Report data changed. Refresh the table and try downloading again.', 409);
    }
    $selectedRows[] = $byId[$id];
  }
  // An explicitly empty table selection must produce an empty report.
  $rawRows = $selectedRows;
}
$rows = [];
$statusKeyMap = [
  'approved'   => ['label'=>'Approved',   'tone'=>'success'],
  'rejected'   => ['label'=>'Rejected',   'tone'=>'danger'],
  'in_review'  => ['label'=>'In Review',  'tone'=>'info'],
  'needs_info' => ['label'=>'Needs Info', 'tone'=>'warning'],
  'pending'    => ['label'=>'Pending',    'tone'=>'secondary'],
  'merged'     => ['label'=>'Merged',     'tone'=>'primary'],
];
$tally = array_fill_keys(array_keys($statusKeyMap), 0);

$nomIds = array_column($rawRows, 'nomination_id');
$lobByNom = nominees_export_line_of_business_map($conn, $nomIds);

foreach ($rawRows as $r) {
  $raw = strtolower((string)$r['status']);
  if (!isset($statusKeyMap[$raw])) {
    $statusKeyMap[$raw] = ['label' => ucwords(str_replace('_',' ',$raw)), 'tone' => 'secondary'];
    $tally[$raw] = 0;
  }
  $tally[$raw]++;
  $nid = (int)$r['nomination_id'];
  $awardNames = array_column($r['awards'], 'award_title');
  $rows[] = [
    'establishment'     => (string)($r['establishment'] ?? ''),
    'email'             => (string)($r['email'] ?? ''),
    'mobile_number'     => (string) $r['mobile_number'],
    'address'           => (string) $r['address'],
    'categories'        => implode("\n", $r['categories']),
    'line_of_business'  => $lobByNom[$nid] ?? '',
    'awards'            => implode("\n", $awardNames),
    'status_key'        => $raw,
    'status_label'      => $statusKeyMap[$raw]['label'],
    'status_tone'       => $statusKeyMap[$raw]['tone'],
  ];
}

$totalCount = count($rows);

// ---------------------------------------------------------------------------
// Common copy & filename
// ---------------------------------------------------------------------------
$titleText    = "Tatak Ormoc Consumer's Choice Awards";
$subtitleText = 'Registration List';
$nowDt        = new DateTimeImmutable('now');
$generatedText = 'Generated on: ' . $nowDt->format('F j, Y - g:i A');
$adminName    = (string)($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Admin');
$adminId      = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);

// Document reference number: TOCCA-NL-YYYYMMDD-HHMMSS-<adminId>
$docRef = sprintf(
  'TOCCA-NL-%s-%s',
  $nowDt->format('Ymd-His'),
  $adminId ? ('U' . $adminId) : 'U0'
);

$yearForName = $eventYear ?: (int)$nowDt->format('Y');
$fname = safe_filename(sprintf(
  'TOCCA_%d_RegistrationList_%s',
  $yearForName,
  $nowDt->format('Ymd_Hi')
));

// Filter summary (structured rows + legacy single-line text)
$filterRows = report_export_build_filter_rows(
  $eventLabel,
  $eventYear,
  $categoryLabel,
  $awardLabel,
  $statusLabel,
  $scope
);
if ($scope === 'table') {
  $filterRows[count($filterRows) - 1] = ['Scope', 'Current table filters (all pages)'];
  $filterRows[] = ['Search', $searchText !== '' ? $searchText : 'No search text'];
  $filterRows[] = ['Matching registrations', (string) $totalCount];
}

// ---------------------------------------------------------------------------
// Logo (best-effort, embedded in PDF letterhead)
// ---------------------------------------------------------------------------
$logoAbsPath = null;
try {
  $logoCfg = function_exists('getConfig') ? trim((string)getConfig('logo_path', '')) : '';
  if ($logoCfg !== '') {
    // strip leading slashes & resolve against tocca_admin/
    $candidate = __DIR__ . '/' . ltrim(preg_replace('~^https?://[^/]+~', '', $logoCfg), '/');
    if (is_file($candidate)) $logoAbsPath = $candidate;
  }
  if (!$logoAbsPath) {
    foreach (['img/default-logo.png','img/tatakormoclogo.png','img/tocca2023.jpg'] as $cand) {
      $cp = __DIR__ . '/' . $cand;
      if (is_file($cp)) { $logoAbsPath = $cp; break; }
    }
  }
} catch (\Throwable $e) { $logoAbsPath = null; }

// ---------------------------------------------------------------------------
// Report letterhead settings and export protection
// ---------------------------------------------------------------------------
$reportSettings   = report_export_get_settings($conn);
$orgLineText      = $reportSettings['org_line'];

// ---------------------------------------------------------------------------
// Audit log (best-effort — never blocks a download)
// ---------------------------------------------------------------------------
try {
  if (function_exists('audit_log')) {
    audit_log(
      $conn,
      'nomination_reports',
      'export',
      'nomination_list',
      $event_id,
      [
        'format'      => $format,
        'scope'       => $scope,
        'event_id'    => $event_id,
        'event_name'  => $eventLabel,
        'category_id' => $category_id,
        'category'    => $categoryLabel,
        'question_id' => $question_id,
        'award'       => $awardLabel,
        'status'      => $status ?: 'all',
        'rows'        => $totalCount,
        'doc_ref'     => $docRef,
      ]
    );
  }
} catch (\Throwable $e) { /* never fail the export over an audit hiccup */ }

// ---------------------------------------------------------------------------
// Tone -> hex color map (shared by PDF & Excel)
// ---------------------------------------------------------------------------
$toneHex = [
  'success'   => ['bg' => '#D1E7DD', 'fg' => '#0F5132', 'argb_bg' => 'FFD1E7DD', 'argb_fg' => 'FF0F5132'],
  'danger'    => ['bg' => '#F8D7DA', 'fg' => '#842029', 'argb_bg' => 'FFF8D7DA', 'argb_fg' => 'FF842029'],
  'info'      => ['bg' => '#CFF4FC', 'fg' => '#055160', 'argb_bg' => 'FFCFF4FC', 'argb_fg' => 'FF055160'],
  'warning'   => ['bg' => '#FFF3CD', 'fg' => '#664D03', 'argb_bg' => 'FFFFF3CD', 'argb_fg' => 'FF664D03'],
  'primary'   => ['bg' => '#CFE2FF', 'fg' => '#084298', 'argb_bg' => 'FFCFE2FF', 'argb_fg' => 'FF084298'],
  'secondary' => ['bg' => '#E2E3E5', 'fg' => '#41464B', 'argb_bg' => 'FFE2E3E5', 'argb_fg' => 'FF41464B'],
];

// =============================================================================
// PDF
// =============================================================================
if ($format === 'pdf') {
  if (!class_exists('\\Mpdf\\Mpdf')) {
    $msg = 'PDF export not available (mPDF not installed).';
    if ($autoloadLoaded === null) $msg .= ' Autoloader not found. Tried: ' . implode(' | ', $autoloadCandidates);
    text_error($msg, 501);
  }

  $mpdf = new \Mpdf\Mpdf([
    'mode'           => 'utf-8',
    'format'         => 'A4-L',
    'margin_left'    => 14,
    'margin_right'   => 14,
    'margin_top'     => 38,
    'margin_bottom'  => 26,
    'margin_header'  => 8,
    'margin_footer'  => 8,
  ]);

  // PDF document metadata
  $mpdf->SetTitle($titleText . ' — Registration List');
  $mpdf->SetAuthor($adminName);
  $mpdf->SetCreator('Tatak Ormoc CCA Admin Portal');
  $mpdf->SetSubject('Registration List');
  $mpdf->SetKeywords('TOCCA, Registration, ' . ($eventLabel ?: ''));

  // ---- Repeating page header (letterhead) -----------------------------------
  $logoHtml = $logoAbsPath
    ? '<img src="' . htmlspecialchars($logoAbsPath, ENT_QUOTES) . '" style="width:38px;height:38px;object-fit:contain;">'
    : '<div style="width:38px;height:38px;border:1px solid #888;border-radius:4px;text-align:center;line-height:38px;font-weight:700;color:#666;">T</div>';

  $headerHtml = '
  <table width="100%" style="font-family: sans-serif; font-size:9pt; color:#222; border-bottom:1.5px solid #0d47a1;">
    <tr>
      <td width="50" valign="middle">' . $logoHtml . '</td>
      <td valign="middle" style="padding-left:8px;">
        <div style="font-size:11pt;font-weight:700;color:#0d47a1;letter-spacing:.3px;">' . htmlspecialchars($titleText) . '</div>
        <div style="font-size:8.5pt;color:#555;">' . htmlspecialchars($orgLineText, ENT_QUOTES, 'UTF-8') . '</div>
      </td>
      <td align="right" valign="middle">
        <div style="font-size:8pt;color:#666;">Doc Ref: <strong style="color:#222;">' . htmlspecialchars($docRef) . '</strong></div>
        <div style="font-size:8pt;color:#666;">Page {PAGENO} of {nbpg}</div>
      </td>
    </tr>
  </table>';
  $mpdf->SetHTMLHeader($headerHtml);

  // ---- Repeating page footer ------------------------------------------------
  $footerHtml = '
  <table width="100%" style="font-family: sans-serif; font-size:7.5pt; color:#666; border-top:1px solid #ccc; padding-top:3px;">
    <tr>
      <td>Generated by <strong style="color:#222;">' . htmlspecialchars($adminName) . '</strong> on ' . htmlspecialchars($nowDt->format('F j, Y g:i A')) . ' (Asia/Manila)</td>
      <td align="right">Page {PAGENO} of {nbpg}</td>
    </tr>
  </table>';
  $mpdf->SetHTMLFooter($footerHtml);

  // ---- Body content ---------------------------------------------------------
  $html  = report_export_pdf_styles();
  $html .= '<style>table.data-table { font-size:8pt; } .data-table th, .data-table td { padding:5px; vertical-align:top; } .data-table td.status { width:auto; } .breakdown-table { page-break-inside:avoid; }</style>';
  $html .= '<div class="title-band">'
        . '<div class="doc-title">' . htmlspecialchars($subtitleText) . '</div>'
        . '<div class="doc-subtitle">' . htmlspecialchars($generatedText) . '</div>'
        . '</div>';

  $html .= report_export_render_pdf_filter_table($filterRows);

  $html .= '<div class="section-label">Registration Records</div>';
  $html .= '<table class="data-table"><thead><tr>'
        . '<th class="center" style="width:3%;">#</th>'
        . '<th style="width:14%;">Business</th>'
        . '<th style="width:15%;">Email</th>'
        . '<th style="width:11%;">Mobile number</th>'
        . '<th style="width:16%;">Address</th>'
        . '<th style="width:12%;">Category</th>'
        . '<th style="width:21%;">Award titles</th>'
        . '<th class="center" style="width:8%;">Status</th>'
        . '</tr></thead><tbody>';

  if (!empty($rows)) {
    foreach ($rows as $i => $r) {
      $tone = $toneHex[$r['status_tone']] ?? $toneHex['secondary'];
      $estab = $r['establishment'] !== '' ? htmlspecialchars($r['establishment']) : '<span class="muted">—</span>';
      $awards = $r['awards']       !== '' ? nl2br(htmlspecialchars($r['awards'])) : '<span class="muted">—</span>';
      $email = $r['email']         !== '' ? htmlspecialchars($r['email'])         : '<span class="muted">—</span>';
      $mobile = $r['mobile_number'] !== '' ? htmlspecialchars($r['mobile_number']) : '<span class="muted">—</span>';
      $address = $r['address'] !== '' ? htmlspecialchars($r['address']) : '<span class="muted">—</span>';
      $categories = $r['categories'] !== '' ? nl2br(htmlspecialchars($r['categories'])) : '<span class="muted">—</span>';
      $cls = ($i % 2 === 1) ? ' class="alt"' : '';
      $html .= '<tr' . $cls . '>'
            . '<td class="num">' . ($i + 1) . '</td>'
            . '<td>' . $estab . '</td>'
            . '<td>' . $email . '</td>'
            . '<td>' . $mobile . '</td>'
            . '<td>' . $address . '</td>'
            . '<td>' . $categories . '</td>'
            . '<td>' . $awards . '</td>'
            . '<td class="status"><span class="chip" style="background:' . $tone['bg'] . ';color:' . $tone['fg'] . ';">' . htmlspecialchars($r['status_label']) . '</span></td>'
            . '</tr>';
    }
  } else {
    $html .= '<tr><td colspan="8" class="empty">No registrations match the selected filters.</td></tr>';
  }

  // Total row inside the table
  $html .= '<tr class="total-row">'
        . '<td colspan="7" style="text-align:right;">TOTAL REGISTRATIONS</td>'
        . '<td class="status">' . $totalCount . '</td>'
        . '</tr>';
  $html .= '</tbody></table>';

  $html .= report_export_render_pdf_breakdown_table($statusKeyMap, $tally, $toneHex, $totalCount);

  $mpdf->WriteHTML($html);

  if ($reportSettings['pdf_password_enabled'] && $reportSettings['pdf_password'] !== '') {
    // Open password required; allow printing only (no copy/modify).
    $mpdf->SetProtection(['print'], $reportSettings['pdf_password'], null, 128);
  }

  send_secure_headers(
    'application/pdf',
    'attachment; filename="' . $fname . '.pdf"'
  );
  $mpdf->Output($fname . '.pdf', 'D');
  exit;
}

// =============================================================================
// Excel
// =============================================================================
if ($format === 'excel') {
  if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
    $msg = 'Excel export not available (PhpSpreadsheet not installed).';
    if ($autoloadLoaded === null) $msg .= ' Autoloader not found. Tried: ' . implode(' | ', $autoloadCandidates);
    text_error($msg, 501);
  }

  $excelRows = $rows;
  if ($scope !== 'table') {
    usort($excelRows, static function (array $a, array $b): int {
      $lob = strcasecmp((string) $a['line_of_business'], (string) $b['line_of_business']);
      if ($lob !== 0) {
        return $lob;
      }
      return strcasecmp((string) $a['establishment'], (string) $b['establishment']);
    });
  }

  $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sh = $ss->getActiveSheet();
  $sh->setTitle('Registration Records');

  $ss->getProperties()
     ->setCreator($adminName)
     ->setLastModifiedBy($adminName)
     ->setTitle('Registration Records')
     ->setSubject('Registration List')
     ->setDescription('Doc Ref: ' . $docRef . ' — Generated ' . $nowDt->format('c'))
     ->setKeywords('TOCCA Registration')
     ->setCategory('Registration Reports');

  $titleFill = 'FF0D47A1';
  $headerRow = 2;
  $lastCol = 'I';

  $sh->mergeCells('A1:I1')->setCellValue('A1', 'Registration Records');
  $sh->getRowDimension(1)->setRowHeight(22);
  $sh->getStyle('A1:I1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
  $sh->getStyle('A1:I1')->getFill()
     ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
     ->getStartColor()->setARGB($titleFill);
  $sh->getStyle('A1')->getAlignment()
     ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
     ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

  $sh->setCellValue('A2', '#')
     ->setCellValue('B2', 'Business')
     ->setCellValue('C2', 'Email')
     ->setCellValue('D2', 'Status')
     ->setCellValue('E2', 'LINE OF BUSINESS')
     ->setCellValue('F2', 'Mobile number')
     ->setCellValue('G2', 'Address')
     ->setCellValue('H2', 'Category')
     ->setCellValue('I2', 'Award titles');
  $sh->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
  $sh->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFill()
     ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
     ->getStartColor()->setARGB($titleFill);
  $sh->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getAlignment()
     ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
     ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

  $row = $headerRow + 1;
  $lobFills = [];
  if ($excelRows !== []) {
    foreach ($excelRows as $i => $r) {
      $business = $r['establishment'] !== '' ? $r['establishment'] : '—';
      $email    = $r['email'] !== '' ? $r['email'] : '—';
      $lob      = $r['line_of_business'] !== '' ? $r['line_of_business'] : '';
      $phone    = $r['mobile_number'];

      $sh->setCellValue("A{$row}", $i + 1);
      $sh->setCellValueExplicit("B{$row}", $business, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
      $sh->setCellValueExplicit("C{$row}", $email, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
      $sh->setCellValue("D{$row}", $r['status_label']);
      $sh->setCellValue("E{$row}", $lob);
      $sh->setCellValueExplicit(
        "F{$row}",
        $phone,
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
      );
      $sh->setCellValueExplicit("G{$row}", $r['address'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
      $sh->setCellValueExplicit("H{$row}", $r['categories'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
      $sh->setCellValueExplicit("I{$row}", $r['awards'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
      $sh->getStyle("A{$row}:{$lastCol}{$row}")->getAlignment()
         ->setWrapText(true)
         ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
      // Excel does not reliably auto-fit wrapped cells generated by a library.
      $lineCount = count(explode("\n", $r['awards']));
      foreach (['establishment' => 28, 'email' => 32, 'address' => 40, 'categories' => 30, 'awards' => 60] as $key => $width) {
        $wrappedLines = 0;
        foreach (explode("\n", $r[$key]) as $line) {
          $wrappedLines += max(1, (int) ceil(mb_strlen($line, 'UTF-8') / ($width - 2)));
        }
        $lineCount = max($lineCount, $wrappedLines);
      }
      $sh->getRowDimension($row)->setRowHeight(min(409, max(24, $lineCount * 15 + 6)));

      $fill = nominees_export_lob_fill($lob, $lobFills);
      $sh->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
         ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
         ->getStartColor()->setARGB($fill);
      $sh->getStyle("A{$row}")->getAlignment()
         ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
      $sh->getStyle("D{$row}")->getAlignment()
         ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

      if ($r['status_key'] === 'approved') {
        $sh->getStyle("D{$row}")->getFont()->setBold(true)->getColor()->setARGB('FF00B050');
      } else {
        $tone = $toneHex[$r['status_tone']] ?? $toneHex['secondary'];
        $sh->getStyle("D{$row}")->getFont()->setBold(true)->getColor()->setARGB($tone['argb_fg']);
      }

      $row++;
    }
  } else {
    $sh->mergeCells("A{$row}:{$lastCol}{$row}")->setCellValue("A{$row}", 'No registrations match the selected filters.');
    $sh->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setARGB('FF999999');
    $sh->getStyle("A{$row}")->getAlignment()
       ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $row++;
  }

  $lastDataRow = max($headerRow, $row - 1);
  $sh->getStyle("A1:{$lastCol}{$lastDataRow}")->getBorders()->getAllBorders()
     ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
     ->getColor()->setARGB('FFB0B0B0');

  $sh->getColumnDimension('A')->setWidth(6);
  $sh->getColumnDimension('B')->setWidth(28);
  $sh->getColumnDimension('C')->setWidth(32);
  $sh->getColumnDimension('D')->setWidth(14);
  $sh->getColumnDimension('E')->setWidth(22);
  $sh->getColumnDimension('F')->setWidth(18);
  $sh->getColumnDimension('G')->setWidth(40);
  $sh->getColumnDimension('H')->setWidth(30);
  $sh->getColumnDimension('I')->setWidth(60);

  $sh->setAutoFilter("A{$headerRow}:{$lastCol}{$lastDataRow}");
  $sh->freezePane('A' . ($headerRow + 1));
  $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow);
  $sh->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
  $sh->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
  $sh->getPageSetup()->setFitToPage(true);
  $sh->getPageSetup()->setFitToWidth(1);
  $sh->getPageSetup()->setFitToHeight(0);

  if ($reportSettings['excel_protect_enabled'] && $reportSettings['excel_password'] !== '') {
    $editPassword = $reportSettings['excel_password'];
    $ss->getSecurity()->setLockStructure(true);
    $ss->getSecurity()->setLockWindows(true);
    $ss->getSecurity()->setWorkbookPassword($editPassword);
    $sh->getProtection()->setSheet(true);
    $sh->getProtection()->setPassword($editPassword);
    $sh->getProtection()->setSort(true);
    $sh->getProtection()->setAutoFilter(true);
    $sh->getProtection()->setInsertRows(true);
    $sh->getProtection()->setFormatCells(true);
    $sh->getProtection()->setSelectLockedCells(true);
    $sh->getProtection()->setSelectUnlockedCells(true);
  }

  send_secure_headers(
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'attachment; filename="' . $fname . '.xlsx"'
  );
  $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
  $writer->save('php://output');
  exit;
}

// =============================================================================
// CSV
// =============================================================================
if ($format === 'csv') {
  send_secure_headers(
    'text/csv; charset=UTF-8',
    'attachment; filename="' . $fname . '.csv"'
  );

  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");

  report_export_write_csv_sections($out, [
    [
      'title' => 'Document',
      'rows'  => [
        [$titleText],
        [$subtitleText],
        [$orgLineText],
        ['Doc Ref', $docRef],
        ['Generated', $generatedText],
        ['Generated by', $adminName],
      ],
    ],
    [
      'title' => 'Report Context',
      'rows'  => array_map(static fn ($pair) => [$pair[0], $pair[1]], $filterRows),
    ],
    [
      'title' => 'Registration Records',
      'rows'  => array_merge(
        [['#', 'Business', 'Email', 'Mobile number', 'Address', 'Category', 'Award titles', 'Status']],
        !empty($rows)
          ? array_map(static function ($i, $r) {
              return [
                $i + 1,
                $r['establishment'] !== '' ? $r['establishment'] : '—',
                $r['email']         !== '' ? $r['email']         : '—',
                $r['mobile_number'] !== '' ? $r['mobile_number'] : '—',
                $r['address']       !== '' ? $r['address']       : '—',
                $r['categories']    !== '' ? $r['categories']    : '—',
                $r['awards']        !== '' ? $r['awards']        : '—',
                $r['status_label'],
              ];
            }, array_keys($rows), $rows)
          : [['', 'No registrations match the selected filters.', '', '', '', '', '', '']],
        [['', '', '', '', '', '', 'TOTAL REGISTRATIONS', $totalCount]]
      ),
    ],
    [
      'title' => 'Status Breakdown',
      'rows'  => array_merge(
        [['Status', 'Count', 'Share', '', '']],
        $totalCount > 0
          ? array_values(array_filter(array_map(static function ($key, $meta) use ($tally, $totalCount) {
              $n = (int) ($tally[$key] ?? 0);
              if ($n === 0) return null;
              return [
                $meta['label'],
                $n,
                round(($n / $totalCount) * 100, 1) . '%',
                '',
                '',
              ];
            }, array_keys($statusKeyMap), $statusKeyMap)))
          : [['No records to summarize.', '', '', '', '']],
        $totalCount > 0 ? [['Total', $totalCount, '100%', '', '']] : []
      ),
    ],
  ]);

  fclose($out);
  exit;
}

// =============================================================================
// Fallback
// =============================================================================
text_error('Unsupported format. Use format=excel, format=pdf, or format=csv.', 400);
