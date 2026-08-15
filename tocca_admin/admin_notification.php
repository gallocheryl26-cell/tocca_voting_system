<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/notification_config.php';

function ok(array $data){ echo json_encode(['status'=>'success','data'=>$data]); exit; }
function err(string $m, int $c=500){ http_response_code($c); echo json_encode(['status'=>'error','message'=>$m]); exit; }

function admin_notif_normalize_thresholds(array $hours): array
{
  $normalized = [];
  foreach ($hours as $hour) {
    if ($hour === null || $hour === '') {
      continue;
    }
    $normalized[] = (int)round((float)$hour);
  }

  if (empty($normalized)) {
    return [];
  }

  $normalized = array_values(array_unique($normalized));
  sort($normalized, SORT_NUMERIC);

  return $normalized;
}

function admin_notif_ensure_milestones(array $hours, array $required): array
{
  $normalized = admin_notif_normalize_thresholds($hours);

  foreach ($required as $hour) {
    $value = (int)round((float)$hour);
    if (!in_array($value, $normalized, true)) {
      $normalized[] = $value;
    }
  }

  if (empty($normalized)) {
    return $normalized;
  }

  $normalized = array_values(array_unique($normalized));
  sort($normalized, SORT_NUMERIC);

  return $normalized;
}

function admin_notif_threshold_hit(?int $hoursUntil, int $threshold, ?int $secondsUntil = null, int $toleranceSeconds = 1800): bool
{
  if ($secondsUntil !== null) {
    $diffSeconds = $secondsUntil - ($threshold * 3600);
    if (abs($diffSeconds) <= $toleranceSeconds) {
      return true;
    }
  }

  return $hoursUntil !== null && $hoursUntil === $threshold;
}

function sync_system_notifications(mysqli $conn, array $context): void
{
  try {
    $eventId   = (int)($context['event_id'] ?? 0);
    $eventName = (string)($context['event_name'] ?? '');

    $nominationStartThresholds = $context['nomination_start_thresholds']
      ?? notif_config_get_thresholds($conn, 'notif_nomination_start_hours', NOTIF_CONFIG_DEFAULT_NOMINATION_START_HOURS);
    $nominationDeadlineThresholds = $context['nomination_deadline_thresholds']
      ?? notif_config_get_thresholds($conn, 'notif_nomination_end_hours', NOTIF_CONFIG_DEFAULT_NOMINATION_END_HOURS);
    $pendingDeadlineThresholds = $context['pending_deadline_thresholds']
      ?? notif_config_get_thresholds($conn, 'notif_pending_deadline_hours', NOTIF_CONFIG_DEFAULT_PENDING_DEADLINE_HOURS);
    if (empty($pendingDeadlineThresholds)) {
      $pendingDeadlineThresholds = $nominationDeadlineThresholds;
    }
    $votingStartThresholds = $context['voting_start_thresholds']
      ?? notif_config_get_thresholds($conn, 'notif_voting_start_hours', NOTIF_CONFIG_DEFAULT_VOTING_START_HOURS);
    $votingEndThresholds = $context['voting_end_thresholds']
      ?? notif_config_get_thresholds($conn, 'notif_voting_end_hours', NOTIF_CONFIG_DEFAULT_VOTING_END_HOURS);
    $pendingMinimum = max(0, (int)($context['pending_minimum'] ?? NOTIF_CONFIG_DEFAULT_PENDING_MIN_TOTAL));

    $nominationStartThresholds = admin_notif_normalize_thresholds($nominationStartThresholds);
    $nominationDeadlineThresholds = admin_notif_normalize_thresholds($nominationDeadlineThresholds);
    $pendingDeadlineThresholds = admin_notif_normalize_thresholds($pendingDeadlineThresholds);
    $votingStartThresholds = admin_notif_ensure_milestones($votingStartThresholds, [1, 0, -1]);
    $votingEndThresholds   = admin_notif_ensure_milestones($votingEndThresholds, [1, 0, -1]);

    $now = $context['now'] ?? null;
    if (!($now instanceof DateTimeInterface)) {
      $tz  = new DateTimeZone('Asia/Manila');
      $now = new DateTime('now', $tz);
    }

    $start = $context['nomination_start'] ?? null;
    if ($start !== null && !($start instanceof DateTimeInterface)) {
      $start = new DateTime((string)$start);
    }

    $end = $context['nomination_end'] ?? null;
    if ($end !== null && !($end instanceof DateTimeInterface)) {
      $end = new DateTime((string)$end);
    }

    $votingStart = $context['voting_start'] ?? null;
    if ($votingStart !== null && !($votingStart instanceof DateTimeInterface)) {
      $votingStart = new DateTime((string)$votingStart);
    }

    $votingEnd = $context['voting_end'] ?? null;
    if ($votingEnd !== null && !($votingEnd instanceof DateTimeInterface)) {
      $votingEnd = new DateTime((string)$votingEnd);
    }

    $hoursUntilNominationStart = $context['hours_until_nomination_start'] ?? null;
    if ($hoursUntilNominationStart !== null && $hoursUntilNominationStart !== '') {
      $hoursUntilNominationStart = (int)$hoursUntilNominationStart;
    } else {
      $hoursUntilNominationStart = null;
    }

    $hoursLeftNomination = $context['hours_left_nomination'] ?? null;
    if ($hoursLeftNomination !== null && $hoursLeftNomination !== '') {
      $hoursLeftNomination = (int)$hoursLeftNomination;
    } else {
      $hoursLeftNomination = null;
    }

    $hoursUntilVotingStart = $context['hours_until_voting_start'] ?? null;
    if ($hoursUntilVotingStart !== null && $hoursUntilVotingStart !== '') {
      $hoursUntilVotingStart = (int)$hoursUntilVotingStart;
    } else {
      $hoursUntilVotingStart = null;
    }

    $hoursUntilVotingEnd = $context['hours_until_voting_end'] ?? null;
    if ($hoursUntilVotingEnd !== null && $hoursUntilVotingEnd !== '') {
      $hoursUntilVotingEnd = (int)$hoursUntilVotingEnd;
    } else {
      $hoursUntilVotingEnd = null;
    }

    $secondsUntilVotingStart = $context['seconds_until_voting_start'] ?? null;
    if ($secondsUntilVotingStart !== null && $secondsUntilVotingStart !== '') {
      $secondsUntilVotingStart = (int)$secondsUntilVotingStart;
    } else {
      $secondsUntilVotingStart = null;
    }

    $secondsUntilVotingEnd = $context['seconds_until_voting_end'] ?? null;
    if ($secondsUntilVotingEnd !== null && $secondsUntilVotingEnd !== '') {
      $secondsUntilVotingEnd = (int)$secondsUntilVotingEnd;
    } else {
      $secondsUntilVotingEnd = null;
    }

    $pendingTotal  = max(0, (int)($context['pending_total'] ?? 0));
    $pendingRecent = max(0, (int)($context['pending_recent'] ?? 0));
    $recentTotal   = max(0, (int)($context['recent_total'] ?? 0));

    $nowIso = $now->format(DATE_ATOM);
    $nowYmd = $now->format('Ymd');

    if ($start instanceof DateTimeInterface && $hoursUntilNominationStart !== null) {
      $startLabel = $start->format('F j, Y g:i A');
      $startIso   = $start->format(DATE_ATOM);
      foreach ($nominationStartThresholds as $threshold) {
        if ($hoursUntilNominationStart === $threshold) {
          $marker = sprintf('nom_start:%d:%s:%d', $eventId, $start->format('YmdHi'), $threshold);
          $payload = [
            'event_id'       => $eventId,
            'event_name'     => $eventName,
            'start'          => $startLabel,
            'start_iso'      => $startIso,
            'threshold_hours'=> $threshold,
            'threshold_days' => ($threshold % 24 === 0) ? (int)($threshold / 24) : ($threshold / 24),
            'href'           => 'events.php',
          ];
          system_notif_record_once($conn, 'system_nomination_start', $payload, $marker, 0, $eventId);
          break;
        }
      }
    }

    if ($end instanceof DateTimeInterface && $hoursLeftNomination !== null) {
      $deadlineLabel = $end->format('F j, Y g:i A');
      $deadlineIso   = $end->format(DATE_ATOM);
      foreach ($nominationDeadlineThresholds as $threshold) {
        if ($hoursLeftNomination === $threshold) {
          $marker = sprintf('nom_end:%d:%s:%d', $eventId, $end->format('YmdHi'), $threshold);
          $payload = [
            'event_id'       => $eventId,
            'event_name'     => $eventName,
            'deadline'       => $deadlineLabel,
            'deadline_iso'   => $deadlineIso,
            'threshold_hours'=> $threshold,
            'threshold_days' => ($threshold % 24 === 0) ? (int)($threshold / 24) : ($threshold / 24),
            'pending_count'  => $pendingTotal,
            'unapproved_count' => $pendingTotal,
            'href'           => 'nominations.php?status=pending',
          ];
          system_notif_record_once($conn, 'system_nomination_deadline', $payload, $marker, 0, $eventId);
          break;
        }
      }

      if ($pendingTotal >= $pendingMinimum) {
        foreach ($pendingDeadlineThresholds as $threshold) {
          if ($hoursLeftNomination === $threshold) {
            $marker = sprintf('pending_deadline:%d:%s:%d:%d', $eventId, $end->format('YmdHi'), $threshold, $pendingTotal);
            $payload = [
              'event_id'       => $eventId,
              'event_name'     => $eventName,
              'deadline'       => $deadlineLabel,
              'deadline_iso'   => $deadlineIso,
              'threshold_hours'=> $threshold,
              'threshold_days' => ($threshold % 24 === 0) ? (int)($threshold / 24) : ($threshold / 24),
              'pending_count'  => $pendingTotal,
              'unapproved_count' => $pendingTotal,
              'href'           => 'nominations.php?status=pending',
            ];
            system_notif_record_once($conn, 'system_pending_deadline', $payload, $marker, 0, $eventId);
            break;
          }
        }
      }
    }

    if ($pendingTotal >= $pendingMinimum) {
      $marker = sprintf('pending:%d:%s:%d:%d:%d', $eventId, $nowYmd, $pendingTotal, $pendingRecent, $recentTotal);
      $payload = [
        'event_id'      => $eventId,
        'event_name'    => $eventName,
        'pending_count' => $pendingTotal,
        'unapproved_count' => $pendingTotal,
        'new_entries'   => $pendingRecent,
        'recent_total'  => $recentTotal,
        'generated_at'  => $nowIso,
        'href'          => 'nominations.php?status=pending',
      ];
      system_notif_record_once($conn, 'system_pending_nomination', $payload, $marker, 0, $eventId);
    }

    if ($votingStart instanceof DateTimeInterface && $hoursUntilVotingStart !== null) {
      $startLabel = $votingStart->format('F j, Y g:i A');
      $startIso   = $votingStart->format(DATE_ATOM);
      foreach ($votingStartThresholds as $threshold) {
        if (admin_notif_threshold_hit($hoursUntilVotingStart, (int)$threshold, $secondsUntilVotingStart)) {
          $marker = sprintf('vote_start:%d:%s:%d', $eventId, $votingStart->format('YmdHi'), $threshold);
          $payload = [
            'event_id'       => $eventId,
            'event_name'     => $eventName,
            'start'          => $startLabel,
            'start_iso'      => $startIso,
            'threshold_hours'=> $threshold,
            'threshold_days' => ($threshold % 24 === 0) ? (int)($threshold / 24) : ($threshold / 24),
            'href'           => 'events.php',
          ];
          system_notif_record_once($conn, 'system_voting_start', $payload, $marker, 0, $eventId);
          break;
        }
      }
    }

    if ($votingEnd instanceof DateTimeInterface && $hoursUntilVotingEnd !== null) {
      $endLabel = $votingEnd->format('F j, Y g:i A');
      $endIso   = $votingEnd->format(DATE_ATOM);
      foreach ($votingEndThresholds as $threshold) {
        if (admin_notif_threshold_hit($hoursUntilVotingEnd, (int)$threshold, $secondsUntilVotingEnd)) {
          $marker = sprintf('vote_end:%d:%s:%d', $eventId, $votingEnd->format('YmdHi'), $threshold);
          $payload = [
            'event_id'       => $eventId,
            'event_name'     => $eventName,
            'end'            => $endLabel,
            'end_iso'        => $endIso,
            'threshold_hours'=> $threshold,
            'threshold_days' => ($threshold % 24 === 0) ? (int)($threshold / 24) : ($threshold / 24),
            'href'           => 'events.php',
          ];
          system_notif_record_once($conn, 'system_voting_end', $payload, $marker, 0, $eventId);
          break;
        }
      }
    }
  } catch (Throwable $error) {
    error_log('admin_notification: sync_system_notifications failed: ' . $error->getMessage());
  }
}

/* -------- Active (or latest) event -------- */
$evt = $conn->query("
  SELECT event_id AS id, event_name, nomination_start, nomination_end, voting_start, voting_end
  FROM tbl_events
  WHERE is_active = 1
  ORDER BY year DESC, event_id DESC
  LIMIT 1
");
if (!$evt || $evt->num_rows === 0) {
  $evt = $conn->query("
    SELECT event_id AS id, event_name, nomination_start, nomination_end, voting_start, voting_end
    FROM tbl_events
    ORDER BY year DESC, event_id DESC
    LIMIT 1
  ");
}
if (!$evt || $evt->num_rows === 0) err('No event found', 404);

$event = $evt->fetch_assoc();
if (empty($event['nomination_end'])) err('Event has no nomination_end configured.', 500);

/* -------- Helpers -------- */
$tz = new DateTimeZone('Asia/Manila');
$now = new DateTime('now', $tz);
$nowTs = $now->getTimestamp();
$recentWindowHours = 24;
$recentCutoff = (clone $now)->modify(sprintf('- %d hours', $recentWindowHours));

$startNom = !empty($event['nomination_start']) ? new DateTime($event['nomination_start'], $tz) : null;
$endNom   = new DateTime($event['nomination_end'], $tz);
$endNomTs = $endNom->getTimestamp();
$diffNomSecs = $endNomTs - $nowTs;
$daysLeftNom = ($diffNomSecs < 0) ? -1 : (int)ceil($diffNomSecs / 86400);
$hoursLeftNom = ($diffNomSecs < 0)
  ? (int)floor($diffNomSecs / 3600)
  : (int)ceil($diffNomSecs / 3600);

$secondsUntilNominationStart = $startNom ? ($startNom->getTimestamp() - $nowTs) : null;
$hoursUntilNominationStart = null;
if ($secondsUntilNominationStart !== null) {
  $hoursUntilNominationStart = ($secondsUntilNominationStart < 0)
    ? (int)floor($secondsUntilNominationStart / 3600)
    : (int)ceil($secondsUntilNominationStart / 3600);
}

$votingStart = !empty($event['voting_start']) ? new DateTime($event['voting_start'], $tz) : null;
$votingEnd   = !empty($event['voting_end']) ? new DateTime($event['voting_end'], $tz) : null;

$secondsUntilVotingStart = $votingStart ? ($votingStart->getTimestamp() - $nowTs) : null;
$secondsUntilVotingEnd   = $votingEnd ? ($votingEnd->getTimestamp() - $nowTs) : null;

$hoursUntilVotingStart = null;
if ($secondsUntilVotingStart !== null) {
  $hoursUntilVotingStart = ($secondsUntilVotingStart < 0)
    ? (int)floor($secondsUntilVotingStart / 3600)
    : (int)ceil($secondsUntilVotingStart / 3600);
}

$hoursUntilVotingEnd = null;
if ($secondsUntilVotingEnd !== null) {
  $hoursUntilVotingEnd = ($secondsUntilVotingEnd < 0)
    ? (int)floor($secondsUntilVotingEnd / 3600)
    : (int)ceil($secondsUntilVotingEnd / 3600);
}

$daysUntilVotingStart = null;
if ($secondsUntilVotingStart !== null) {
  $daysUntilVotingStart = ($secondsUntilVotingStart < 0)
    ? (int)floor($secondsUntilVotingStart / 86400)
    : (int)ceil($secondsUntilVotingStart / 86400);
}

$daysUntilVotingEnd = null;
if ($secondsUntilVotingEnd !== null) {
  $daysUntilVotingEnd = ($secondsUntilVotingEnd < 0)
    ? (int)floor($secondsUntilVotingEnd / 86400)
    : (int)ceil($secondsUntilVotingEnd / 86400);
}

$votingStatus = 'unscheduled';
if ($secondsUntilVotingEnd !== null && $secondsUntilVotingEnd < 0) {
  $votingStatus = 'ended';
} elseif ($secondsUntilVotingStart !== null) {
  $votingStatus = ($secondsUntilVotingStart > 0) ? 'upcoming' : 'ongoing';
} elseif ($secondsUntilVotingEnd !== null) {
  $votingStatus = ($secondsUntilVotingEnd >= 0) ? 'ongoing' : 'ended';
} elseif ($votingStart instanceof DateTimeInterface || $votingEnd instanceof DateTimeInterface) {
  $votingStatus = 'upcoming';
}

/* -------- Pending registrations (normalized statuses) -------- */
$PENDING_STATUSES_NORMALIZED = ['submitted', 'pending', 'in review', 'under review'];
$NEEDS_INFO_STATUSES_NORMALIZED = ['needs info', 'needs information'];
$REJECTED_STATUSES_NORMALIZED = ['rejected', 'declined', 'denied', 'not approved'];
$pendingTotal = 0;
$pendingRecent = 0;
$pendingActionableTotal = 0;
$pendingActionableRecent = 0;
$needsInfoTotal = 0;
$needsInfoRecent = 0;
$rejectedTotal = 0;
$rejectedRecent = 0;
$recentAll = 0;

$stmt = $conn->prepare("
  SELECT status,
         COUNT(*) AS total_count,
         SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS recent_count
    FROM tbl_nominations
    WHERE event_id = ?
    GROUP BY status
");

$recentCutoffSql = $recentCutoff->format('Y-m-d H:i:s');
$stmt->bind_param('si', $recentCutoffSql, $event['id']);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
  $raw  = (string)($row['status'] ?? '');
  $cnt  = (int)($row['total_count'] ?? 0);
  $recentCnt = (int)($row['recent_count'] ?? 0);
  $norm = strtolower(str_replace(['_', '-'], ' ', trim($raw)));

  $isNeedsInfo = in_array($norm, $NEEDS_INFO_STATUSES_NORMALIZED, true);
  $isPendingActionable = in_array($norm, $PENDING_STATUSES_NORMALIZED, true);
  $isRejected = in_array($norm, $REJECTED_STATUSES_NORMALIZED, true);

  if ($isPendingActionable || $isNeedsInfo) {
    $pendingTotal += $cnt;
    $pendingRecent += $recentCnt;
  }

  if ($isPendingActionable) {
    $pendingActionableTotal += $cnt;
    $pendingActionableRecent += $recentCnt;
  }

  if ($isNeedsInfo) {
    $needsInfoTotal += $cnt;
    $needsInfoRecent += $recentCnt;
  }

  if ($isRejected) {
    $rejectedTotal += $cnt;
    $rejectedRecent += $recentCnt;
  }

  $recentAll += $recentCnt;
}
$stmt->close();

$nominationDeadlineThresholds = notif_config_get_thresholds(
  $conn,
  'notif_nomination_end_hours',
  NOTIF_CONFIG_DEFAULT_NOMINATION_END_HOURS
);
$pendingDeadlineThresholds = notif_config_get_thresholds(
  $conn,
  'notif_pending_deadline_hours',
  NOTIF_CONFIG_DEFAULT_PENDING_DEADLINE_HOURS
);
if (empty($pendingDeadlineThresholds)) {
  $pendingDeadlineThresholds = $nominationDeadlineThresholds;
}
$votingStartThresholds = notif_config_get_thresholds(
  $conn,
  'notif_voting_start_hours',
  NOTIF_CONFIG_DEFAULT_VOTING_START_HOURS
);
$votingEndThresholds = notif_config_get_thresholds(
  $conn,
  'notif_voting_end_hours',
  NOTIF_CONFIG_DEFAULT_VOTING_END_HOURS
);
$nominationStartThresholds = notif_config_get_thresholds(
  $conn,
  'notif_nomination_start_hours',
  NOTIF_CONFIG_DEFAULT_NOMINATION_START_HOURS
);
$pendingMinimum = notif_config_get_int(
  $conn,
  'notif_pending_min_total',
  NOTIF_CONFIG_DEFAULT_PENDING_MIN_TOTAL,
  0,
  10000
);
$pendingWarningHours = notif_config_get_int(
  $conn,
  'notif_pending_warning_hours',
  NOTIF_CONFIG_DEFAULT_PENDING_WARNING_HOURS,
  0,
  720
);

$pendingDeadlineWarning = (
  $pendingTotal >= $pendingMinimum
  && $diffNomSecs >= 0
  && $diffNomSecs <= ($pendingWarningHours * 3600)
);

sync_system_notifications($conn, [
  'event_id'                    => (int)$event['id'],
  'event_name'                  => (string)$event['event_name'],
  'now'                         => $now,
  'nomination_start'            => $startNom,
  'nomination_end'              => $endNom,
  'hours_until_nomination_start'=> $hoursUntilNominationStart,
  'hours_left_nomination'       => $hoursLeftNom,
  'pending_total'               => $pendingTotal,
  'pending_recent'              => $pendingRecent,
  'pending_actionable_total'    => $pendingActionableTotal,
  'pending_actionable_recent'   => $pendingActionableRecent,
  'needs_info_total'            => $needsInfoTotal,
  'needs_info_recent'           => $needsInfoRecent,
  'rejected_total'              => $rejectedTotal,
  'rejected_recent'             => $rejectedRecent,
  'recent_total'                => $recentAll,
  'voting_start'                => $votingStart,
  'voting_end'                  => $votingEnd,
  'hours_until_voting_start'    => $hoursUntilVotingStart,
  'hours_until_voting_end'      => $hoursUntilVotingEnd,
  'seconds_until_voting_start'  => $secondsUntilVotingStart,
  'seconds_until_voting_end'    => $secondsUntilVotingEnd,
  'nomination_start_thresholds' => $nominationStartThresholds,
  'nomination_deadline_thresholds' => $nominationDeadlineThresholds,
  'pending_deadline_thresholds' => $pendingDeadlineThresholds,
  'voting_start_thresholds'     => $votingStartThresholds,
  'voting_end_thresholds'       => $votingEndThresholds,
  'pending_minimum'             => $pendingMinimum,
]);
/* -------- Response -------- */
ok([
  'timestamp' => $now->format(DATE_ATOM),
 'event' => [
    'id'                     => (int)$event['id'],
    'name'                   => $event['event_name'],
    'nomination_start'       => $startNom ? $startNom->format('F j, Y g:i A') : null,
    'nomination_start_iso'   => $startNom ? $startNom->format(DATE_ATOM) : null,
    'nomination_end'         => $endNom->format('F j, Y g:i A'),
    'nomination_end_iso'     => $endNom->format(DATE_ATOM),
    'nomination_seconds_left'=> $diffNomSecs,
    'days_left'              => $daysLeftNom,
    'hours_left'             => $hoursLeftNom,
  ],
  'nomination' => [
    'start_iso'          => $startNom ? $startNom->format(DATE_ATOM) : null,
    'start_label'        => $startNom ? $startNom->format('F j, Y g:i A') : null,
    'hours_until_start'  => $hoursUntilNominationStart,
    'deadline_iso'      => $endNom->format(DATE_ATOM),
    'deadline_label'    => $endNom->format('F j, Y g:i A'),
    'seconds_remaining' => $diffNomSecs,
    'days_remaining'    => $daysLeftNom,
    'hours_remaining'   => $hoursLeftNom,
    'is_active'         => $diffNomSecs >= 0,
    'threshold_hours'   => $hoursLeftNom,
  ],
  'voting' => [
    'start'                 => $votingStart ? $votingStart->format('F j, Y g:i A') : null,
    'start_iso'             => $votingStart ? $votingStart->format(DATE_ATOM) : null,
    'end'                   => $votingEnd ? $votingEnd->format('F j, Y g:i A') : null,
    'end_iso'               => $votingEnd ? $votingEnd->format(DATE_ATOM) : null,
    'status'                => $votingStatus,
    'seconds_until_start'   => $secondsUntilVotingStart,
    'seconds_until_end'     => $secondsUntilVotingEnd,
    'days_until_start'      => $daysUntilVotingStart,
    'days_until_end'        => $daysUntilVotingEnd,
    'hours_until_start'     => $hoursUntilVotingStart,
    'hours_until_end'       => $hoursUntilVotingEnd,
  ],
  'pending' => $pendingTotal,
  'pending_actionable' => $pendingActionableTotal,
  'pending_actionable_recent' => $pendingActionableRecent,
  'pending_recent' => $pendingRecent,
  'recent_pending' => $pendingRecent,
  'pending_deadline_warning' => $pendingDeadlineWarning,
  'unapproved' => $pendingTotal,
  'unapproved_recent' => $pendingRecent,
  'unapproved_actionable' => $pendingActionableTotal,
  'unapproved_actionable_recent' => $pendingActionableRecent,
  'unapproved_deadline_warning' => $pendingDeadlineWarning,
  'needs_info' => $needsInfoTotal,
  'needs_info_recent' => $needsInfoRecent,
  'rejected' => $rejectedTotal,
  'rejected_recent' => $rejectedRecent,
  'recent_submissions' => $pendingRecent,
  'recent_total_submissions' => $recentAll,
  'recent_window_hours' => $recentWindowHours,
  'recent_window_cutoff_iso' => $recentCutoff->format(DATE_ATOM),
  'settings' => [
    'nomination_start_hours'    => $nominationStartThresholds,
    'nomination_deadline_hours' => $nominationDeadlineThresholds,
    'pending_deadline_hours'    => $pendingDeadlineThresholds,
    'voting_start_hours'        => $votingStartThresholds,
    'voting_end_hours'          => $votingEndThresholds,
    'pending_minimum'           => $pendingMinimum,
    'pending_warning_hours'     => $pendingWarningHours,
  ],
]);
