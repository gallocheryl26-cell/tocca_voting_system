<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/admin_active_event.php';

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
     Returns: establishment, email, status
  */
  if ($action === 'establishments') {
    $activeEventId = nomination_report_require_active_event($conn);

    $status      = isset($_GET['status']) && $_GET['status'] !== '' ? strtolower(trim((string)$_GET['status'])) : null;
    $category_id = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
    $question_id = isset($_GET['question_id']) && $_GET['question_id'] !== '' ? (int)$_GET['question_id'] : null;

    $allowedStatuses = ['pending','in_review','needs_info','approved','rejected','merged'];
    if ($status && !in_array($status, $allowedStatuses, true)) jerr('Invalid status value', 400);

    // Feature detection
    $hasNomEvent     = column_exists($conn, 'tbl_nominations', 'event_id');
    $hasNomBizCol    = column_exists($conn, 'tbl_nominations', 'business_name');
    $hasNomEmailCol  = column_exists($conn, 'tbl_nominations', 'email');

    $hasEvents       = table_exists($conn, 'tbl_events');
    $hasNq           = table_exists($conn, 'tbl_nomination_questions');
    $hasQuestions    = table_exists($conn, 'tbl_questions');
    $hasCategories   = table_exists($conn, 'tbl_categories');
    $hasFieldsTbl    = table_exists($conn, 'tbl_nomination_fields');
    $hasAnswersTbl   = table_exists($conn, 'tbl_nomination_answers');
    $hasQChoices     = table_exists($conn, 'tbl_question_choices');
    $hasChoices      = table_exists($conn, 'tbl_choices');

    /* ---------- Detect answers table column names (defensive) ---------- */
    $ansTbl = 'tbl_nomination_answers';
    $ansNomCol = column_exists($conn, $ansTbl, 'nomination_id') ? 'nomination_id'
              : (column_exists($conn, $ansTbl, 'nominationId') ? 'nominationId' : null);
    $ansFieldCol = column_exists($conn, $ansTbl, 'field_id') ? 'field_id'
                : (column_exists($conn, $ansTbl, 'fieldId') ? 'fieldId'
                : (column_exists($conn, $ansTbl, 'nomination_field_id') ? 'nomination_field_id' : null));
    $ansValueCol = column_exists($conn, $ansTbl, 'answer') ? 'answer'
                : (column_exists($conn, $ansTbl, 'value') ? 'value'
                : (column_exists($conn, $ansTbl, 'response') ? 'response'
                : (column_exists($conn, $ansTbl, 'text') ? 'text'
                : (column_exists($conn, $ansTbl, 'text_value') ? 'text_value'
                : (column_exists($conn, $ansTbl, 'val') ? 'val' : null)))));

    $answersUsable = $hasAnswersTbl && $ansNomCol && $ansFieldCol && $ansValueCol;

    /* ---------- Lock exact field IDs by name (from your schema) ---------- */
    $bnFieldId = null; // business_name
    $emFieldId = null; // email

    if ($hasFieldsTbl) {
      try {
        $rs = $conn->query("SELECT id FROM tbl_nomination_fields WHERE name='business_name' LIMIT 1");
        if ($rs && ($row = $rs->fetch_assoc())) $bnFieldId = (int)$row['id'];
        $rs = $conn->query("SELECT id FROM tbl_nomination_fields WHERE name='email' LIMIT 1");
        if ($rs && ($row = $rs->fetch_assoc())) $emFieldId = (int)$row['id'];

        // soft fallback by label if needed
        if ($bnFieldId === null) {
          $rs = $conn->query("SELECT id FROM tbl_nomination_fields WHERE LOWER(label) LIKE 'business name%' ORDER BY id LIMIT 1");
          if ($rs && ($row = $rs->fetch_assoc())) $bnFieldId = (int)$row['id'];
        }
        if ($emFieldId === null) {
          $rs = $conn->query("SELECT id FROM tbl_nomination_fields WHERE LOWER(label)='email' ORDER BY id LIMIT 1");
          if ($rs && ($row = $rs->fetch_assoc())) $emFieldId = (int)$row['id'];
        }
      } catch (\Throwable $e) { /* ignore */ }
    }

    // Helper: build ONE-row-per-registration answers join for a single field id
    $makeAnswerJoin = function(string $alias, ?int $fid) use ($answersUsable, $ansTbl, $ansNomCol, $ansFieldCol, $ansValueCol) {
      if (!$answersUsable || !$fid) return "LEFT JOIN (SELECT NULL AS nomination_id, NULL AS answer) $alias ON 1=0";
      return "
        LEFT JOIN (
          SELECT $ansNomCol AS nomination_id, MAX($ansValueCol) AS answer
          FROM $ansTbl
          WHERE $ansFieldCol = $fid
          GROUP BY $ansNomCol
        ) $alias ON $alias.nomination_id = n.nomination_id
      ";
    };

    $parts = [];
    $types = '';
    $args  = [];

    // Base select: 1 row per registration (no fan-out)
    $parts[] = "SELECT
                  COALESCE(".
                    ($hasNomBizCol   ? "n.business_name, " : "").
                    "bn.answer, chm.choice_name
                  ) AS establishment,
                  COALESCE(".
                    ($hasNomEmailCol ? "n.email, " : "").
                    "em.answer, chm.choice_email
                  ) AS email,
                  n.status
                FROM tbl_nominations n";

    if (!$hasNomEvent || !$hasEvents) {
      jerr('Registration reports require event-scoped registrations (event_id column).', 500);
    }

    $parts[] = "JOIN tbl_events e ON e.event_id = n.event_id";
    $canUseUnarch = true;

    // answers joins (single field each)
    $parts[] = $makeAnswerJoin('bn', $bnFieldId); // business_name
    $parts[] = $makeAnswerJoin('em', $emFieldId); // email

    // choices aggregate (ONE row per registration) — fallback only
    if ($hasNq && $hasQuestions && $hasQChoices && $hasChoices) {
      $parts[] = "LEFT JOIN (
                    SELECT
                      nq.nomination_id,
                      MAX(ch.choice_name) AS choice_name,
                      MAX(ch.email)       AS choice_email
                    FROM tbl_nomination_questions nq
                    JOIN tbl_questions q        ON q.question_id  = nq.question_id
                    JOIN tbl_question_choices qc ON qc.question_id = q.question_id
                    JOIN tbl_choices ch         ON ch.choice_id   = qc.choice_id
                    GROUP BY nq.nomination_id
                  ) chm ON chm.nomination_id = n.nomination_id";
    } else {
      $parts[] = "LEFT JOIN (SELECT NULL AS nomination_id, NULL AS choice_name, NULL AS choice_email) chm ON 1=0";
    }

    // WHERE — active event only; use EXISTS for category/award filters (prevents duplication)
    $where = [$UNARCH, 'e.event_id = ?', 'n.event_id = ?'];
    $types = 'ii';
    $args  = [$activeEventId, $activeEventId];

    if ($status) {
      $where[] = 'n.status = ?';
      $types .= 's';
      $args[] = $status;
    }

    if ($category_id && $hasNq && $hasQuestions && $hasCategories) {
      $where[] = "EXISTS (
                    SELECT 1
                    FROM tbl_nomination_questions nq
                    JOIN tbl_questions q  ON q.question_id = nq.question_id
                    JOIN tbl_categories c ON c.category_id = q.category_id
                    WHERE nq.nomination_id = n.nomination_id
                      AND c.category_id    = ?
                      AND c.event_id       = ?
                  )";
      $types .= 'ii';
      $args[] = $category_id;
      $args[] = $activeEventId;
    }

    if ($question_id && $hasNq && $hasQuestions && $hasCategories) {
      $where[] = "EXISTS (
                    SELECT 1
                    FROM tbl_nomination_questions nq2
                    JOIN tbl_questions q2 ON q2.question_id = nq2.question_id
                    JOIN tbl_categories c2 ON c2.category_id = q2.category_id
                    WHERE nq2.nomination_id = n.nomination_id
                      AND nq2.question_id   = ?
                      AND c2.event_id       = ?
                  )";
      $types .= 'ii';
      $args[] = $question_id;
      $args[] = $activeEventId;
    }

    if ($where) $parts[] = "WHERE ".implode(" AND ", $where);

    // Order — null names last
    $parts[] = "ORDER BY establishment IS NULL, establishment ASC";

    $sql = implode("\n", $parts);
    $stmt = $conn->prepare($sql);
    if (!$stmt) jerr($DEBUG ? 'Prep failed (establishments): '.$conn->error."\nSQL:\n".$sql : 'Server error', 500);

    if ($types) {
      $bind = array_merge([$types], $args);
      $refs = [];
      foreach ($bind as $k => &$v) { $refs[$k] = &$v; }
      call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
      $rows[] = [
        'establishment' => $r['establishment'] ?? null,
        'email'         => $r['email'] ?? null,
        'status'        => $r['status'] ?? null,
      ];
    }

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
