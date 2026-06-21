<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
date_default_timezone_set('Asia/Manila');

function fail($m,$c=400){ http_response_code($c); echo json_encode(['status'=>'error','message'=>$m]); exit; }
function ok($p=[]){ echo json_encode(['status'=>'success'] + $p, JSON_UNESCAPED_UNICODE); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// --- schema helper ---
function has_col(mysqli $conn, string $table, string $col): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ss', $table, $col);
  $stmt->execute();
  $stmt->store_result();
  $ok = $stmt->num_rows > 0;
  $stmt->close();
  return $ok;
}

// Work out the "unarchived" condition for tbl_events
$hasIsArchived = has_col($conn, 'tbl_events', 'is_archived');
$hasArchivedAt = has_col($conn, 'tbl_events', 'archived_at');
$hasIsActive   = has_col($conn, 'tbl_events', 'is_active');

if ($hasIsArchived) {
  $unarchivedWhere = 'e.is_archived = 0';
} elseif ($hasArchivedAt) {
  $unarchivedWhere = 'e.archived_at IS NULL';
} else {
  // Fallback: treat active as unarchived if no archive flag exists
  $unarchivedWhere = 'e.is_active = 1';
}

/*
Query params:
- event_id (int, optional)
- from (Y-m-d, optional)   -> applies to sent_at/created_at
- to   (Y-m-d, optional)   -> applies to sent_at/created_at
- q    (string; search in recipient_email/name/subject)
- limit (int, default 200)
*/
$event_id = isset($_GET['event_id']) && $_GET['event_id'] !== '' ? (int)$_GET['event_id'] : null;
$from     = !empty($_GET['from']) ? $_GET['from'] : null; // YYYY-MM-DD
$to       = !empty($_GET['to'])   ? $_GET['to']   : null; // YYYY-MM-DD
$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit    = isset($_GET['limit']) ? max(10, (int)$_GET['limit']) : 200;

// Base WHERE: only QR emails (case-insensitive)
$where = ["LOWER(m.type)='qr_email'"];

// Enforce: include only unarchived events, but keep rows with NULL event_id
$where[] = "(m.event_id IS NULL OR ($unarchivedWhere))";

$types = '';
$args  = [];

// Event filter (optional): include NULL to avoid hiding batch rows without event_id
if ($event_id) {
  $where[] = '(m.event_id = ? OR m.event_id IS NULL)';
  $types  .= 'i';
  $args[]  = $event_id;
}

// Date filters follow ORDER BY key (COALESCE)
if ($from) {
  $where[] = 'COALESCE(m.sent_at, m.created_at) >= ?';
  $types  .= 's';
  $args[]  = $from.' 00:00:00';
}
if ($to) {
  $where[] = 'COALESCE(m.sent_at, m.created_at) <= ?';
  $types  .= 's';
  $args[]  = $to.' 23:59:59';
}

if ($q !== '') {
  $where[]='(m.recipient_email LIKE ? OR m.recipient_name LIKE ? OR m.subject LIKE ?)';
  $types.='sss';
  $like = '%'.$q.'%';
  $args[]=$like; $args[]=$like; $args[]=$like;
}

$whereSql = 'WHERE '.implode(' AND ', $where);

// LEFT JOIN so NULL event_id rows are preserved
$sql = "
  SELECT 
    m.id, m.event_id, m.recipient_name, m.recipient_email, m.subject,
    m.created_at, m.scheduled_at, m.sent_at, m.status, m.retries, m.error_text,
    m.type, m.body_html
  FROM tbl_comm_messages m
  LEFT JOIN tbl_events e ON e.event_id = m.event_id
  $whereSql
  ORDER BY COALESCE(m.sent_at, m.created_at) DESC, m.id DESC
  LIMIT ?
";
$types .= 'i';
$args[]  = $limit;

$stmt = $conn->prepare($sql);
if (!$stmt) fail('SQL prepare failed: '.$conn->error, 500);
$stmt->bind_param($types, ...$args);
if (!$stmt->execute()) fail('SQL execute failed: '.$stmt->error, 500);

$res = $stmt->get_result();
$qr = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ok(['qr' => $qr]);
