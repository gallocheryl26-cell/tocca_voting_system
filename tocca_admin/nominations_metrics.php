<?php
require_once __DIR__ . '/require_admin_api.php';
date_default_timezone_set('Asia/Manila');

/* Resolve event: use ?event_id=… if provided; else active; else newest */
$event_id = isset($_GET['event_id']) && $_GET['event_id'] !== '' ? (int)$_GET['event_id'] : null;
if (!$event_id) {
  $res = $conn->query("SELECT event_id FROM tbl_events WHERE is_active=1 ORDER BY year DESC, event_id DESC LIMIT 1");
  if ($res && $res->num_rows) {
    $event_id = (int)$res->fetch_assoc()['event_id'];
  } else {
    $res2 = $conn->query("SELECT event_id FROM tbl_events ORDER BY year DESC, event_id DESC LIMIT 1");
    if ($res2 && $res2->num_rows) $event_id = (int)$res2->fetch_assoc()['event_id'];
  }
}

/* Window: last N days (default 30, min 7, max 180) */
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
$days = max(7, min(180, $days));

try {
  // --- Daily counts (last N days)
  $where = [];
  $types = '';
  $params = [];

  $where[] = "created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
  $types .= 'i';
  $params[] = $days;

  if (!empty($event_id)) {
    $where[] = "event_id = ?";
    $types .= 'i';
    $params[] = $event_id;
  }

  $sqlDaily = "SELECT DATE(created_at) AS d, COUNT(*) AS c
               FROM tbl_nominations
               ".(count($where) ? "WHERE ".implode(' AND ',$where):"")."
               GROUP BY DATE(created_at)
               ORDER BY d ASC";

  $stmt = $conn->prepare($sqlDaily);
  if ($types) $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $dailyRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  // --- Status counts
  $where2 = [];
  $types2 = '';
  $params2 = [];
  if (!empty($event_id)) { $where2[]="event_id=?"; $types2.='i'; $params2[]=$event_id; }

  $sqlStatus = "SELECT COALESCE(NULLIF(TRIM(status),''),'pending') AS status, COUNT(*) AS cnt
                FROM tbl_nominations
                ".(count($where2) ? "WHERE ".implode(' AND ',$where2):"")."
                GROUP BY status";

  $stmt2 = $conn->prepare($sqlStatus);
  if ($types2) $stmt2->bind_param($types2, ...$params2);
  $stmt2->execute();
  $statusRows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt2->close();

  // Build status map
  $statusMap = ['pending'=>0,'in_review'=>0,'needs_info'=>0,'approved'=>0,'rejected'=>0,'merged'=>0];
  $total = 0;
  foreach ($statusRows as $r) {
    $k = strtolower($r['status']);
    $cnt = (int)$r['cnt'];
    $statusMap[$k] = ($statusMap[$k] ?? 0) + $cnt;
    $total += $cnt;
  }

  echo json_encode([
    'status' => 'success',
    'event_id' => (int)$event_id,
    'window_days' => $days,
    'daily' => array_map(fn($r)=>['date'=>$r['d'],'count'=>(int)$r['c']], $dailyRows),
    'status_counts' => $statusMap,
    'total' => $total
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'Server error','detail'=>$e->getMessage()]);
}
