<?php
// tocca_admin/cron_nom_deadline.php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/db_connection.php';

// ---- CONFIG ----
$ADMIN_EMAILS = ['admin@example.com']; // put your actual recipients
$PENDING_STATES = ['Submitted'];
$THRESHOLDS = [7,3,1,0];

$evt = $conn->query("SELECT id, event_name, nomination_end
                     FROM tbl_events WHERE is_active=1 ORDER BY id DESC LIMIT 1");
if (!$evt || $evt->num_rows === 0) exit(0);
$event = $evt->fetch_assoc();

$now = new DateTime('now', new DateTimeZone('Asia/Manila'));
$end = new DateTime($event['nomination_end'], new DateTimeZone('Asia/Manila'));
$daysLeft = (int)ceil(($end->getTimestamp() - $now->getTimestamp())/86400);

$st = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_nominations WHERE event_id=? AND status IN (".
  implode(',', array_fill(0, count($PENDING_STATES), '?')).")");
$types = 'i' . str_repeat('s', count($PENDING_STATES));
$args  = array_merge([$types, $event['id']], $PENDING_STATES);
$ref   = [];
foreach($args as $k => $v){ $ref[$k] = &$args[$k]; }
$refFn = new ReflectionFunction('mysqli_stmt::bind_param');
$st->bind_param(...$ref);
$st->execute();
$pending = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); 

// Send when threshold day OR pending>0 and <=3 days left
$shouldSend = in_array($daysLeft, $THRESHOLDS, true) || ($pending>0 && $daysLeft<=3);
if (!$shouldSend) exit(0);

$subject = "[TOCCA] Nomination reminder — {$event['event_name']}";
$daysTxt = $daysLeft===0 ? 'today' : ($daysLeft<0 ? 'ended' : "in {$daysLeft} day".($daysLeft===1?'':'s'));
$body = <<<HTML
<p>Hello Admin,</p>
<p>The nomination period for <b>{$event['event_name']}</b> ends <b>{$daysTxt}</b> (ends on <b>{$end->format('M j, Y g:i A')}</b>).</p>
<p>Nominations to review (<i>{$PENDING_STATES[0]}</i> only): <b>{$pending}</b>.</p>
<p>Please log in to the admin panel to continue the review.</p>
<p>— Automated Reminder</p>
HTML;

foreach ($ADMIN_EMAILS as $rcpt) {
  // If you already have PHPMailer set up, call it here.
  // For a quick fallback (if mail() works on your host):
  @mail($rcpt, $subject, strip_tags($body), "Content-Type: text/html; charset=UTF-8\r\n");
}
