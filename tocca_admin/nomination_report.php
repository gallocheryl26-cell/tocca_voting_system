<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/admin_active_event.php';
require_once __DIR__ . '/includes/nomination_report_data.php';

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

/* ---------------------- helpers ---------------------- */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function jerr($m, $c=400){
  http_response_code($c);
  echo json_encode(['status'=>'error','message'=>$m]);
  exit;
}

function db_name(mysqli $conn): string {
  $resDb = $conn->query("SELECT DATABASE() AS db");
  $rowDb = $resDb ? $resDb->fetch_assoc() : null;
  return $rowDb ? (string)$rowDb['db'] : '';
}

function table_exists(mysqli $conn, string $table): bool {
  $db = db_name($conn);
  $sql = "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return false;
  $stmt->bind_param('ss', $db, $table);
  $stmt->execute();
  $stmt->store_result();
  return $stmt->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
  $db = db_name($conn);
  $sql = "SELECT 1
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return false;
  $stmt->bind_param('sss', $db, $table, $column);
  $stmt->execute();
  $stmt->store_result();
  return $stmt->num_rows > 0;
}

function stmt_all_assoc(mysqli_stmt $stmt, array $fields) {
  $res = $stmt->get_result();
  if (!$res) return [];
  $rows = [];
  while ($row = $res->fetch_assoc()) {
    $out = [];
    foreach ($fields as $f) $out[$f] = $row[$f] ?? null;
    $rows[] = $out;
  }
  return $rows;
}

/** Unified "unarchived" condition for alias e (tbl_events) */
function unarchived_where(mysqli $conn): string {
  $hasIsArchived = column_exists($conn, 'tbl_events', 'is_archived');
  $hasArchivedAt = column_exists($conn, 'tbl_events', 'archived_at');
  if ($hasIsArchived) return 'e.is_archived = 0';
  if ($hasArchivedAt) return 'e.archived_at IS NULL';
  return 'e.is_active = 1';
}

/**
 * Registration reports are scoped to the single active event only.
 */
function nomination_report_require_active_event(mysqli $conn): int
{
  $eventId = admin_get_active_event_id($conn);
  if ($eventId === null || $eventId <= 0) {
    jerr(
      'No active event. Activate an event under File Maintenance → Events before using registration reports.',
      403
    );
  }

  if (isset($_GET['event_id']) && $_GET['event_id'] !== '') {
    $requested = (int) $_GET['event_id'];
    if ($requested !== $eventId) {
      jerr('Registration reports are limited to the currently active event.', 403);
    }
  }

  return $eventId;
}

/* ---------------------- routes ---------------------- */
try {
  $action = $_GET['action'] ?? '';
  $UNARCH = unarchived_where($conn);

  /* ---- events (active event only) ---- */
  if ($action === 'events') {
    $activeEventId = nomination_report_require_active_event($conn);

    $stmt = $conn->prepare(
      "SELECT e.event_id, e.event_name, e.year
       FROM tbl_events e
       WHERE e.event_id = ? AND $UNARCH
       LIMIT 1"
    );
    if (!$stmt) {
      jerr($DEBUG ? 'Prep failed (events): ' . $conn->error : 'Server error', 500);
    }
    $stmt->bind_param('i', $activeEventId);
    $stmt->execute();
    $rows = stmt_all_assoc($stmt, ['event_id', 'event_name', 'year']);

    echo json_encode([
      'status'           => 'success',
      'rows'             => $rows,
      'default_event_id' => $activeEventId,
      'active_event_id'  => $activeEventId,
    ]);
    exit;
  }

  /* ---- categories (active event only) ---- */
  if ($action === 'categories') {
    $activeEventId = nomination_report_require_active_event($conn);

    $sql = "SELECT c.category_id, c.category_name
            FROM tbl_categories c
            JOIN tbl_events e ON e.event_id = c.event_id
            WHERE $UNARCH AND e.event_id = ?
            ORDER BY c.category_name";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      jerr($DEBUG ? 'Prep failed for categories: ' . $conn->error : 'Server error', 500);
    }
    $stmt->bind_param('i', $activeEventId);
    $stmt->execute();

    echo json_encode([
      'status'          => 'success',
      'rows'            => stmt_all_assoc($stmt, ['category_id', 'category_name']),
      'active_event_id' => $activeEventId,
    ]);
    exit;
  }

  /* ---- awards (active event only) ---- */
  if ($action === 'awards') {
    $activeEventId = nomination_report_require_active_event($conn);
    $category_id = (isset($_GET['category_id']) && $_GET['category_id'] !== '') ? (int) $_GET['category_id'] : null;

    if ($category_id) {
      $sql = "SELECT q.question_id, q.question_name AS award_name
              FROM tbl_questions q
              JOIN tbl_categories c ON c.category_id = q.category_id
              JOIN tbl_events e     ON e.event_id    = c.event_id
              WHERE $UNARCH AND e.event_id = ? AND q.category_id = ?
              ORDER BY q.question_name";
      $types = 'ii';
      $args = [$activeEventId, $category_id];
    } else {
      $sql = "SELECT q.question_id, q.question_name AS award_name
              FROM tbl_questions q
              JOIN tbl_categories c ON c.category_id = q.category_id
              JOIN tbl_events e     ON e.event_id    = c.event_id
              WHERE $UNARCH AND e.event_id = ?
              ORDER BY q.question_name";
      $types = 'i';
      $args = [$activeEventId];
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      jerr($DEBUG ? 'Prep failed for awards: ' . $conn->error : 'Server error', 500);
    }
    $stmt->bind_param($types, ...$args);
    $stmt->execute();

    echo json_encode([
      'status'          => 'success',
      'rows'            => stmt_all_assoc($stmt, ['question_id', 'award_name']),
      'active_event_id' => $activeEventId,
    ]);
    exit;
  }

  /* ---- establishments (report) — 1 row per registration ----
     Filters (all optional):
       - status      = pending|in_review|needs_info|approved|rejected|merged
       - category_id = int
       - question_id = int
     Returns: contact details, categories, awards, and status
  */
  if ($action === 'establishments') {
    $activeEventId = nomination_report_require_active_event($conn);

    $status      = isset($_GET['status']) && $_GET['status'] !== '' ? strtolower(trim((string)$_GET['status'])) : null;
    $category_id = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
    $question_id = isset($_GET['question_id']) && $_GET['question_id'] !== '' ? (int)$_GET['question_id'] : null;

    $allowedStatuses = ['pending','in_review','needs_info','approved','rejected','merged'];
    if ($status && !in_array($status, $allowedStatuses, true)) jerr('Invalid status value', 400);

    $rows = nomination_report_fetch_rows(
      $conn, $activeEventId, $status ?? '', $category_id, $question_id
    );

    echo json_encode([
      'status'          => 'success',
      'rows'            => $rows,
      'active_event_id' => $activeEventId,
    ]);
    exit;
  }

  /* ---- unknown ---- */
  jerr('Unknown action', 404);

} catch (Throwable $e) {
  error_log('[nomination_report] '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=> $DEBUG ? $e->getMessage() : 'Server error']);
}
