<?php
declare(strict_types=1);
require_once __DIR__ . '/require_admin_api.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/includes/establishment_type_event_helpers.php';

const TBL_TYPES          = ET_TBL_TYPES;
const TBL_TYPES_X_AWARDS = ET_TBL_TYPES_X_AWARDS;
const TBL_EVENTS         = ET_TBL_EVENTS;
const TBL_CATEGORIES     = ET_TBL_CATEGORIES;
const TBL_QUESTIONS      = ET_TBL_QUESTIONS;

function jerr(string $m, int $code = 400, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['status' => 'error', 'featureEnabled' => true, 'message' => $m] + $extra);
  exit;
}
function jok(array $d = []): void {
  echo json_encode(['status' => 'success', 'featureEnabled' => true] + $d);
  exit;
}
function read_json_body(): array {
  $raw = file_get_contents('php://input') ?: '';
  $ctype = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
  if ($raw !== '' && stripos((string)$ctype, 'application/json') !== false) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) return $tmp;
  }
  return [];
}
function get_action(array $body): string {
  if (isset($body['action']))      return (string)$body['action'];
  if (isset($_POST['action']))     return (string)$_POST['action'];
  if (isset($_GET['action']))      return (string)$_GET['action'];
  return '';
}
function ints(array $a): array {
  return et_ints($a);
}
function get_active_event_id(mysqli $conn): ?int {
  return et_get_active_event_id($conn);
}
function awards_belong_to_event(mysqli $conn, array $questionIds, int $eventId): bool {
  return et_awards_belong_to_event($conn, $questionIds, $eventId);
}
function choices_have_establishment_type(mysqli $conn): bool {
  return et_choices_have_establishment_type($conn);
}
function type_ids_for_event(mysqli $conn, int $eventId): array {
  return et_type_ids_for_event($conn, $eventId);
}
function type_belongs_to_event(mysqli $conn, int $typeId, ?int $eventId): bool {
  return et_type_belongs_to_event($conn, $typeId, $eventId);
}
function fetch_types_for_event(mysqli $conn, ?int $eventId): array {
  return et_fetch_types_for_event($conn, $eventId);
}
function fetch_awards_for_type(mysqli $conn, int $typeId, ?int $eventId): array {
  return et_fetch_awards_for_type($conn, $typeId, $eventId);
}
function get_type_with_awards(mysqli $conn, int $typeId, ?int $eventId = null): ?array {
  if ($eventId === null) $eventId = get_active_event_id($conn);
  $stmt = $conn->prepare("SELECT type_id, type_name, COALESCE(status,1) AS status FROM ".TBL_TYPES." WHERE type_id = ?");
  $stmt->bind_param('i', $typeId);
  $stmt->execute();
  $res = $stmt->get_result();
  $type = $res->fetch_assoc();
  $stmt->close();
  if (!$type) return null;

  return [
    'type_id'   => (int)$type['type_id'],
    'type_name' => $type['type_name'],
    'status'    => (int)$type['status'],
    'awards'    => fetch_awards_for_type($conn, $typeId, $eventId),
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  echo json_encode(['status'=>'ok','featureEnabled'=>true]); exit;
}
$body   = read_json_body();
$action = get_action($body);

if ($action === 'awards') {
  try {
    $eventId = get_active_event_id($conn);
    $groupsMap = [];
    if ($eventId) {
      $stmt = $conn->prepare("
        SELECT c.category_id, c.category_name, q.question_id, q.question_name
        FROM ".TBL_CATEGORIES." c
        JOIN ".TBL_QUESTIONS."  q ON q.category_id = c.category_id
        WHERE c.event_id = ?
        ORDER BY c.category_name ASC, q.question_name ASC
      ");
      $stmt->bind_param('i', $eventId);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) {
        $cid = (int)$r['category_id'];
        if (!isset($groupsMap[$cid])) {
          $groupsMap[$cid] = [
            'category_id'   => $cid,
            'category_name' => $r['category_name'] ?? 'Uncategorized',
            'awards'        => [],
          ];
        }
        $groupsMap[$cid]['awards'][] = [
          'question_id'   => (int)$r['question_id'],
          'question_name' => $r['question_name'] ?? 'Untitled Award',
          'category_name' => $r['category_name'] ?? null,
        ];
      }
      $stmt->close();
    }

    jok(['data' => ['groups' => array_values($groupsMap)]]);
  } catch (Throwable $e) {
    jerr('Unable to load awards list: ' . $e->getMessage(), 500);
  }
}

if ($action === 'list') {
  try {
    $eventId = get_active_event_id($conn);
    if ($eventId === null) {
      jok(['data' => [], 'no_active_event' => true]);
    }
    jok(['data' => fetch_types_for_event($conn, $eventId), 'no_active_event' => false]);
  } catch (Throwable $e) {
    jerr('Unable to load establishment types: ' . $e->getMessage(), 500);
  }
}

if ($action === 'get') {
  try {
    $eventId = get_active_event_id($conn);
    if ($eventId === null) jerr('No active event. Activate an event before managing establishment types.', 400);
    $typeId = isset($body['type_id']) ? (int)$body['type_id'] : (isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0);
    if ($typeId <= 0) jerr('Missing or invalid type_id.');
    if (!type_belongs_to_event($conn, $typeId, $eventId)) jerr('Establishment type not found for the active event.', 404);
    $type = get_type_with_awards($conn, $typeId, $eventId);
    if (!$type) jerr('Establishment type not found.', 404);
    jok(['data' => $type]);
  } catch (Throwable $e) {
    jerr('Unable to fetch establishment type: ' . $e->getMessage(), 500);
  }
}

if ($action === 'create') {
  try {
    $eventId = get_active_event_id($conn);
    if ($eventId === null) jerr('No active event. Activate an event before managing establishment types.', 400);
    $typeName = trim((string)($body['type_name'] ?? ''));
    $awards   = isset($body['awards']) ? ints((array)$body['awards']) : [];
    if ($typeName === '') jerr('Type name is required.');
    if (count($awards) === 0) jerr('Please select at least one award.');
    if (!awards_belong_to_event($conn, $awards, $eventId)) {
      jerr('One or more selected awards do not belong to the active event.');
    }
    $stmt = $conn->prepare("SELECT COUNT(*) FROM ".TBL_TYPES." WHERE LOWER(type_name) = LOWER(?)");
    $stmt->bind_param('s', $typeName);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    if ((int)$cnt > 0) {
      echo json_encode(['status' => 'duplicate', 'featureEnabled' => true, 'message' => 'This establishment type already exists.']);
      exit;
    }

    $stmt = $conn->prepare("INSERT INTO ".TBL_TYPES." (type_name, status, created_at) VALUES (?, 1, NOW())");
    $stmt->bind_param('s', $typeName);
    $stmt->execute();
    $typeId = (int)$stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO ".TBL_TYPES_X_AWARDS." (type_id, question_id) VALUES (?, ?)");
    foreach ($awards as $qid) {
      $stmt->bind_param('ii', $typeId, $qid);
      $stmt->execute();
    }
    $stmt->close();

    jok(['message' => 'Establishment type created.', 'data' => ['type_id' => $typeId]]);
  } catch (Throwable $e) {
    jerr('Failed to create establishment type: ' . $e->getMessage(), 500);
  }
}

if ($action === 'update') {
  try {
    $eventId = get_active_event_id($conn);
    if ($eventId === null) jerr('No active event. Activate an event before managing establishment types.', 400);
    $typeId   = isset($body['type_id']) ? (int)$body['type_id'] : 0;
    $typeName = isset($body['type_name']) ? trim((string)$body['type_name']) : null; // nullable (no change if null)
    $status   = isset($body['status']) ? (int)$body['status'] : null;               // 0/1 or null (no change)
    $awards   = array_key_exists('awards', $body) ? ints((array)$body['awards']) : null; // null => don't touch; array => replace
    if ($typeId <= 0) jerr('Missing or invalid type_id.');
    if (!type_belongs_to_event($conn, $typeId, $eventId)) jerr('Establishment type not found for the active event.', 404);

    $stmt = $conn->prepare("SELECT type_id FROM ".TBL_TYPES." WHERE type_id = ?");
    $stmt->bind_param('i', $typeId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res->fetch_row()) { $stmt->close(); jerr('Establishment type not found.', 404); }
    $stmt->close();

    if ($typeName !== null && $typeName !== '') {
      $stmt = $conn->prepare("SELECT COUNT(*) FROM ".TBL_TYPES." WHERE LOWER(type_name) = LOWER(?) AND type_id <> ?");
      $stmt->bind_param('si', $typeName, $typeId);
      $stmt->execute();
      $stmt->bind_result($cnt);
      $stmt->fetch();
      $stmt->close();
      if ((int)$cnt > 0) jerr('Another establishment type already uses that name.');
    }
    if ($awards !== null && count($awards) === 0) jerr('Please select at least one award.');
    if ($awards !== null && !awards_belong_to_event($conn, $awards, $eventId)) {
      jerr('One or more selected awards do not belong to the active event.');
    }
    $conn->begin_transaction();
    if ($typeName !== null) {
      $stmt = $conn->prepare("UPDATE ".TBL_TYPES." SET type_name = ? WHERE type_id = ?");
      $stmt->bind_param('si', $typeName, $typeId);
      $stmt->execute();
      $stmt->close();
    }
    if ($status !== null) {
      $status = $status ? 1 : 0;
      $stmt = $conn->prepare("UPDATE ".TBL_TYPES." SET status = ? WHERE type_id = ?");
      $stmt->bind_param('ii', $status, $typeId);
      $stmt->execute();
      $stmt->close();
    }

    if ($awards !== null) {
      $stmt = $conn->prepare("DELETE FROM ".TBL_TYPES_X_AWARDS." WHERE type_id = ?");
      $stmt->bind_param('i', $typeId);
      $stmt->execute();
      $stmt->close();
      $stmt = $conn->prepare("INSERT INTO ".TBL_TYPES_X_AWARDS." (type_id, question_id) VALUES (?, ?)");
      foreach ($awards as $qid) {
        $stmt->bind_param('ii', $typeId, $qid);
        $stmt->execute();
      }
      $stmt->close();
    }
    $conn->commit();
    $updated = get_type_with_awards($conn, $typeId);
    jok(['message' => 'Establishment type updated.', 'data' => $updated]);
  } catch (Throwable $e) {
    if ($conn->errno) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
    jerr('Failed to update establishment type: ' . $e->getMessage(), 500);
  }
}

if ($action === 'delete') {
  try {
    $eventId = get_active_event_id($conn);
    if ($eventId === null) jerr('No active event. Activate an event before managing establishment types.', 400);
    $typeId = isset($body['type_id']) ? (int)$body['type_id'] : 0;
    if ($typeId <= 0) jerr('Missing or invalid type_id.');
    if (!type_belongs_to_event($conn, $typeId, $eventId)) jerr('Establishment type not found for the active event.', 404);
    $stmt = $conn->prepare("SELECT COUNT(*) FROM ".TBL_TYPES." WHERE type_id = ?");
    $stmt->bind_param('i', $typeId);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    if ((int)$cnt === 0) jerr('Establishment type not found.', 404);
    $stmt = $conn->prepare("DELETE FROM ".TBL_TYPES." WHERE type_id = ?");
    $stmt->bind_param('i', $typeId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected <= 0) jerr('Nothing was deleted.');
    jok(['message' => 'Establishment type deleted.', 'data' => ['type_id' => $typeId]]);
  } catch (Throwable $e) {
    jerr('Failed to delete establishment type: ' . $e->getMessage(), 500);
  }
}

jerr('Unknown action.', 400, ['hint' => 'Use actions: awards | list | get | create | update | delete']);
