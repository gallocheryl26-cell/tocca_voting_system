<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/require_admin_api.php';
function norm_type(?string $t): string {
  $t = strtolower(trim($t ?? ''));
  $map = [
    'categories'         => 'category',
    'category'           => 'category',
    'questions'          => 'question',
    'question'           => 'question',
    'choices'            => 'choice',
    'choice'             => 'choice',
    'establishments'     => 'choice',
    'event_schedule'     => 'event_schedule',
    'events'             => 'event',
    'event'              => 'event',
    'nomination_fields'  => 'nomination_field',
    'nomination_field'   => 'nomination_field',
    'nomination_texts'   => 'nomination_text',
    'nomination_text'    => 'nomination_text',
    'nomination_qr'      => 'event',
    'establishment_qr'   => 'choice',
    'registrations'      => 'nomination',
    'nomination'         => 'nomination',
    'establishment_types'=> 'establishment_type',
    'archives'           => 'event',
    'auth'               => 'user',
    'admin_users'        => 'user',
    'communications'     => 'choice',
    'twg_evaluation'     => 'choice',
    'import'             => 'event',
    'admin_settings'     => 'config',
    'nomination_settings'=> 'config',
    'public_url'         => 'config',
    'admin_profile'      => 'user',
  ];
  return $map[$t] ?? $t;
}

/**
 * Try to read a human label from details JSON, preferring "new", then "old", then flat body.
 */
function extract_label(string $entityType, ?array $details): ?string {
  if (!$details) return null;
  $etype = norm_type($entityType);

  // prefer "new", then "old", then flat
  $candidates = [];
  if (isset($details['new']) && is_array($details['new'])) $candidates[] = $details['new'];
  if (isset($details['old']) && is_array($details['old'])) $candidates[] = $details['old'];
  $candidates[] = $details; // flat

  $fieldsByType = [
    'category'         => ['category_name','name','title','label'],
    'question'         => ['question_name','question','question_text','name','title','label'],
    'choice'           => ['choice_name','business_name','name','title','label'],
    'event_schedule'   => ['event_name','name','title','label'],
    'event'            => ['event_name','name','title','label'],
    'nomination_field' => ['label','name','title'],
    'nomination_text'  => ['title','label','name'],
    'nomination'       => ['business_name','choice_name','name','title','label'],
    'establishment_type' => ['type_name','name','title','label'],
    'user'             => ['username','admin_name','name'],
    'config'           => ['name','title','label'],
    'choice'           => ['choice_name','name','title','label'],
    'event'            => ['event_name','name','title','label'],
    '_default'         => ['name','title','label'],
  ];
  $fields = $fieldsByType[$etype] ?? $fieldsByType['_default'];

  foreach ($candidates as $row) {
    if (!is_array($row)) continue;
    if (!empty($row['choice_name'])) {
      return (string) $row['choice_name'];
    }
    if (!empty($row['business_name'])) {
      return (string) $row['business_name'];
    }
    if (!empty($row['type_name'])) {
      return (string) $row['type_name'];
    }
    if (!empty($row['username'])) {
      return (string) $row['username'];
    }
    if (!empty($row['event_name'])) {
      return (string) $row['event_name'];
    }
    foreach ($fields as $k) {
      if (isset($row[$k]) && $row[$k] !== '') return (string)$row[$k];
    }
  }
  return null;
}

/**
 * DB fallback lookups when details JSON didn’t carry a human label.
 */
function db_fallback_label(mysqli $conn, string $entityType, $entityId): ?string {
  $etype = norm_type($entityType);
  $id = (int)$entityId;
  if ($id <= 0) return null;

  try {
    if ($etype === 'question') {
      $st = $conn->prepare("SELECT question_name FROM tbl_questions WHERE question_id=?");
      if ($st) { $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r['question_name'] ?? null; }
    } elseif ($etype === 'category') {
      $st = $conn->prepare("SELECT category_name FROM tbl_categories WHERE category_id=?");
      if ($st) { $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r['category_name'] ?? null; }
    } elseif ($etype === 'choice') {
      $st = $conn->prepare("SELECT choice_name FROM tbl_choices WHERE choice_id=?");
      if ($st) { $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r['choice_name'] ?? null; }
    } elseif ($etype === 'event') {
      $st = $conn->prepare("SELECT event_name FROM tbl_events WHERE event_id=?");
      if ($st) { $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r['event_name'] ?? null; }
    } elseif ($etype === 'nomination_field') {
      // Prefer label; fall back to name
      $st = $conn->prepare("SELECT COALESCE(NULLIF(label,''), name) AS label FROM tbl_nomination_fields WHERE id=?");
      if ($st) { $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r['label'] ?? null; }
    } elseif ($etype === 'nomination') {
      require_once __DIR__ . '/includes/admin_schema.php';
      if (admin_schema_column_exists($conn, 'tbl_nominations', 'business_name')) {
        $st = $conn->prepare("SELECT business_name FROM tbl_nominations WHERE nomination_id=?");
        if ($st) {
          $st->bind_param('i', $id);
          $st->execute();
          $r = $st->get_result()->fetch_assoc();
          $st->close();
          $name = trim((string) ($r['business_name'] ?? ''));
          if ($name !== '') {
            return $name;
          }
        }
      }
      // Most installs store the business name in registration answers, not a column.
      $st = $conn->prepare(
        "SELECT a.answer
         FROM tbl_nomination_answers a
         INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
         WHERE a.nomination_id = ?
           AND f.name IN ('official_business_name','business_name','company_name','company','business')
           AND TRIM(COALESCE(a.answer, '')) <> ''
         ORDER BY FIELD(f.name, 'official_business_name','business_name','company_name','company','business')
         LIMIT 1"
      );
      if ($st) {
        $st->bind_param('i', $id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        $name = trim((string) ($r['answer'] ?? ''));
        if ($name !== '') {
          return $name;
        }
      }
      return $id > 0 ? ('Registration #' . $id) : null;
    } elseif ($etype === 'establishment_type') {
      $st = $conn->prepare("SELECT type_name FROM tbl_establishment_types WHERE type_id=?");
      if ($st) { $st->bind_param('i',$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r['type_name'] ?? null; }
    }
  } catch (Throwable $e) {
    error_log('db_fallback_label failed for ' . $etype . '#' . $id . ': ' . $e->getMessage());
  }
  return null;
}

/** Event name helper with a small cache. */
function make_event_name_getter(mysqli $conn) {
  $cache = [];
  return function(int $eventId) use (&$cache, $conn): ?string {
    if ($eventId <= 0) return null;
    if (isset($cache[$eventId])) return $cache[$eventId];
    $st = $conn->prepare("SELECT event_name FROM tbl_events WHERE event_id=?");
    if (!$st) return null;
    $st->bind_param('i', $eventId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $cache[$eventId] = ($r['event_name'] ?? null);
  };
}

try {
  $draw   = (int)($_GET['draw']   ?? 1);
  $start  = (int)($_GET['start']  ?? 0);
  $length = (int)($_GET['length'] ?? 25);

  // Filters
  $qModule  = trim((string)($_GET['module'] ?? ''));
  $qAction  = trim((string)($_GET['action'] ?? ''));
  $qAdminId = trim((string)($_GET['admin_id'] ?? ''));
  $qFrom    = trim((string)($_GET['from'] ?? ''));
  $qTo      = trim((string)($_GET['to']   ?? ''));

  $where = [];
  $params = [];
  $types  = '';

  if ($qModule !== '') { $where[] = "module=?";        $params[]=$qModule;         $types.='s'; }
  if ($qAction === 'export') {
    $where[] = "action IN ('export', 'download')";
  } elseif ($qAction !== '') {
    $where[] = "action=?";
    $params[] = $qAction;
    $types .= 's';
  }
  if ($qAdminId !== ''){ $where[] = "admin_id=?";      $params[]=(int)$qAdminId;   $types.='i'; }
  if ($qFrom !== '')   { $where[] = "event_time >= ?"; $params[]=$qFrom.' 00:00:00';$types.='s'; }
  if ($qTo !== '')     { $where[] = "event_time <= ?"; $params[]=$qTo.' 23:59:59'; $types.='s'; }

  $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  // total count
  $totalRes = $conn->query("SELECT COUNT(*) AS c FROM tbl_admin_audit_log");
  $recordsTotal = (int)($totalRes->fetch_assoc()['c'] ?? 0);

  // filtered count
  $sqlCount = "SELECT COUNT(*) AS c FROM tbl_admin_audit_log $whereSql";
  $stmt = $conn->prepare($sqlCount);
  if (!$stmt) throw new RuntimeException('Prepare count failed: '.$conn->error);
  if ($types !== '') { $stmt->bind_param($types, ...$params); }
  $stmt->execute();
  $recordsFiltered = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
  $stmt->close();

  // data rows
  $sql = "SELECT log_id, event_time, admin_id, admin_name, module, entity_type, entity_id, action, details_json
          FROM tbl_admin_audit_log
          $whereSql
          ORDER BY event_time DESC, log_id DESC
          LIMIT ?, ?";
  $typesData  = $types . 'ii';
  $paramsData = $params;
  $paramsData[] = $start;
  $paramsData[] = $length;

  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new RuntimeException('Prepare data failed: '.$conn->error);
  if ($typesData !== '') { $stmt->bind_param($typesData, ...$paramsData); }
  else                   { $stmt->bind_param('ii', $start, $length); }
  $stmt->execute();
  $res = $stmt->get_result();

  $getEventName = make_event_name_getter($conn);

  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $details = json_decode($r['details_json'] ?? 'null', true);
    $etype   = norm_type((string)$r['entity_type']);

    // Event extraction from details
    $eventId = 0;
    if (is_array($details)) {
      if (isset($details['new']['event_id']))      $eventId = (int)$details['new']['event_id'];
      elseif (isset($details['old']['event_id']))  $eventId = (int)$details['old']['event_id'];
      elseif (isset($details['event_id']))         $eventId = (int)$details['event_id'];
    }
    $eventName = $eventId ? $getEventName($eventId) : null;

    // Entity label extraction
    $entityLabel = extract_label($etype, is_array($details) ? $details : null);

    // DB fallback by entity_id if still empty
    if (!$entityLabel && !empty($r['entity_id'])) {
      $entityLabel = db_fallback_label($conn, $etype, $r['entity_id']);
    }

    if (($etype === 'twg_scoresheet' || (string)$r['module'] === 'twg_evaluation') && !$entityLabel && is_array($details)) {
      $choiceId = (int)($details['choice_id'] ?? 0);
      $questionId = (int)($details['question_id'] ?? 0);
      if ($choiceId > 0) {
        $entityLabel = db_fallback_label($conn, 'choice', $choiceId);
      } elseif ($questionId > 0) {
        $entityLabel = db_fallback_label($conn, 'question', $questionId);
      } elseif ($eventName) {
        $entityLabel = $eventName;
      } else {
        $entityLabel = 'TWG scoresheet';
      }
    }

    // Schedule fallback: infer which window changed
    if ($etype === 'event_schedule' && !$entityLabel && is_array($details)) {
      $changedKeys = [];
      if (isset($details['diff']['changed']) && is_array($details['diff']['changed'])) {
        $changedKeys = array_keys($details['diff']['changed']);
      }
      if (count($changedKeys) === 1) {
        $k = strtolower((string)$changedKeys[0]);
        $map = [
          'nomination_start' => 'Registration start',
          'nomination_end'   => 'Registration end',
          'voting_start'     => 'Voting start',
          'voting_end'       => 'Voting end',
        ];
        if (isset($map[$k])) $entityLabel = $map[$k];
      }
      if (!$entityLabel) $entityLabel = 'Schedule';
    }

    // Registration text fallback: instructions / introduction
    if ($etype === 'nomination_text' && !$entityLabel && is_array($details)) {
      // look for section on root, or inside new/old
      $section = $details['section'] ?? ($details['new']['section'] ?? ($details['old']['section'] ?? null));
      if (is_string($section)) {
        $s = strtolower(trim($section));
        if ($s === 'instructions') {
          $entityLabel = 'Registration Form Instruction';
        } elseif ($s === 'intro' || $s === 'introduction') {
          $entityLabel = 'Registration Form Introduction';
        }
      }
      if (!$entityLabel) $entityLabel = 'Registration Form Copy';
    }

    $rows[] = [
      'log_id'       => (int)$r['log_id'],
      'event_time'   => $r['event_time'],
      'admin_id'     => $r['admin_id'],
      'admin_name'   => $r['admin_name'],
      'module'       => $r['module'],
      'entity_type'  => $etype,
      'entity_id'    => $r['entity_id'],
      'entity_label' => $entityLabel,
      'action'       => $r['action'],
      'event'        => [ 'id' => $eventId, 'name' => $eventName ],
      'details'      => $details
    ];
  }
  $stmt->close();

  echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data'            => $rows
  ]);
} catch (Throwable $e) {
  error_log('admin_audit_logs failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode([
    'draw'            => (int) ($_GET['draw'] ?? 0),
    'recordsTotal'    => 0,
    'recordsFiltered' => 0,
    'data'            => [],
    'error'           => true,
    'message'         => 'Audit log fetch failed.',
  ]);
}
