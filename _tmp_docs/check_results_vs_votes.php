<?php
declare(strict_types=1);

require dirname(__DIR__) . '/tocca_admin/db_connection.php';
require dirname(__DIR__) . '/tocca_admin/includes/results_formula.php';
require dirname(__DIR__) . '/tocca_admin/includes/award_answer_fields.php';

date_default_timezone_set('Asia/Manila');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function line(string $s = ''): void
{
    echo $s . PHP_EOL;
}

$ev = $conn->query(
    "SELECT event_id, event_name, year, is_active, voting_start, voting_end
     FROM tbl_events
     WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0
     ORDER BY year DESC, event_id DESC LIMIT 1"
)->fetch_assoc();
if (!$ev) {
    fwrite(STDERR, "No active event\n");
    exit(1);
}
$eventId = (int) $ev['event_id'];
line('EVENT ' . $eventId . ' ' . $ev['event_name'] . ' year=' . $ev['year']);
line('voting ' . $ev['voting_start'] . ' → ' . $ev['voting_end']);
line();

$hasBallot = false;
$r = $conn->query("SHOW COLUMNS FROM tbl_poll_choice LIKE 'ballot_entry_id'");
if ($r && $r->num_rows > 0) {
    $hasBallot = true;
}

line('=== CAST VOTES (tbl_poll_choice) ===');
$choiceSql = "
    SELECT pc.voters_id, v.mobile_number, v.has_voted,
           c.category_name, q.question_id, q.question_name, q.answer_fields, q.choice_type,
           ch.choice_id, ch.choice_name,
           " . ($hasBallot ? 'pc.ballot_entry_id' : 'NULL AS ballot_entry_id') . ",
           pc.vote_at
    FROM tbl_poll_choice pc
    JOIN tbl_questions q ON q.question_id = pc.question_id
    JOIN tbl_categories c ON c.category_id = q.category_id
    JOIN tbl_choices ch ON ch.choice_id = pc.choice_id
    JOIN tbl_voters v ON v.voters_id = pc.voters_id
    WHERE c.event_id = ?
    ORDER BY pc.vote_at DESC, c.category_name, q.question_name
";
$st = $conn->prepare($choiceSql);
$st->bind_param('i', $eventId);
$st->execute();
$choiceRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
line('rows=' . count($choiceRows));
foreach ($choiceRows as $row) {
    $entry = '';
    $eid = (int) ($row['ballot_entry_id'] ?? 0);
    if ($eid > 0) {
        $en = $conn->query('SELECT entry_name FROM tbl_award_ballot_entries WHERE ballot_entry_id = ' . $eid)->fetch_assoc();
        $entry = ' entry=' . ($en['entry_name'] ?? ('#' . $eid));
    }
    line(sprintf(
        '  %s | %s | %s | %s | voter %s %s has_voted=%s%s',
        $row['vote_at'],
        $row['category_name'],
        $row['question_name'],
        $row['choice_name'],
        $row['voters_id'],
        $row['mobile_number'],
        $row['has_voted'],
        $entry
    ));
}
line();

line('=== CAST FREETEXT (tbl_poll_freetext) ===');
$st = $conn->prepare(
    "SELECT pf.voters_id, v.mobile_number, v.has_voted,
            c.category_name, q.question_id, q.question_name, q.answer_fields, q.choice_type,
            pf.freetext, pf.vote_at
     FROM tbl_poll_freetext pf
     JOIN tbl_questions q ON q.question_id = pf.question_id
     JOIN tbl_categories c ON c.category_id = q.category_id
     JOIN tbl_voters v ON v.voters_id = pf.voters_id
     WHERE c.event_id = ?
     ORDER BY pf.vote_at DESC"
);
$st->bind_param('i', $eventId);
$st->execute();
$ftRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
line('rows=' . count($ftRows));
foreach ($ftRows as $row) {
    $open = award_answer_fields_uses_open_text($row['answer_fields'] ?? null) ? 'COUNTED_IN_RESULTS' : 'DISPLAY_COPY_ONLY';
    line(sprintf(
        '  %s | %s | %s | [%s] | %s | voter %s %s | fields=%s',
        $row['vote_at'],
        $row['category_name'],
        $row['question_name'],
        $open,
        $row['freetext'],
        $row['voters_id'],
        $row['mobile_number'],
        $row['answer_fields'] ?? ''
    ));
}
line();

line('=== DRAFTS (should NOT appear in Results) ===');
$draftChoice = $conn->query(
    "SELECT COUNT(*) AS n FROM tbl_draft_choice dc
     JOIN tbl_questions q ON q.question_id = dc.question_id
     JOIN tbl_categories c ON c.category_id = q.category_id
     WHERE c.event_id = {$eventId}"
)->fetch_assoc();
$draftFt = $conn->query(
    "SELECT COUNT(*) AS n FROM tbl_draft_freetext df
     JOIN tbl_questions q ON q.question_id = df.question_id
     JOIN tbl_categories c ON c.category_id = q.category_id
     WHERE c.event_id = {$eventId}"
)->fetch_assoc();
line('draft_choice=' . (int) ($draftChoice['n'] ?? 0) . ' draft_freetext=' . (int) ($draftFt['n'] ?? 0));
line();

$qids = [];
foreach ($choiceRows as $row) {
    $qids[(int) $row['question_id']] = true;
}
foreach ($ftRows as $row) {
    $qids[(int) $row['question_id']] = true;
}

line('=== RESULTS PAGE (same formula as admin Results) ===');
$issues = [];
foreach (array_keys($qids) as $qid) {
    $payload = results_formula_fetch_for_award($conn, $eventId, $qid);
    $qname = '';
    foreach (array_merge($choiceRows, $ftRows) as $row) {
        if ((int) $row['question_id'] === $qid) {
            $qname = $row['category_name'] . ' / ' . $row['question_name'];
            break;
        }
    }
    line($qname . ' (question_id=' . $qid . ') total_votes=' . (int) ($payload['total_votes'] ?? 0));
    $rawChoice = 0;
    $rawVoters = [];
    foreach ($choiceRows as $row) {
        if ((int) $row['question_id'] !== $qid) {
            continue;
        }
        $rawChoice++;
        $rawVoters[(int) $row['voters_id']] = true;
    }
    $rawFtCounted = 0;
    $rawFtCopy = 0;
    foreach ($ftRows as $row) {
        if ((int) $row['question_id'] !== $qid) {
            continue;
        }
        if (award_answer_fields_uses_open_text($row['answer_fields'] ?? null)) {
            $rawFtCounted++;
        } else {
            $rawFtCopy++;
        }
    }
    $expected = count($rawVoters) + $rawFtCounted;
    // Distinct voters in poll_choice plus song freetext. Named-entry copies should not add.
    // A voter can have BOTH choice and song freetext on different awards only.
    // For one award: either choice XOR song.
    $adminTotal = (int) ($payload['total_votes'] ?? 0);
    line('  raw poll_choice rows=' . $rawChoice . ' distinct_voters=' . count($rawVoters)
        . ' song_freetext=' . $rawFtCounted . ' named_display_copies=' . $rawFtCopy);
    line('  expected unique votes≈' . $expected . ' admin_total_votes=' . $adminTotal);
    if ($adminTotal !== $expected && $rawFtCopy === 0) {
        $issues[] = $qname . ' total mismatch expected ' . $expected . ' got ' . $adminTotal;
    }
    if ($rawFtCopy > 0 && $adminTotal === ($expected + $rawFtCopy)) {
        $issues[] = $qname . ' DOUBLE-COUNT: named display freetext added to results';
    }
    foreach ($payload['results'] as $row) {
        if ((int) ($row['vote_count'] ?? 0) <= 0) {
            continue;
        }
        $entries = implode(', ', array_filter((array) ($row['entry_names'] ?? [])));
        line(sprintf(
            '  rank %s | %s | votes=%s share=%s community=%s twg=%s final=%s%s%s',
            $row['rank'] ?? '',
            $row['choice_name'] ?? '',
            $row['vote_count'] ?? 0,
            $row['vote_share'] ?? 0,
            $row['community_score'] ?? 0,
            $row['twg_average'] === null ? '—' : $row['twg_average'],
            $row['final_score'] ?? 0,
            !empty($row['is_freetext']) ? ' [typed song]' : '',
            $entries !== '' ? (' | listed entries: ' . $entries) : ''
        ));
    }
    line();
}

line('=== DASHBOARD vs RESULTS (named-entry double count risk) ===');
$dashChoice = $conn->query(
    "SELECT COUNT(*) AS n FROM tbl_poll_choice pc
     JOIN tbl_questions q ON q.question_id = pc.question_id
     JOIN tbl_categories c ON c.category_id = q.category_id
     WHERE c.event_id = {$eventId}"
)->fetch_assoc();
$dashFt = $conn->query(
    "SELECT COUNT(*) AS n FROM tbl_poll_freetext pf
     JOIN tbl_questions q ON q.question_id = pf.question_id
     JOIN tbl_categories c ON c.category_id = q.category_id
     WHERE c.event_id = {$eventId}"
)->fetch_assoc();
$dashTotal = (int) ($dashChoice['n'] ?? 0) + (int) ($dashFt['n'] ?? 0);
$uniqueChoice = $conn->query(
    "SELECT COUNT(*) AS n FROM (
        SELECT DISTINCT pc.voters_id, pc.question_id
        FROM tbl_poll_choice pc
        JOIN tbl_questions q ON q.question_id = pc.question_id
        JOIN tbl_categories c ON c.category_id = q.category_id
        WHERE c.event_id = {$eventId}
     ) t"
)->fetch_assoc();
line('dashboard total_votes (choice+freetext COUNT *)=' . $dashTotal);
line('unique voter+award in poll_choice=' . (int) ($uniqueChoice['n'] ?? 0));
line('display freetext copies=' . count(array_filter($ftRows, static function ($row) {
    return !award_answer_fields_uses_open_text($row['answer_fields'] ?? null);
})));
line();

if ($issues === []) {
    line('ISSUES: none in Results formula vs unique poll_choice / song freetext.');
} else {
    line('ISSUES:');
    foreach ($issues as $issue) {
        line('  - ' . $issue);
    }
}
