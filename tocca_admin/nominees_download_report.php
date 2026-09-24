<?php
// nominees_download_report.php
// Registration list export for TOCCA in PDF, Excel, or CSV.

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

$format      = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'excel';   // excel | pdf | csv
$scope       = isset($_GET['scope'])  ? strtolower(trim($_GET['scope']))  : 'all';     // current | all
$event_id    = (isset($_GET['event_id'])    && $_GET['event_id']    !== '') ? (int)$_GET['event_id']    : null;
$category_id = (isset($_GET['category_id']) && $_GET['category_id'] !== '') ? (int)$_GET['category_id'] : null;
$question_id = (isset($_GET['question_id']) && $_GET['question_id'] !== '') ? (int)$_GET['question_id'] : null;
$status      = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '';

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
if (isset($_GET['preflight']) && $_GET['preflight'] === '1') {
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
// Identify field IDs for business name & email (varies per deployment)
// ---------------------------------------------------------------------------
$nomHasEvent       = column_exists($conn, 'tbl_nominations', 'event_id');
$hasEventsTable    = column_exists($conn, 'tbl_events', 'event_id');
$eventsArchivedCol = column_exists($conn, 'tbl_events', 'is_archived')
  ? 'is_archived'
  : (column_exists($conn, 'tbl_events', 'archived_at') ? 'archived_at' : null);
$hasNq       = column_exists($conn, 'tbl_nomination_questions', 'nomination_id') &&
               column_exists($conn, 'tbl_nomination_questions', 'question_id');

$bnFieldId = null;
$emFieldId = null;
$phoneFieldIds = [];
try {
  if ($r = $conn->query("SELECT id FROM tbl_nomination_fields WHERE name='business_name' LIMIT 1")) {
    if ($row = $r->fetch_assoc()) $bnFieldId = (int)$row['id'];
  }
  if ($r = $conn->query("SELECT id FROM tbl_nomination_fields WHERE name='email' LIMIT 1")) {
    if ($row = $r->fetch_assoc()) $emFieldId = (int)$row['id'];
  }
  if ($r = $conn->query(
    "SELECT id FROM tbl_nomination_fields
     WHERE name IN ('mobile_number','contact_phone','phone','mobile','contact_number')"
  )) {
    while ($row = $r->fetch_assoc()) {
      $phoneFieldIds[] = (int) $row['id'];
    }
  }
} catch (\Throwable $e) { /* ignore */ }

$ansValueCol = 'answer';
foreach (['answer','value','response','text','text_value','val'] as $cand) {
  if (column_exists($conn, 'tbl_nomination_answers', $cand)) { $ansValueCol = $cand; break; }
}

// ---------------------------------------------------------------------------
// Build query (one row per registration)
// ---------------------------------------------------------------------------
$selBn = $bnFieldId
  ? "(SELECT MAX(a1.$ansValueCol) FROM tbl_nomination_answers a1 WHERE a1.nomination_id=n.nomination_id AND a1.field_id=$bnFieldId) AS establishment"
  : "NULL AS establishment";
$selEm = $emFieldId
  ? "(SELECT MAX(a2.$ansValueCol) FROM tbl_nomination_answers a2 WHERE a2.nomination_id=n.nomination_id AND a2.field_id=$emFieldId) AS email"
  : "NULL AS email";

$nomPhoneCol = column_exists($conn, 'tbl_nominations', 'mobile_number')
  ? 'n.mobile_number'
  : (column_exists($conn, 'tbl_nominations', 'phone') ? 'n.phone' : null);
$ansPhone = $phoneFieldIds !== []
  ? "(SELECT MAX(a3.$ansValueCol) FROM tbl_nomination_answers a3
      WHERE a3.nomination_id=n.nomination_id
        AND a3.field_id IN (" . implode(',', $phoneFieldIds) . "))"
  : "NULL";
$selPhone = $nomPhoneCol
  ? "COALESCE(NULLIF(TRIM($nomPhoneCol), ''), $ansPhone) AS contact_number"
  : "$ansPhone AS contact_number";

$sql = "SELECT
          n.nomination_id,
          $selBn,
          $selEm,
          $selPhone,
          n.status
        FROM tbl_nominations n ";

// Optional unarchived-event join so the export matches the on-screen list,
// which only ever shows registrations belonging to unarchived events.
if ($nomHasEvent && $hasEventsTable && $eventsArchivedCol) {
  $sql .= " JOIN tbl_events e ON e.event_id = n.event_id ";
}

$sql .= " WHERE 1=1 ";

$types  = '';
$params = [];

if ($nomHasEvent && $hasEventsTable && $eventsArchivedCol) {
  $sql .= ($eventsArchivedCol === 'is_archived')
    ? " AND e.is_archived = 0 "
    : " AND e.archived_at IS NULL ";
}

if ($event_id && $nomHasEvent) {
  $sql   .= " AND n.event_id = ? ";
  $types .= 'i'; $params[] = $event_id;
}
if ($status !== '') {
  $sql   .= " AND LOWER(n.status) = ? ";
  $types .= 's'; $params[] = $status;
}
if ($hasNq) {
  if ($question_id && $scope === 'current') {
    $sql   .= " AND EXISTS (SELECT 1 FROM tbl_nomination_questions nq
                            WHERE nq.nomination_id = n.nomination_id
                              AND nq.question_id   = ?) ";
    $types .= 'i'; $params[] = $question_id;
  } else {
    if ($category_id) {
      $sql   .= " AND EXISTS (
                     SELECT 1
                     FROM tbl_nomination_questions nq
                     JOIN tbl_questions q ON q.question_id = nq.question_id
                     WHERE nq.nomination_id = n.nomination_id
                       AND q.category_id    = ?
                   ) ";
      $types .= 'i'; $params[] = $category_id;
    }
    if ($question_id) {
      $sql   .= " AND EXISTS (SELECT 1 FROM tbl_nomination_questions nq2
                              WHERE nq2.nomination_id = n.nomination_id
                                AND nq2.question_id   = ?) ";
      $types .= 'i'; $params[] = $question_id;
    }
  }
}
$sql .= " ORDER BY establishment IS NULL, establishment ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) text_error('Server error (cannot prepare export query).', 500);
if ($types !== '') {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

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

$nomIds = [];
$rawRows = [];
while ($r = $res->fetch_assoc()) {
  $nomIds[] = (int)$r['nomination_id'];
  $rawRows[] = $r;
}
$stmt->close();

$lobByNom = nominees_export_line_of_business_map($conn, $nomIds);

// Build award list per nomination_id
$awardsMap = [];
if ($hasNq && !empty($nomIds)) {
  $chunks = array_chunk($nomIds, 200);
  foreach ($chunks as $chunk) {
    $phs = implode(',', array_fill(0, count($chunk), '?'));
    $awdSql = "SELECT nq.nomination_id, q.question_name, c.category_name
               FROM tbl_nomination_questions nq
               JOIN tbl_questions q ON q.question_id = nq.question_id
               JOIN tbl_categories c ON c.category_id = q.category_id
               WHERE nq.nomination_id IN ($phs)";
    if ($question_id) {
      $awdSql .= " AND nq.question_id = ?";
    } elseif ($category_id) {
      $awdSql .= " AND q.category_id = ?";
    }
    $awdSql .= " ORDER BY c.category_name, q.question_name";
    $awdSt = $conn->prepare($awdSql);
    $awdTypes = str_repeat('i', count($chunk));
    $awdParams = $chunk;
    if ($question_id) {
      $awdTypes .= 'i'; $awdParams[] = $question_id;
    } elseif ($category_id) {
      $awdTypes .= 'i'; $awdParams[] = $category_id;
    }
    $awdSt->bind_param($awdTypes, ...$awdParams);
    $awdSt->execute();
    $awdRes = $awdSt->get_result();
    while ($a = $awdRes->fetch_assoc()) {
      $awardsMap[(int)$a['nomination_id']][] = $a['question_name'];
    }
    $awdSt->close();
  }
}

foreach ($rawRows as $r) {
  $raw = strtolower((string)$r['status']);
  if (!isset($statusKeyMap[$raw])) {
    $statusKeyMap[$raw] = ['label' => ucwords(str_replace('_',' ',$raw)), 'tone' => 'secondary'];
    $tally[$raw] = 0;
  }
  $tally[$raw]++;
  $nid = (int)$r['nomination_id'];
  $awardNames = $awardsMap[$nid] ?? [];
  $rows[] = [
    'establishment'     => (string)($r['establishment'] ?? ''),
    'email'             => (string)($r['email'] ?? ''),
    'contact_number'    => trim((string)($r['contact_number'] ?? '')),
    'line_of_business'  => $lobByNom[$nid] ?? '',
    'awards'            => implode(', ', $awardNames),
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
    'format'         => 'A4',
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
  $html .= '<div class="title-band">'
        . '<div class="doc-title">' . htmlspecialchars($subtitleText) . '</div>'
        . '<div class="doc-subtitle">' . htmlspecialchars($generatedText) . '</div>'
        . '</div>';

  $html .= report_export_render_pdf_filter_table($filterRows);

  $html .= '<div class="section-label">Registration Records</div>';
  $html .= '<table class="data-table"><thead><tr>'
        . '<th class="center" style="width:28px;">#</th>'
        . '<th style="width:28%;">Business</th>'
        . '<th style="width:26%;">Award(s)</th>'
        . '<th style="width:26%;">Email</th>'
        . '<th class="center" style="width:90px;">Status</th>'
        . '</tr></thead><tbody>';

  if (!empty($rows)) {
    foreach ($rows as $i => $r) {
      $tone = $toneHex[$r['status_tone']] ?? $toneHex['secondary'];
      $estab = $r['establishment'] !== '' ? htmlspecialchars($r['establishment']) : '<span class="muted">—</span>';
      $awards = $r['awards']       !== '' ? htmlspecialchars($r['awards'])       : '<span class="muted">—</span>';
      $email = $r['email']         !== '' ? htmlspecialchars($r['email'])         : '<span class="muted">—</span>';
      $cls = ($i % 2 === 1) ? ' class="alt"' : '';
      $html .= '<tr' . $cls . '>'
            . '<td class="num">' . ($i + 1) . '</td>'
            . '<td>' . $estab . '</td>'
            . '<td style="font-size:8pt;">' . $awards . '</td>'
            . '<td>' . $email . '</td>'
            . '<td class="status"><span class="chip" style="background:' . $tone['bg'] . ';color:' . $tone['fg'] . ';">' . htmlspecialchars($r['status_label']) . '</span></td>'
            . '</tr>';
    }
  } else {
    $html .= '<tr><td colspan="5" class="empty">No registrations match the selected filters.</td></tr>';
  }

  // Total row inside the table
  $html .= '<tr class="total-row">'
        . '<td colspan="4" style="text-align:right;">TOTAL REGISTRATIONS</td>'
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
  usort($excelRows, static function (array $a, array $b): int {
    $lob = strcasecmp((string) $a['line_of_business'], (string) $b['line_of_business']);
    if ($lob !== 0) {
      return $lob;
    }
    return strcasecmp((string) $a['establishment'], (string) $b['establishment']);
  });

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
  $lastCol = 'F';

  $sh->mergeCells('A1:E1')->setCellValue('A1', 'Registration Records');
  $sh->getRowDimension(1)->setRowHeight(22);
  $sh->getStyle('A1:E1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
  $sh->getStyle('A1:E1')->getFill()
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
     ->setCellValue('F2', 'contact number');
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
      $phone    = $r['contact_number'] !== '' ? $r['contact_number'] : '';

      $sh->setCellValue("A{$row}", $i + 1);
      $sh->setCellValue("B{$row}", $business);
      $sh->setCellValue("C{$row}", $email);
      $sh->setCellValue("D{$row}", $r['status_label']);
      $sh->setCellValue("E{$row}", $lob);
      $sh->setCellValueExplicit(
        "F{$row}",
        $phone,
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
      );

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
        [['#', 'Business', 'Award(s)', 'Email', 'Status']],
        !empty($rows)
          ? array_map(static function ($i, $r) {
              return [
                $i + 1,
                $r['establishment'] !== '' ? $r['establishment'] : '—',
                $r['awards']        !== '' ? $r['awards']        : '—',
                $r['email']         !== '' ? $r['email']         : '—',
                $r['status_label'],
              ];
            }, array_keys($rows), $rows)
          : [['', 'No registrations match the selected filters.', '', '', '']],
        [['', '', '', 'TOTAL REGISTRATIONS', $totalCount]]
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
