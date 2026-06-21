<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
date_default_timezone_set('Asia/Manila');

function fail($m,$c=400){ http_response_code($c); echo json_encode(['status'=>'error','message'=>$m]); exit; }
function ok($p=[]){ echo json_encode(['status'=>'success'] + $p); exit; }

// --- helpers ---
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

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
- from (Y-m-d, optional)
- to   (Y-m-d, optional)
- q    (string; search in recipient_email/name/subject)
- limit (int, default 200)
*/
$event_id = isset($_GET['event_id']) && $_GET['event_id'] !== '' ? (int)$_GET['event_id'] : null;
$from     = !empty($_GET['from']) ? $_GET['from'] : null;
$to       = !empty($_GET['to'])   ? $_GET['to']   : null;
$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit    = isset($_GET['limit']) ? max(10, (int)$_GET['limit']) : 200;

// Base WHERE (applies to ALL buckets)
$where = [];
$types = '';
$args  = [];

// Always restrict to unarchived events via JOIN alias "e"
$where[] = $unarchivedWhere;

if ($event_id) { $where[] = 'm.event_id = ?'; $types .= 'i'; $args[] = $event_id; }
if ($from)     { $where[] = 'm.created_at >= ?'; $types .= 's'; $args[] = $from.' 00:00:00'; }
if ($to)       { $where[] = 'm.created_at <= ?'; $types .= 's'; $args[] = $to.' 23:59:59'; }
if ($q !== '') {
  $where[] = '(m.recipient_email LIKE ? OR m.recipient_name LIKE ? OR m.subject LIKE ?)';
  $types  .= 'sss';
  $like = '%'.$q.'%';
  $args[] = $like; $args[] = $like; $args[] = $like;
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// Base FROM with JOIN to events so unarchived filter works
$FROM = "FROM tbl_comm_messages m
         JOIN tbl_events e ON e.event_id = m.event_id";

// Small runner to avoid repetition
$run = function(string $extraCond) use ($conn, $FROM, $whereSql, $types, $args, $limit) {
  $sql = "
    SELECT 
      m.id, m.created_at, m.sent_at, m.status, m.type,
      m.recipient_name, m.recipient_email, m.subject, m.retries, m.error_text
    $FROM
    $whereSql ".($whereSql ? "AND" : "WHERE")." $extraCond
    ORDER BY m.id DESC
    LIMIT ?
  ";
  $t = $types . 'i';
  $a = $args; $a[] = $limit;

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($t, ...$a);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $rows;
};

// ---- Bucket 1: SENT of any type ----
$sentRows = $run("m.status = 'sent'");

// ---- Bucket 2: QR emails (any status) ----
$qrRows = $run("m.type = 'qr_email'");

// ---- Bucket 3: Nomination status emails (any status) ----
$statusRows = $run("m.type = 'nomination_status'");

ok([
  'sent'        => $sentRows,    // only status='sent' (any type)
  'qr'          => $qrRows,      // type='qr_email' (any status)
  'status_msgs' => $statusRows,  // type='nomination_status' (any status)
]);
