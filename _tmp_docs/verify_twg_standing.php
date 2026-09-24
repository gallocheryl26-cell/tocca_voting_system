<?php
declare(strict_types=1);
require __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/includes/twg_ballot.php';

$checks = [
    14 => 'expect Top 5',
    18 => 'expect Top 5 (5th)',
    19 => 'expect NOT Top 5 (6th)',
    27 => 'rejected, no choice',
    25 => 'needs_info, no choice',
    28 => 'reopened+approved, low score NOT Top 5',
];

foreach ($checks as $nid => $note) {
    $st = $conn->prepare('SELECT status, merged_choice_id FROM tbl_nominations WHERE nomination_id=?');
    $st->bind_param('i', $nid);
    $st->execute();
    $nom = $st->get_result()->fetch_assoc();
    $st->close();
    $cid = (int) ($nom['merged_choice_id'] ?? 0);
    echo "nom {$nid} status={$nom['status']} choice={$cid} ({$note})\n";
    if ($cid <= 0) {
        continue;
    }
    $elig = twg_ballot_eligibility_for_choice($conn, $cid);
    echo "  remaining={$elig['remaining_count']} graded={$elig['graded_count']} all={$elig['all_graded']} top5=" . count($elig['top10']) . " not=" . count($elig['not_top10']) . "\n";
    foreach ($elig['awards'] as $a) {
        $flag = !empty($a['in_top5']) || !empty($a['in_top10']) ? 'TOP5' : 'OUT';
        echo "  {$flag} #{$a['twg_rank']} {$a['question_name']} avg={$a['twg_average']}\n";
    }
}
