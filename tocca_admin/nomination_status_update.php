<?php
// nomination_status_update.php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/comm.php';

date_default_timezone_set('Asia/Manila');

function fail(string $m, int $c=400){ http_response_code($c); echo json_encode(['status'=>'error','message'=>$m], JSON_UNESCAPED_UNICODE); exit; }
function ok(array $p=[]){ echo json_encode(['status'=>'success'] + $p, JSON_UNESCAPED_UNICODE); exit; }

// --- Read input (JSON or form) ---
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

$nomination_id = isset($in['nomination_id']) ? (int)$in['nomination_id'] : 0;
$requested     = isset($in['status']) ? trim((string)$in['status']) : '';       // "Approved" | "Needs Info" | "Rejected"
$missing       = isset($in['missing_fields']) ? trim((string)$in['missing_fields']) : '';
$sendEmail     = array_key_exists('send_email', $in) ? (bool)$in['send_email'] : true;
$customSubject = isset($in['subject']) ? trim((string)$in['subject']) : '';
$customHtml    = isset($in['html'])    ? trim((string)$in['html'])    : '';      // sent by JS

// Map UI -> DB enum & template names
$mapUiToDb = [
  'Approved'   => 'approved',
  'Rejected'   => 'rejected',
  'Needs Info' => 'needs_info',
];
$mapUiToTemplate = [
  'Approved'   => 'nomination_approved',
  'Rejected'   => 'nomination_rejected',
  'Needs Info' => 'nomination_needs_info',
];

if (!$nomination_id || !isset($mapUiToDb[$requested])) {
  fail('Invalid payload');
}

$dbStatus      = $mapUiToDb[$requested];          // lowercase enum
$templateName  = $mapUiToTemplate[$requested];

/* ----------------------------------------------------------------------------
   Active (or latest) event — matches tbl_events (voting_start/voting_end)
---------------------------------------------------------------------------- */
$event = null;
$res = $conn->query("SELECT event_id, voting_start, voting_end FROM tbl_events WHERE is_active=1 ORDER BY event_id DESC LIMIT 1");
if ($res && $res->num_rows) {
  $event = $res->fetch_assoc();
} else {
  $res2 = $conn->query("SELECT event_id, voting_start, voting_end FROM tbl_events ORDER BY year DESC, event_id DESC LIMIT 1");
  if ($res2 && $res2->num_rows) $event = $res2->fetch_assoc();
}

// Guard: block approvals during voting window
$now = time();
if ($requested === 'Approved' && $event && !empty($event['voting_start'])) {
  $start = strtotime($event['voting_start']);
  $end   = !empty($event['voting_end']) ? strtotime($event['voting_end']) : null;
  $votingOngoing = $start && $now >= $start && (!$end || $now <= $end);
  if ($votingOngoing) {
    fail('Voting is already ongoing. You can no longer approve/accept nominations.', 409);
  }
}

/* ----------------------------------------------------------------------------
   Load nomination — matches tbl_nominations
---------------------------------------------------------------------------- */
$stmt = $conn->prepare("
  SELECT nomination_id, event_id, business_name, owner_name, email, status
  FROM tbl_nominations
  WHERE nomination_id = ?
  LIMIT 1
");
$stmt->bind_param('i', $nomination_id);
$stmt->execute();
$nom = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$nom) fail('Nomination not found', 404);

// Recipient fields
$toEmail = trim((string)($nom['email'] ?? ''));
if ($toEmail === '') fail('Nomination has no email address on record.', 422);

$toName  = trim((string)($nom['owner_name'] ?? ''));
if ($toName === '') $toName = trim((string)($nom['business_name'] ?? 'Valued Nominee'));

// Event id to tag the message with
$activeEventId = $event ? (int)$event['event_id'] : (int)($nom['event_id'] ?? 0);

// View link (admin)
$scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http');
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$viewLink = sprintf('%s://%s%s/nomination_profile.php?id=%d', $scheme, $host, $base, $nomination_id);

// Optional voting start text
$votingStartDate = ($event && !empty($event['voting_start']))
  ? date('F j, Y g:i A', strtotime($event['voting_start']))
  : '';

/* ----------------------------------------------------------------------------
   Transaction: update status (+ optional email)
---------------------------------------------------------------------------- */
$conn->begin_transaction();

try {
  // 1) Update status in tbl_nominations
  $u = $conn->prepare("UPDATE tbl_nominations SET status=? WHERE nomination_id=?");
  $u->bind_param('si', $dbStatus, $nomination_id);
  $u->execute();
  $u->close();

  // 2) Optional email
  if ($sendEmail) {
    // Build common data used by server templates
    $templateData = [
      'business_name'   => (string)($nom['business_name'] ?? ''),
      'award_name'      => 'business', // adjust if you later fetch real award/category
      'voting_start'    => $votingStartDate,
      'missing_fields'  => ($requested === 'Needs Info') ? $missing : '',
      'nomination_link' => $viewLink,
      'date'            => date('F j, Y'),
    ];

    // Prefer Quill-composed subject/body if provided (non-empty)
    if ($customSubject !== '' && $customHtml !== '') {
      queue_email($conn, [
        'type'             => 'nomination_status',
        'event_id'         => $activeEventId ?: null,
        'recipient_name'   => $toName,
        'recipient_email'  => $toEmail,
        'subject'          => $customSubject,
        'body_html'        => $customHtml,  // <-- IMPORTANT: use body_html key
      ]);
    } else {
      // Fallback to your server-side template
      queue_email($conn, [
        'type'             => 'nomination_status',
        'event_id'         => $activeEventId ?: null,
        'recipient_name'   => $toName,
        'recipient_email'  => $toEmail,
        'template_name'    => $templateName,
        'data'             => $templateData,
      ]);
    }
  }

  $conn->commit();

  ok();

} catch (Throwable $e) {
  $conn->rollback();
  fail('Server error: '.$e->getMessage(), 500);
}
