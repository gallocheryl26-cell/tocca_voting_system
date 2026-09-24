<?php
/**
 * One-off status correction for Kutaw / Figaro / Hunger Wings / Bigbys.
 * CLI only. Dry-run by default. Pass --apply to write.
 *
 * On Hostinger: upload this file, SSH/Terminal:
 *   php fix_registration_status.php
 *   php fix_registration_status.php --apply
 * then delete this file.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);

define('TOCCA_SKIP_NOMINATION_ROUTER', true);
define('TOCCA_ALLOW_DB_FAIL', true);

require_once dirname(__DIR__) . '/tocca_admin/session_bootstrap.php';
$_SESSION['loggedin'] = true;
require_once dirname(__DIR__) . '/tocca_admin/nomination.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "NO_DB\n");
    exit(2);
}

/**
 * @return list<array<string,mixed>>
 */
function find_noms_by_needle(mysqli $conn, string $needle): array
{
    $like = '%' . $conn->real_escape_string($needle) . '%';
    $sql = "
        SELECT n.nomination_id, n.status, n.event_id, n.merged_choice_id, a.answer AS business_name,
               c.choice_name, c.status AS choice_status, c.on_ballot
        FROM tbl_nominations n
        INNER JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id
        INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
            AND f.name IN ('business_name','official_business_name','company','company_name','business')
        LEFT JOIN tbl_choices c ON c.choice_id = n.merged_choice_id
        WHERE a.answer LIKE '{$like}'
        ORDER BY n.nomination_id
    ";
    $res = $conn->query($sql);
    $out = [];
    $seen = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $id = (int) $row['nomination_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $row;
        }
    }
    $res2 = $conn->query("
        SELECT choice_id, choice_name, status, on_ballot, event_id
        FROM tbl_choices
        WHERE choice_name LIKE '{$like}'
        ORDER BY choice_id
    ");
    $choices = [];
    if ($res2) {
        while ($row = $res2->fetch_assoc()) {
            $choices[] = $row;
        }
    }
    return ['noms' => $out, 'choices' => $choices];
}

function print_hits(string $label, array $found): void
{
    echo "=== {$label} ===\n";
    if ($found['noms'] === [] && $found['choices'] === []) {
        echo "(no match in this database)\n\n";
        return;
    }
    foreach ($found['noms'] as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
    foreach ($found['choices'] as $row) {
        echo 'choice: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\n";
}

$targets = [
    'reject' => [
        'Kutaw' => 'kutaw',
        'Figaro' => 'figaro',
        'Hunger Wings' => 'hunger',
    ],
    'approve' => [
        'Bigbys' => 'bigby',
    ],
];

echo $apply ? "MODE=APPLY\n\n" : "MODE=DRY-RUN (pass --apply to write)\n\n";

foreach ($targets['reject'] as $label => $needle) {
    print_hits($label, find_noms_by_needle($conn, $needle));
}
foreach ($targets['approve'] as $label => $needle) {
    print_hits($label, find_noms_by_needle($conn, $needle));
}

if (!$apply) {
    echo "No rows changed.\n";
    exit(0);
}

$evt = fetch_active_or_latest_event($conn);
if ($evt && !empty($evt['voting_start']) && is_voting_open_for_event($evt)) {
    fwrite(STDERR, "Voting is open; refusing status changes.\n");
    exit(3);
}

foreach ($targets['reject'] as $label => $needle) {
    $found = find_noms_by_needle($conn, $needle);
    if (count($found['noms']) !== 1) {
        echo "SKIP reject {$label}: expected 1 nomination, found " . count($found['noms']) . "\n";
        continue;
    }
    $nom = $found['noms'][0];
    $id = (int) $nom['nomination_id'];
    $prev = strtolower((string) $nom['status']);
    if ($prev === 'rejected') {
        echo "OK {$label} already rejected (nomination_id={$id})\n";
        continue;
    }
    $st = $conn->prepare("UPDATE tbl_nominations SET status='rejected', updated_at=NOW() WHERE nomination_id=?");
    $st->bind_param('i', $id);
    $st->execute();
    $st->close();
    $choiceId = (int) ($nom['merged_choice_id'] ?? 0);
    if ($choiceId > 0) {
        maybe_deactivate_choice($conn, $choiceId, $id);
    }
    log_audit($conn, $id, 'reject', "CLI reject (was {$prev}; new business)");
    echo "REJECTED {$label} nomination_id={$id} choice_id={$choiceId} (was {$prev})\n";
}

foreach ($targets['approve'] as $label => $needle) {
    $found = find_noms_by_needle($conn, $needle);
    if (count($found['noms']) !== 1) {
        echo "SKIP approve {$label}: expected 1 nomination, found " . count($found['noms']) . "\n";
        continue;
    }
    $nom = $found['noms'][0];
    $id = (int) $nom['nomination_id'];
    $prev = strtolower((string) $nom['status']);
    if (in_array($prev, ['approved', 'merged'], true)) {
        $choiceId = (int) ($nom['merged_choice_id'] ?? 0);
        if ($choiceId > 0) {
            $st = $conn->prepare('UPDATE tbl_choices SET status=1 WHERE choice_id=?');
            $st->bind_param('i', $choiceId);
            $st->execute();
            $st->close();
        }
        echo "OK {$label} already {$prev} (nomination_id={$id})\n";
        continue;
    }
    if ($prev === 'rejected') {
        $st = $conn->prepare("UPDATE tbl_nominations SET status='in_review', updated_at=NOW() WHERE nomination_id=?");
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        log_audit($conn, $id, 'reopen', 'CLI reopen before approve');
    }
    $choiceId = approve_nomination($conn, $id, null);
    echo "APPROVED {$label} nomination_id={$id} choice_id={$choiceId} (was {$prev})\n";
}

echo "\nDone.\n";
