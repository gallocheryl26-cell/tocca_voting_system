<?php
require_once __DIR__ . '/require_admin_api.php';
date_default_timezone_set('Asia/Manila');

/** Helpers **/
function json_fail($msg, $code = 400) {
  http_response_code($code);
  echo json_encode(['status' => 'error', 'message' => $msg]);
  exit;
}
function fetch_active_or_latest_event(mysqli $conn) {
  $res = $conn->query(
    "SELECT * FROM tbl_events
     WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0
     ORDER BY year DESC, event_id DESC
     LIMIT 1"
  );
  return ($res && $res->num_rows) ? $res->fetch_assoc() : null;
}
function fetch_event_by_id(mysqli $conn, $event_id) {
  $stmt = $conn->prepare("SELECT * FROM tbl_events WHERE event_id = ?");
  $stmt->bind_param('i', $event_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res && $res->num_rows ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row;
}
function format_local($dt) {
  // Format for <input type="datetime-local">
  return $dt ? date('Y-m-d\TH:i', strtotime($dt)) : '';
}
function get_phase($e) {
  $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
  $ns = !empty($e['nomination_start']) ? new DateTime($e['nomination_start']) : null;
  $ne = !empty($e['nomination_end'])   ? new DateTime($e['nomination_end'])   : null;
  $vs = !empty($e['voting_start'])     ? new DateTime($e['voting_start'])     : null;
  $ve = !empty($e['voting_end'])       ? new DateTime($e['voting_end'])       : null;

  // Voting window takes precedence if present
  if ($vs && $ve) {
    if ($now < $vs) {
      // Before voting; if registrations are open now, show that; else "between"
      if ($ns && $ne && $now >= $ns && $now <= $ne) return 'nominations_open';
      return 'between';
    }
    if ($now <= $ve) return 'voting_open';
    return 'voting_closed';
  }

  // Only registration window present
  if ($ns && $ne) {
    if ($now < $ns)  return 'unscheduled';      // registrations not started yet
    if ($now <= $ne) return 'nominations_open'; // currently accepting registrations
    return 'between';                           // registrations ended; voting not scheduled yet
  }

  // No windows configured
  return 'unscheduled';
}

/** Resolve event **/
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$event = $event_id ? fetch_event_by_id($conn, $event_id) : fetch_active_or_latest_event($conn);
if ($event && (int)($event['is_archived'] ?? 0) === 1) {
  $event = null;
}
if (!$event) json_fail('No active event. Activate an event to view the dashboard schedule.', 503);

/** /?check_status: quick status for dashboards **/
if (isset($_GET['check_status'])) {
  // Prevent caching so dashboards always get fresh data
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $phase = get_phase($event);
  $voting_allowed = ($phase === 'voting_open') ? 1 : 0;

  echo json_encode([
    'status'           => 'success',
    'event_id'         => (int)$event['event_id'],
    'event_name'       => $event['event_name'],
    'year'             => isset($event['year']) ? (int)$event['year'] : null,
    'phase'            => $phase, // 'nominations_open' | 'between' | 'voting_open' | 'voting_closed' | 'unscheduled'
    'voting_allowed'   => $voting_allowed,
    'nomination_start' => $event['nomination_start'],
    'nomination_end'   => $event['nomination_end'],
    'voting_start'     => $event['voting_start'],
    'voting_end'       => $event['voting_end'],
    'message'          => $voting_allowed ? 'Voting is open.' : 'Voting is currently closed.'
  ]);
  exit;
}

/** GET: fetch schedule for the event (form load) **/
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  echo json_encode([
    'status'           => 'success',
    'event_id'         => (int)$event['event_id'],
    'event_name'       => $event['event_name'],
    'year'             => isset($event['year']) ? (int)$event['year'] : null,
    'is_active'        => (int)$event['is_active'],
    'nomination_start' => format_local($event['nomination_start']),
    'nomination_end'   => format_local($event['nomination_end']),
    'voting_start'     => format_local($event['voting_start']),
    'voting_end'       => format_local($event['voting_end']),
  ]);
  exit;
}

/** POST/JSON: update schedule for the event **/
$raw = file_get_contents('php://input');
if ($raw === false) json_fail('No input.');
$in = json_decode($raw, true);
if (!is_array($in)) json_fail('Invalid JSON.');

// Legacy path support: {action:'update_schedule', start_datetime, end_datetime}
$action = $in['action'] ?? null;
if ($action === 'update_schedule' && isset($in['start_datetime']) && isset($in['end_datetime'])) {
  $vsDT = date('Y-m-d H:i:s', strtotime($in['start_datetime']));
  $veDT = date('Y-m-d H:i:s', strtotime($in['end_datetime']));
  if (strtotime($vsDT) >= strtotime($veDT)) json_fail('Voting end must be after voting start.');
  $eid = (int)($in['event_id'] ?? $event['event_id']);
  $stmt = $conn->prepare("UPDATE tbl_events SET voting_start=?, voting_end=? WHERE event_id=?");
  $stmt->bind_param('ssi', $vsDT, $veDT, $eid);
  if (!$stmt->execute()) json_fail('Database update failed.');
  $stmt->close();
  echo json_encode(['status' => 'success', 'message' => 'Voting schedule updated for event. (Legacy API)']);
  exit;
}

// ----- Flexible path: windows are pair-optional -----
$eid = (int)($in['event_id'] ?? 0);
$ns = isset($in['nomination_start']) ? trim((string)$in['nomination_start']) : '';
$ne = isset($in['nomination_end'])   ? trim((string)$in['nomination_end'])   : '';
$vs = isset($in['voting_start'])     ? trim((string)$in['voting_start'])     : '';
$ve = isset($in['voting_end'])       ? trim((string)$in['voting_end'])       : '';

if (!$eid) json_fail('event_id is required.');

// Pair integrity (either both set or both blank)
$hasNomination = ($ns !== '' || $ne !== '');
$hasVoting     = ($vs !== '' || $ve !== '');

if (($ns !== '' && $ne === '') || ($ns === '' && $ne !== '')) {
  json_fail('Provide both registration start and end, or leave both blank.');
}
if (($vs !== '' && $ve === '') || ($vs === '' && $ve !== '')) {
  json_fail('Provide both voting start and end, or leave both blank.');
}

// Validate event exists
$exists = fetch_event_by_id($conn, $eid);
if (!$exists) json_fail('Event not found.');

// Normalize to DATETIME (or NULL if window absent)
$nsDT = $hasNomination ? date('Y-m-d H:i:s', strtotime($ns)) : null;
$neDT = $hasNomination ? date('Y-m-d H:i:s', strtotime($ne)) : null;
$vsDT = $hasVoting     ? date('Y-m-d H:i:s', strtotime($vs)) : null;
$veDT = $hasVoting     ? date('Y-m-d H:i:s', strtotime($ve)) : null;

// Ordering checks for present windows
if ($hasNomination) {
  $existingNs = $exists['nomination_start'] ?? null;
  $existingNorm = $existingNs ? date('Y-m-d H:i:s', strtotime((string)$existingNs)) : null;
  $newNorm = date('Y-m-d H:i:s', strtotime($nsDT));
  $unchangedNomStart = ($existingNorm !== null && $existingNorm === $newNorm);

  if (!$unchangedNomStart) {
    $tz = new DateTimeZone('Asia/Manila');
    $todayStart = new DateTime('today', $tz);
    $dns = new DateTime($nsDT, $tz);
    if ($dns < $todayStart) {
      json_fail('Registration start must be today or a future date.');
    }
  }
}

if ($hasNomination && strtotime($nsDT) >= strtotime($neDT)) {
  json_fail('Registration start must be before registration end.');
}
if ($hasVoting && strtotime($vsDT) >= strtotime($veDT)) {
  json_fail('Voting end must be after voting start.');
}
// Cross-window only if both windows exist
if ($hasNomination && $hasVoting && strtotime($neDT) > strtotime($vsDT)) {
  json_fail('Voting start must be after or equal to registration end.');
}

// Update (NULLs allowed)
$upd = $conn->prepare("
  UPDATE tbl_events
     SET nomination_start = ?, nomination_end = ?, voting_start = ?, voting_end = ?
   WHERE event_id = ?");
$upd->bind_param('ssssi', $nsDT, $neDT, $vsDT, $veDT, $eid);
if (!$upd->execute()) json_fail('Database update failed.');
$upd->close();

echo json_encode(['status' => 'success', 'message' => 'Schedule saved.']);
