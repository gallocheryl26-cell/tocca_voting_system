<?php
declare(strict_types=1);
require dirname(__DIR__) . '/tocca_admin/db_connection.php';
require dirname(__DIR__) . '/tocca_admin/includes/voters_list_data.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "NO_DB\n");
    exit(1);
}

$event = $conn->query("SELECT event_id, event_name, nomination_start, nomination_end, voting_start, voting_end FROM tbl_events WHERE is_active = 1 AND COALESCE(is_archived,0)=0 ORDER BY year DESC, event_id DESC LIMIT 1")->fetch_assoc();
if (!$event) {
    echo "NO_EVENT\n";
    exit(0);
}
$eventId = (int) $event['event_id'];
echo "EVENT {$eventId} {$event['event_name']}\n";
echo "NOM {$event['nomination_start']} .. {$event['nomination_end']}\n";
echo "VOTE {$event['voting_start']} .. {$event['voting_end']}\n";

$totalVoters = (int) $conn->query("SELECT COUNT(*) c FROM tbl_voters WHERE COALESCE(is_archived,0)=0")->fetch_assoc()['c'];
$hasVoted = (int) $conn->query("SELECT COUNT(*) c FROM tbl_voters WHERE COALESCE(is_archived,0)=0 AND has_voted=1")->fetch_assoc()['c'];
$withChoice = (int) $conn->query("SELECT COUNT(DISTINCT pc.voters_id) c FROM tbl_poll_choice pc JOIN tbl_questions q ON q.question_id=pc.question_id JOIN tbl_categories c ON c.category_id=q.category_id WHERE c.event_id={$eventId}")->fetch_assoc()['c'];
$withText = (int) $conn->query("SELECT COUNT(DISTINCT pf.voters_id) c FROM tbl_poll_freetext pf JOIN tbl_questions q ON q.question_id=pf.question_id JOIN tbl_categories c ON c.category_id=q.category_id WHERE c.event_id={$eventId}")->fetch_assoc()['c'];
$dual = (int) $conn->query("SELECT COUNT(*) c FROM (SELECT pc.voters_id, pc.question_id FROM tbl_poll_choice pc JOIN tbl_questions q ON q.question_id=pc.question_id JOIN tbl_categories c ON c.category_id=q.category_id WHERE c.event_id={$eventId} INTERSECT SELECT pf.voters_id, pf.question_id FROM tbl_poll_freetext pf JOIN tbl_questions q ON q.question_id=pf.question_id JOIN tbl_categories c ON c.category_id=q.category_id WHERE c.event_id={$eventId}) x")->fetch_assoc()['c'];

echo "voters_not_archived={$totalVoters} has_voted_flag={$hasVoted} cast_choice_voters={$withChoice} cast_freetext_voters={$withText} choice_and_freetext_pairs={$dual}\n";

$rows = voters_list_rows_for_event($conn, $eventId);
$counts = ['completed' => 0, 'drafted' => 0, 'not started' => 0];
foreach ($rows as $row) {
    $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
}
echo 'report_rows=' . count($rows) . ' ' . json_encode($counts) . "\n";
echo 'required_questions=' . voter_flow_count_required_questions($conn, $eventId) . "\n";
$voterId = (int) ($rows[0]['voters_id'] ?? 0);
if ($voterId > 0) {
    echo 'voter=' . $voterId
        . ' finalized=' . voter_flow_count_finalized_questions($conn, $voterId, $eventId)
        . ' status=' . $rows[0]['status'] . "\n";
    $act = $conn->query("SELECT COUNT(*) c FROM (
        SELECT pc.question_id FROM tbl_poll_choice pc JOIN tbl_questions q ON q.question_id=pc.question_id JOIN tbl_categories c ON c.category_id=q.category_id WHERE pc.voters_id={$voterId} AND c.event_id={$eventId}
        UNION ALL
        SELECT pf.question_id FROM tbl_poll_freetext pf JOIN tbl_questions q ON q.question_id=pf.question_id JOIN tbl_categories c ON c.category_id=q.category_id WHERE pf.voters_id={$voterId} AND c.event_id={$eventId}
    ) a")->fetch_assoc()['c'];
    $uniq = $conn->query("SELECT COUNT(*) c FROM (
        SELECT pc.question_id FROM tbl_poll_choice pc JOIN tbl_questions q ON q.question_id=pc.question_id JOIN tbl_categories c ON c.category_id=q.category_id WHERE pc.voters_id={$voterId} AND c.event_id={$eventId}
        UNION
        SELECT pf.question_id FROM tbl_poll_freetext pf JOIN tbl_questions q ON q.question_id=pf.question_id JOIN tbl_categories c ON c.category_id=q.category_id WHERE pf.voters_id={$voterId} AND c.event_id={$eventId}
    ) a")->fetch_assoc()['c'];
    echo "activity_rows={$act} unique_awards={$uniq}\n";
}
