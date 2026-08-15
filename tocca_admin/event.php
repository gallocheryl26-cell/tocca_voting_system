<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/qr_url.php';

function out(array $payload, int $code = 200): void {
    http_response_code($code);
    if (ob_get_length()) { @ob_clean(); }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function out_error(string $msg, int $code = 400): void { out(['status'=>'error','message'=>$msg], $code); }

function audit_log(mysqli $conn, array $row): void {
    $admin_id   = $_SESSION['admin_id']   ?? ($_SESSION['user_id'] ?? null);
    $admin_name = $_SESSION['admin_name'] ?? ($_SESSION['username'] ?? null);
    $module      = $row['module']      ?? 'events';
    $entity_type = $row['entity_type'] ?? 'event';
    $entity_id   = isset($row['entity_id']) ? (int)$row['entity_id'] : null;
    $action      = $row['action']      ?? null;
    $details     = $row['details']     ?? null;
    $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

    $sql = "INSERT INTO tbl_admin_audit_log
              (event_time, admin_id, admin_name, module, entity_type, entity_id, action, details_json)
            VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)";
    $st = $conn->prepare($sql); if(!$st){ error_log('audit_log prepare failed: '.$conn->error); return; }
    $st->bind_param('isssiss', $admin_id, $admin_name, $module, $entity_type, $entity_id, $action, $json);
    if(!$st->execute()) error_log('audit_log execute failed: '.$st->error);
    $st->close();
}

function get_event(mysqli $conn, int $id): ?array {
    $st = $conn->prepare("SELECT event_id, event_name, year, description, is_active, is_archived, created_at,
                                 nomination_start, nomination_end, voting_start, voting_end
                          FROM tbl_events WHERE event_id=?");
    if(!$st) return null;
    $st->bind_param('i',$id); $st->execute();
    $res=$st->get_result(); $row=$res?$res->fetch_assoc():null; $st->close();
    return $row ?: null;
}
function build_changed(?array $before, ?array $after, array $keys): array {
    $changed = []; if(!$after) return $changed;
    foreach($keys as $k){ $old=$before[$k]??null; $new=$after[$k]??null; if($old!==$new){ $changed[$k]=['old'=>$old,'new'=>$new]; } }
    return $changed;
}
function normalize_dt(?string $v): ?string {
    $v = trim((string)$v); if($v==='') return null;
    $v = str_replace('T',' ',$v);
    if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',$v)) $v.=':00';
    return $v;
}
function derive_year(?string $nom_start, ?string $vote_start): int {
    $src = $nom_start ?: $vote_start;
    if($src && preg_match('/^\d{4}/',$src,$m)) return (int)$m[0];
    return (int)date('Y');
}

function nomination_form_configured(mysqli $conn): bool {
    $sql = "SELECT COUNT(*) AS cnt FROM tbl_nomination_fields WHERE is_active = 1";
    $res = $conn->query($sql);
    if ($res instanceof mysqli_result) {
        $row = $res->fetch_assoc();
        $res->free();
        return (int)($row['cnt'] ?? 0) > 0;
    }
    return false;
}

function parse_dt(?string $s): ?DateTime {
    if (!$s) return null;
    $s = trim($s);
    $fmts = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s'];
    foreach ($fmts as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt instanceof DateTime) return $dt;
    }
    $ts = strtotime($s);
    return $ts ? (new DateTime())->setTimestamp($ts) : null;
}

function validate_schedule_input(?string $ns, ?string $ne, ?string $vs, ?string $ve, bool $requireBoth, ?string $existingNomStart = null): array {
    $ns = normalize_dt($ns); $ne = normalize_dt($ne);
    $vs = normalize_dt($vs); $ve = normalize_dt($ve);

    $hasNomAny  = ($ns !== null) || ($ne !== null);
    $hasVoteAny = ($vs !== null) || ($ve !== null);

    $dns = parse_dt($ns);
    $dne = parse_dt($ne);
    $dvs = parse_dt($vs);
    $dve = parse_dt($ve);

    $hasNomFull  = $dns && $dne;
    $hasVoteFull = $dvs && $dve;

    if ($hasNomAny && !$hasNomFull) return ['ok'=>false, 'message'=>'Registration period must have both start and end.'];
    if ($hasVoteAny && !$hasVoteFull) return ['ok'=>false, 'message'=>'Voting period must have both start and end.'];

    if ($requireBoth) {
        if (!$hasNomFull) return ['ok'=>false, 'message'=>'Active event requires a complete Registration period.'];
        if (!$hasVoteFull) return ['ok'=>false, 'message'=>'Active event requires a complete Voting period.'];
    }

    if ($hasNomFull) {
        $tz = new DateTimeZone('Asia/Manila');
        $todayStart = new DateTime('today', $tz);
        $existingNorm = $existingNomStart !== null ? normalize_dt($existingNomStart) : null;
        $unchanged = ($existingNorm !== null && $existingNorm === $ns);
        if (!$unchanged && $dns < $todayStart) {
            return ['ok'=>false, 'message'=>'Registration start must be today or a future date.'];
        }
    }

    if ($hasNomFull && $dns >= $dne) return ['ok'=>false, 'message'=>'Registration start must be before registration end.'];
    if ($hasVoteFull && $dvs >= $dve) return ['ok'=>false, 'message'=>'Voting start must be before voting end.'];

    if ($hasNomFull && $hasVoteFull) {
        if ($dvs < $dne) return ['ok'=>false, 'message'=>'Voting must start on or after the registration end.'];
    }

    return ['ok'=>true, 'message'=>''];
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: 'null', true) ?? [];
$action = (string)($data['action'] ?? '');

if ($action === 'loadDropdown') {
    $stmt = $conn->prepare("SELECT event_id, event_name, year
                            FROM tbl_events
                            WHERE is_archived = 0
                            ORDER BY created_at DESC");
    if (!$stmt) out_error('Server error. Could not prepare query.', 500);
    $stmt->execute();
    $res = $stmt->get_result();
    $events = [];
    while ($res && ($row = $res->fetch_assoc())) $events[] = $row;
    $stmt->close();
    out(['status' => 'success', 'data' => $events]);
}

if ($action === 'create' || $action === 'add_event') {
    $name        = trim((string)($data['event_name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $isActiveReq = isset($data['is_active']) ? (int)$data['is_active'] : 0;

    $nom_start = normalize_dt($data['nomination_start'] ?? null);
    $nom_end   = normalize_dt($data['nomination_end']   ?? null);
    $vote_start= normalize_dt($data['voting_start']     ?? null);
    $vote_end  = normalize_dt($data['voting_end']       ?? null);
    if ($name === '') out_error('Event name is required.');
    $v = validate_schedule_input($nom_start, $nom_end, $vote_start, $vote_end, $isActiveReq === 1);
    if (!$v['ok']) out_error($v['message'], 400);

    $year = derive_year($nom_start, $vote_start);

    $stmt = $conn->prepare("SELECT event_id FROM tbl_events
                            WHERE LOWER(event_name)=LOWER(?) AND year=? AND is_archived=0 LIMIT 1");
    if (!$stmt) out_error('Server error. Could not prepare duplicate check.', 500);
    $stmt->bind_param('si', $name, $year);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows > 0) { $stmt->close(); out(['status'=>'duplicate','message'=>'An event with the same name and year already exists.']); }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO tbl_events
        (event_name, year, description, is_active, is_archived, created_at,
         nomination_start, nomination_end, voting_start, voting_end)
        VALUES (?, ?, ?, 0, 0, NOW(), ?, ?, ?, ?)");
    if (!$stmt) out_error('Server error. Could not prepare insert.', 500);
    $stmt->bind_param('sisssss', $name, $year, $description, $nom_start, $nom_end, $vote_start, $vote_end);
    if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); out_error($err ?: 'Failed to create event.', 500); }
    $newId = (int)$stmt->insert_id; $stmt->close();

    if ($isActiveReq === 1) {
        $conn->query("UPDATE tbl_events SET is_active = 0");
        $stmt = $conn->prepare("UPDATE tbl_events SET is_active = 1 WHERE event_id = ?");
        $stmt->bind_param('i', $newId); $stmt->execute(); $stmt->close();
    }

    $after = get_event($conn, $newId);
    audit_log($conn, [
        'module'=>'events','entity_type'=>'event','entity_id'=>$newId,'action'=>'create',
        'details'=>['event_id'=>$newId,'new'=>$after]
    ]);

    $nomPeriodDefined = !empty($after['nomination_start']) || !empty($after['nomination_end']);
    $needsFormSetup = $nomPeriodDefined && !nomination_form_configured($conn);

    out([
        'status' => 'success',
        'message' => 'Event created successfully.',
        'event_id' => $newId,
        'needs_form_setup' => $needsFormSetup,
    ]);
}

if ($action === 'load_all') {
    $res = $conn->query("SELECT event_id, event_name, year, description, is_active, is_archived, created_at,
                                nomination_start, nomination_end, voting_start, voting_end
                         FROM tbl_events
                         WHERE COALESCE(is_archived, 0) = 0
                         ORDER BY is_active DESC, created_at DESC");
    $events = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $eid = (int) ($row['event_id'] ?? 0);
        $row['public_slug'] = $eid > 0 ? public_slug_for_event($conn, $eid) : '';
        $row['register_url'] = $eid > 0 ? qr_nomination_form_url($conn, $eid) : '';
        $row['vote_url'] = $eid > 0 ? qr_vote_portal_url($conn, $eid) : '';
        $row['track_url'] = qr_tracking_url($conn);
        $events[] = $row;
    }
    out(['status'=>'success','events'=>$events]);
}

if ($action === 'activate') {
    $eventId = (int)($data['event_id'] ?? 0);
    if ($eventId <= 0) out_error('Invalid event ID.');

    $cur = get_event($conn, $eventId);
    if (!$cur) out_error('Event not found.', 404);
    $v = validate_schedule_input(
        $cur['nomination_start'] ?? null,
        $cur['nomination_end']   ?? null,
        $cur['voting_start']     ?? null,
        $cur['voting_end']       ?? null,
        true,
        $cur['nomination_start'] ?? null
    );
    if (!$v['ok']) out_error($v['message'], 400);

    $conn->query("UPDATE tbl_events SET is_active = 0");
    $stmt = $conn->prepare("UPDATE tbl_events SET is_active = 1 WHERE event_id = ?");
    if (!$stmt) out_error('Server error. Could not prepare update.', 500);
    $stmt->bind_param('i', $eventId);
    if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); out_error($err ?: 'Failed to activate event.', 500); }
    $stmt->close();

    $after = get_event($conn, $eventId);
    audit_log($conn, [
        'module'=>'events','entity_type'=>'event','entity_id'=>$eventId,'action'=>'activate',
        'details'=>['event_id'=>$eventId,'new'=>$after]
    ]);
    out(['status'=>'success']);
}

if ($action === 'archive') {
    $eventId = (int)($data['event_id'] ?? 0);
    if ($eventId <= 0) out_error('Invalid event ID.');

    $before = get_event($conn, $eventId);

    $stmt = $conn->prepare("UPDATE tbl_events
                            SET is_archived = 1, is_active = 0, archived_date = NOW()
                            WHERE event_id = ?");
    if (!$stmt) out_error('Server error. Could not prepare archive.', 500);
    $stmt->bind_param('i', $eventId);
    if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); out_error($err ?: 'Failed to archive event.', 500); }
    $stmt->close();

    $sql = "
        UPDATE tbl_voters 
        SET is_archived = 1 
        WHERE voters_id IN (
            SELECT DISTINCT pc.voters_id 
            FROM tbl_poll_choice pc
            JOIN tbl_questions q  ON pc.question_id = q.question_id
            JOIN tbl_categories c ON q.category_id = c.category_id
            WHERE c.event_id = ?
            UNION
            SELECT DISTINCT pf.voters_id 
            FROM tbl_poll_freetext pf
            JOIN tbl_questions q2  ON pf.question_id = q2.question_id
            JOIN tbl_categories c2 ON q2.category_id = c2.category_id
            WHERE c2.event_id = ?
        )";
    $stmt2 = $conn->prepare($sql);
    if ($stmt2) { $stmt2->bind_param('ii', $eventId, $eventId); $stmt2->execute(); $stmt2->close(); }

    $after = get_event($conn, $eventId);
    $changed = build_changed($before, $after, ['is_archived','is_active']);

    audit_log($conn, [
        'module'=>'events','entity_type'=>'event','entity_id'=>$eventId,'action'=>'archive',
        'details'=>['event_id'=>$eventId,'old'=>$before,'new'=>$after,'diff'=>['changed'=>$changed]]
    ]);
    out(['status'=>'success']);
}

if ($action === 'update_event') {
    $eventId     = (int)($data['event_id'] ?? 0);
    $name        = trim((string)($data['event_name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $hasActive   = array_key_exists('is_active', $data);
    $isActiveReq = $hasActive ? (int)$data['is_active'] : null;

    $nom_start = normalize_dt($data['nomination_start'] ?? null);
    $nom_end   = normalize_dt($data['nomination_end']   ?? null);
    $vote_start= normalize_dt($data['voting_start']     ?? null);
    $vote_end  = normalize_dt($data['voting_end']       ?? null);

    if ($eventId <= 0 || $name === '') out_error('Missing required fields.');

    $before = get_event($conn, $eventId);
    if (!$before) out_error('Event not found.', 404);

    $requireBoth = ($isActiveReq === 1);
    if (!$hasActive && (int)$before['is_active'] === 1) {
        $requireBoth = true;
    }
    $v = validate_schedule_input(
        $nom_start,
        $nom_end,
        $vote_start,
        $vote_end,
        $requireBoth,
        $before['nomination_start'] ?? null
    );
    if (!$v['ok']) out_error($v['message'], 400);

    $year = derive_year($nom_start, $vote_start);

    $stmt = $conn->prepare("SELECT event_id FROM tbl_events
                            WHERE LOWER(event_name)=LOWER(?) AND year=? AND is_archived=0 AND event_id<>?
                            LIMIT 1");
    if (!$stmt) out_error('Server error. Could not prepare duplicate check.', 500);
    $stmt->bind_param('sii', $name, $year, $eventId);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows > 0) { $stmt->close(); out(['status'=>'duplicate','message'=>'Another event with the same name and year already exists.']); }
    $stmt->close();

    $stmt = $conn->prepare("UPDATE tbl_events SET
                              event_name=?, year=?, description=?,
                              nomination_start=?, nomination_end=?, voting_start=?, voting_end=?
                            WHERE event_id=?");
    if (!$stmt) out_error('Server error. Could not prepare update.', 500);
    $stmt->bind_param('sisssssi', $name, $year, $description, $nom_start, $nom_end, $vote_start, $vote_end, $eventId);
    if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); out_error($err ?: 'Failed to update event.', 500); }
    $stmt->close();

    if ($hasActive) {
        if ((int)$isActiveReq === 1) {
            $conn->query("UPDATE tbl_events SET is_active = 0");
            $stmt = $conn->prepare("UPDATE tbl_events SET is_active = 1 WHERE event_id = ?");
            $stmt->bind_param('i', $eventId); $stmt->execute(); $stmt->close();
        } else {
            $stmt = $conn->prepare("UPDATE tbl_events SET is_active = 0 WHERE event_id = ?");
            $stmt->bind_param('i', $eventId); $stmt->execute(); $stmt->close();
        }
    }

    $after = get_event($conn, $eventId);
    $fields = ['event_name','year','description','is_active','nomination_start','nomination_end','voting_start','voting_end'];
    $changed = build_changed($before, $after, $fields);

    audit_log($conn, [
        'module'=>'events','entity_type'=>'event','entity_id'=>$eventId,'action'=>'update',
        'details'=>['event_id'=>$eventId,'old'=>$before,'new'=>$after,'diff'=>['changed'=>$changed]]
    ]);
    $nomPeriodDefined = !empty($after['nomination_start']) || !empty($after['nomination_end']);
    $needsFormSetup = $nomPeriodDefined && !nomination_form_configured($conn);

    out([
        'status' => 'success',
        'message' => 'Event updated successfully.',
        'needs_form_setup' => $needsFormSetup,
    ]);
}

out_error('Invalid action.', 400);
