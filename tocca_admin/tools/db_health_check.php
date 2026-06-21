<?php
declare(strict_types=1);
/**
 * One-off DB health snapshot (CLI: php tocca_admin/tools/db_health_check.php)
 */
require_once dirname(__DIR__) . '/db_connection.php';

$issues = [];
$info = [];

$r = $conn->query('SELECT DATABASE() AS db');
$info['database'] = (string) ($r->fetch_assoc()['db'] ?? '');

$tables = [];
$res = $conn->query('SHOW TABLES');
while ($row = $res->fetch_array(MYSQLI_NUM)) {
    $tables[] = $row[0];
}
$info['table_count'] = count($tables);

// Multiple active events
$res = $conn->query('SELECT COUNT(*) AS c FROM tbl_events WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0');
$activeEvents = (int) ($res->fetch_assoc()['c'] ?? 0);
$info['active_events'] = $activeEvents;
if ($activeEvents > 1) {
    $issues[] = "Multiple active events ($activeEvents). Only one should be active.";
}
if ($activeEvents === 0) {
    $issues[] = 'No active event.';
}

// Nominations without event
if (in_array('tbl_nominations', $tables, true)) {
    $res = $conn->query('SELECT COUNT(*) AS c FROM tbl_nominations WHERE event_id IS NULL OR event_id = 0');
    $orphNom = (int) ($res->fetch_assoc()['c'] ?? 0);
    $info['nominations_missing_event'] = $orphNom;
    if ($orphNom > 0) {
        $issues[] = "$orphNom nomination(s) missing event_id.";
    }
}

// Nominations pointing to missing events
$res = $conn->query(
    'SELECT COUNT(*) AS c FROM tbl_nominations n
     LEFT JOIN tbl_events e ON e.event_id = n.event_id
     WHERE n.event_id IS NOT NULL AND n.event_id > 0 AND e.event_id IS NULL'
);
$badNomEvent = (int) ($res->fetch_assoc()['c'] ?? 0);
$info['nominations_bad_event'] = $badNomEvent;
if ($badNomEvent > 0) {
    $issues[] = "$badNomEvent nomination(s) reference a non-existent event.";
}

// Choices schema varies by install — skip strict FK check unless question_id exists
if (in_array('tbl_choices', $tables, true)) {
    $cols = [];
    $dc = $conn->query('SHOW COLUMNS FROM tbl_choices');
    while ($c = $dc->fetch_assoc()) {
        $cols[] = (string) $c['Field'];
    }
    if (in_array('question_id', $cols, true)) {
        $res = $conn->query(
            'SELECT COUNT(*) AS c FROM tbl_choices ch
             LEFT JOIN tbl_questions q ON q.question_id = ch.question_id
             WHERE ch.question_id IS NOT NULL AND ch.question_id > 0 AND q.question_id IS NULL'
        );
        $orphChoices = (int) ($res->fetch_assoc()['c'] ?? 0);
        $info['choices_bad_question'] = $orphChoices;
        if ($orphChoices > 0) {
            $issues[] = "$orphChoices choice(s) reference missing question.";
        }
    }
}

// Row counts (sanity)
$countTables = [
    'tbl_events', 'tbl_nominations', 'tbl_categories', 'tbl_questions',
    'tbl_choices', 'tbl_voters', 'tbl_poll_choice', 'tbl_admin_audit_log',
];
foreach ($countTables as $t) {
    if (!in_array($t, $tables, true)) {
        continue;
    }
    $res = $conn->query("SELECT COUNT(*) AS c FROM `$t`");
    $info['rows'][$t] = (int) ($res->fetch_assoc()['c'] ?? 0);
}

// Pending schema migrations (optional tables from db/migrations)
$expectedOptional = ['tbl_choice_tokens'];
foreach ($expectedOptional as $t) {
    if (!in_array($t, $tables, true)) {
        $info['optional_missing'][] = $t;
    }
}

// Duplicate voter mobile (if column exists)
if (in_array('tbl_voters', $tables, true)) {
    $vcols = [];
    $vc = $conn->query('SHOW COLUMNS FROM tbl_voters');
    while ($c = $vc->fetch_assoc()) {
        $vcols[] = (string) $c['Field'];
    }
    if (in_array('mobile', $vcols, true)) {
        $res = $conn->query(
            "SELECT COUNT(*) AS c FROM (
                SELECT mobile FROM tbl_voters
                WHERE mobile IS NOT NULL AND TRIM(mobile) <> ''
                GROUP BY mobile HAVING COUNT(*) > 1
            ) d"
        );
        $dupMobile = (int) ($res->fetch_assoc()['c'] ?? 0);
        $info['duplicate_voter_mobiles'] = $dupMobile;
        if ($dupMobile > 0) {
            $issues[] = "$dupMobile duplicate voter mobile number group(s).";
        }
    }
}

// Active event schedule completeness
$res = $conn->query(
    'SELECT event_id, event_name, nomination_start, nomination_end, voting_start, voting_end
     FROM tbl_events WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0 LIMIT 1'
);
if ($ev = $res->fetch_assoc()) {
    $info['active_event'] = [
        'id' => (int) $ev['event_id'],
        'name' => (string) $ev['event_name'],
    ];
    foreach (['nomination_start', 'nomination_end', 'voting_start', 'voting_end'] as $f) {
        if (empty($ev[$f]) || $ev[$f] === '0000-00-00 00:00:00') {
            $issues[] = "Active event missing or invalid $f.";
        }
    }
}

echo json_encode([
    'status' => empty($issues) ? 'ok' : 'issues',
    'issues' => $issues,
    'info' => $info,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
