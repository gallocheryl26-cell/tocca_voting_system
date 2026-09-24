<?php
declare(strict_types=1);

/**
 * Local test data: 15 Café registrations for event 7, statuses, then TWG scores.
 * Re-run with --reset to replace a previous TWG Drill Café set.
 */
define('TOCCA_SKIP_NOMINATION_ROUTER', true);

require_once __DIR__ . '/../tocca_admin/session_bootstrap.php';
$_SESSION['loggedin'] = true;
$_SESSION['username'] = $_SESSION['username'] ?? 'seed';

require_once __DIR__ . '/../tocca_admin/nomination.php';
require_once __DIR__ . '/../nomination/nomination_field_helpers.php';
require_once __DIR__ . '/../tocca_admin/includes/award_entry_helpers.php';
require_once __DIR__ . '/../tocca_admin/includes/establishment_type_event_helpers.php';
require_once __DIR__ . '/../tocca_admin/includes/results_formula.php';

$reset = in_array('--reset', $argv ?? [], true);
$eventId = 7;
$typeId = 4; // Café
$prefix = 'TWG Drill Café';

$awardIds = [77, 72, 65, 138, 139, 140]; // 3 business + 3 product titles
$productAwards = [138, 139, 140];
$kindByQuestion = [
    138 => 'product',
    139 => 'product',
    140 => 'product',
];

function seed_ref(mysqli $conn): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $ref = 'REG-2026-' . $suffix;
        $st = $conn->prepare('SELECT 1 FROM tbl_nominations WHERE reference_no = ? LIMIT 1');
        $st->bind_param('s', $ref);
        $st->execute();
        $exists = (bool) $st->get_result()->fetch_row();
        $st->close();
        if (!$exists) {
            return $ref;
        }
    }
    return 'REG-2026-' . strtoupper(bin2hex(random_bytes(3)));
}

function seed_existing_ids(mysqli $conn, string $prefix): array
{
    $like = $prefix . '%';
    $st = $conn->prepare(
        "SELECT n.nomination_id
         FROM tbl_nominations n
         JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id AND a.field_id = 24
         WHERE n.event_id = 7 AND a.answer LIKE ?
         ORDER BY n.nomination_id"
    );
    $st->bind_param('s', $like);
    $st->execute();
    $ids = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['nomination_id'];
    }
    $st->close();
    return $ids;
}

if ($reset) {
    $old = seed_existing_ids($conn, $prefix);
    foreach ($old as $nid) {
        $st = $conn->prepare('SELECT merged_choice_id FROM tbl_nominations WHERE nomination_id=?');
        $st->bind_param('i', $nid);
        $st->execute();
        $cid = (int) ($st->get_result()->fetch_assoc()['merged_choice_id'] ?? 0);
        $st->close();
        if ($cid > 0) {
            $conn->query('DELETE FROM tbl_twg_member_scores WHERE choice_id=' . $cid);
            $conn->query('DELETE FROM tbl_twg_scores WHERE choice_id=' . $cid);
            $conn->query('DELETE FROM tbl_question_choices WHERE choice_id=' . $cid);
            if ($conn->query("SHOW TABLES LIKE 'tbl_award_ballot_entries'")->num_rows) {
                $conn->query('DELETE FROM tbl_award_ballot_entries WHERE choice_id=' . $cid);
            }
            $conn->query('DELETE FROM tbl_choices WHERE choice_id=' . $cid);
        }
        $conn->query('DELETE FROM tbl_nomination_award_entries WHERE nomination_id=' . $nid);
        $conn->query('DELETE FROM tbl_nomination_questions WHERE nomination_id=' . $nid);
        $conn->query('DELETE FROM tbl_nomination_answers WHERE nomination_id=' . $nid);
        $conn->query('DELETE FROM tbl_nomination_establishment_types WHERE nomination_id=' . $nid);
        $conn->query('DELETE FROM tbl_nominations WHERE nomination_id=' . $nid);
        echo "reset deleted nomination {$nid}\n";
    }
}

$existing = seed_existing_ids($conn, $prefix);
if (count($existing) >= 15) {
    echo "Already have " . count($existing) . " {$prefix} registrations. Use --reset to rebuild.\n";
    $created = $existing;
} else {
    $created = [];
    for ($n = 1; $n <= 15; $n++) {
        $num = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        $biz = $prefix . ' ' . $num;
        $st = $conn->prepare(
            'INSERT INTO tbl_nominations (event_id, establishment_type_id, status) VALUES (?, ?, \'pending\')'
        );
        $st->bind_param('ii', $eventId, $typeId);
        $st->execute();
        $nid = (int) $st->insert_id;
        $st->close();

        $ref = seed_ref($conn);
        $u = $conn->prepare('UPDATE tbl_nominations SET reference_no=? WHERE nomination_id=?');
        $u->bind_param('si', $ref, $nid);
        $u->execute();
        $u->close();

        et_set_nomination_types($conn, $nid, [$typeId]);

        $answers = [
            24 => $biz,
            25 => 'Owner ' . $num,
            26 => '0917' . str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT),
            29 => sprintf('twg.drill.%02d@example.com', $n),
            27 => 'Sole Proprietorship',
            31 => 'Real Street, Brgy. District 8, Ormoc City, Leyte 6541',
            30 => 'https://example.com/twg-drill-' . $num,
        ];
        foreach ($answers as $fid => $val) {
            nf_upsert_nomination_answer($conn, $nid, (int) $fid, (string) $val);
        }

        set_questions_for_nomination($conn, $nid, $awardIds);
        $entries = [];
        foreach ($productAwards as $qid) {
            if ($qid === 139) {
                $entries[$qid] = [$biz . ' Date Tart'];
            } elseif ($qid === 140) {
                $entries[$qid] = [$biz . ' Happy Shake'];
            } else {
                $entries[$qid] = [$biz . ' Comfort Soup'];
            }
        }
        award_entry_set_for_nomination($conn, $nid, $entries, $kindByQuestion);
        $created[] = $nid;
        echo "created {$nid} {$biz} {$ref}\n";
    }
}

if (count($created) < 15) {
    fwrite(STDERR, "Expected 15 registrations, got " . count($created) . "\n");
    exit(1);
}

$approveIds = array_slice($created, 0, 11);
$needsIds = array_slice($created, 11, 2);
$rejectStay = $created[13];
$rejectThenReopen = $created[14];

foreach ($approveIds as $nid) {
    $choiceId = approve_nomination($conn, $nid);
    echo "approved {$nid} choice={$choiceId}\n";
}

foreach ($needsIds as $nid) {
    $st = $conn->prepare("UPDATE tbl_nominations SET status='needs_info', updated_at=NOW() WHERE nomination_id=?");
    $st->bind_param('i', $nid);
    $st->execute();
    $st->close();
    log_audit($conn, $nid, 'needs_info', 'Seed needs_info');
    echo "needs_info {$nid}\n";
}

$st = $conn->prepare("UPDATE tbl_nominations SET status='rejected', updated_at=NOW() WHERE nomination_id=?");
$st->bind_param('i', $rejectStay);
$st->execute();
$st->close();
log_audit($conn, $rejectStay, 'reject', 'Seed reject stay');
echo "rejected {$rejectStay}\n";

$st = $conn->prepare("UPDATE tbl_nominations SET status='rejected', updated_at=NOW() WHERE nomination_id=?");
$st->bind_param('i', $rejectThenReopen);
$st->execute();
$st->close();
log_audit($conn, $rejectThenReopen, 'reject', 'Seed reject then reopen');
echo "rejected {$rejectThenReopen} (will reopen)\n";

$st = $conn->prepare("UPDATE tbl_nominations SET status='in_review', updated_at=NOW() WHERE nomination_id=?");
$st->bind_param('i', $rejectThenReopen);
$st->execute();
$st->close();
log_audit($conn, $rejectThenReopen, 'reopen', 'Seed reopen to in_review');
echo "reopened {$rejectThenReopen}\n";

$reopenChoice = approve_nomination($conn, $rejectThenReopen);
echo "approved reopened {$rejectThenReopen} choice={$reopenChoice}\n";

$scoredNoms = array_merge($approveIds, [$rejectThenReopen]);
$members = twg_member_keys();
$scoredChoices = [];
foreach ($scoredNoms as $idx => $nid) {
    $st = $conn->prepare('SELECT merged_choice_id FROM tbl_nominations WHERE nomination_id=?');
    $st->bind_param('i', $nid);
    $st->execute();
    $cid = (int) ($st->get_result()->fetch_assoc()['merged_choice_id'] ?? 0);
    $st->close();
    if ($cid <= 0) {
        echo "skip score nom {$nid} no choice\n";
        continue;
    }
    $qids = get_questions_for_nomination($conn, $nid);
    $base = 9.80 - ($idx * 0.45); // 9.80 ... down
    $rows = [];
    foreach ($qids as $qid) {
        foreach ($members as $m => $key) {
            $jitter = ($m - 2) * 0.04;
            $score = round(min(10, max(1, $base + $jitter)), 2);
            $rows[] = [
                'question_id' => $qid,
                'choice_id' => $cid,
                'member_key' => $key,
                'score' => $score,
            ];
        }
    }
    $result = twg_save_sheet($conn, $cid, $rows);
    echo "scored choice {$cid} nom {$nid} avg~{$base} saved={$result['saved']} ok=" . (!empty($result['ok']) ? '1' : '0') . ' ' . ($result['message'] ?? '') . "\n";
    $scoredChoices[] = ['nomination_id' => $nid, 'choice_id' => $cid, 'target_avg' => $base];
}

echo "=== summary ===\n";
echo json_encode([
    'approve' => $approveIds,
    'needs_info' => $needsIds,
    'rejected' => $rejectStay,
    'reopened_then_approved' => $rejectThenReopen,
    'scored' => $scoredChoices,
], JSON_PRETTY_PRINT) . PHP_EOL;
