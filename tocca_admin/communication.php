<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
date_default_timezone_set('Asia/Manila');

function fail($m,$c=400){ http_response_code($c); echo json_encode(['status'=>'error','message'=>$m]); exit; }
function ok($p=[]){ echo json_encode(['status'=>'success'] + $p); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'list') {
  $event_id = isset($_GET['event_id']) && $_GET['event_id'] !== '' ? (int)$_GET['event_id'] : null;
  $from     = $_GET['from'] ?? null; // YYYY-MM-DD
  $to       = $_GET['to'] ?? null;   // YYYY-MM-DD
  $q        = trim($_GET['q'] ?? '');
  $limit    = max(10, (int)($_GET['limit'] ?? 200));

  $w = []; $p = []; $types = '';
  if ($event_id) { $w[] = "event_id=?"; $p[] = $event_id; $types.='i'; }
  if ($from)     { $w[] = "created_at>=?"; $p[] = $from.' 00:00:00'; $types.='s'; }
  if ($to)       { $w[] = "created_at<=?"; $p[] = $to.' 23:59:59'; $types.='s'; }
  if ($q !== '') {
    $w[] = "(recipient_email LIKE ? OR subject LIKE ?)";
    $like = '%'.$q.'%';
    $p[] = $like; $types.='s';
    $p[] = $like; $types.='s';
  }
  $where = $w ? ('WHERE '.implode(' AND ',$w)) : '';
  $sql = "SELECT id, created_at, recipient_email, subject, type, sent_at, status, error_text
          FROM tbl_comm_messages
          $where
          ORDER BY id DESC
          LIMIT ?";
  $p[] = $limit; $types.='i';

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$p);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  ok(['rows'=>$rows]);
}

if ($action === 'qr_only_stats') {
  $sql = "SELECT status, COUNT(*) c FROM tbl_comm_messages WHERE type='qr_email' GROUP BY status";
  $r = $conn->query($sql);
  $stats = ['sent'=>0,'failed'=>0,'pending'=>0];
  while ($row = $r->fetch_assoc()) {
    $stats[$row['status']] = (int)$row['c'];
  }
  ok(['stats'=>$stats]);
}

if ($action === 'qr_only_list') {
  $limit = max(10, (int)($_GET['limit'] ?? 200));
  $sql = "SELECT id, created_at, recipient_email, subject, status, sent_at, error_text
          FROM tbl_comm_messages
          WHERE type='qr_email'
          ORDER BY id DESC
          LIMIT $limit";
  $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
  ok(['rows'=>$rows]);
}

if ($action === 'get') {
  $id = (int)($_GET['id'] ?? 0);
  if (!$id) fail('Missing id');
  $stmt = $conn->prepare("SELECT * FROM tbl_comm_messages WHERE id=?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if (!$row) fail('Not found', 404);
  ok(['row'=>$row]);
}

fail('Unknown action', 404);
