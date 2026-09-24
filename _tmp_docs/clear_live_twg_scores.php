<?php
/**
 * Remove TWG scores for the active event only.
 * Does not touch businesses, nominations, award links, votes, or event dates.
 *
 * CLI only. Dry-run by default.
 *   php clear_live_twg_scores.php
 *   php clear_live_twg_scores.php --apply
 * Delete this file when finished.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/tocca_admin/db_connection.php';
require_once dirname(__DIR__) . '/tocca_admin/includes/admin_active_event.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "NO_DB\n");
    exit(2);
}

$eventId = admin_get_active_event_id($conn);
if ($eventId === null || $eventId <= 0) {
    fwrite(STDERR, "No active event.\n");
    exit(3);
}
$label = admin_get_active_event_label($conn, $eventId);

function table_exists(mysqli $conn, string $table): bool
{
    $esc = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$esc}'");
    $ok = $res && $res->num_rows > 0;
    if ($res) {
        $res->close();
    }
    return $ok;
}

function count_event_rows(mysqli $conn, string $table, int $eventId): int
{
    $sql = "SELECT COUNT(*) AS n
            FROM `{$table}` t
            INNER JOIN tbl_questions q ON q.question_id = t.question_id
            INNER JOIN tbl_categories cat ON cat.category_id = q.category_id
            WHERE cat.event_id = ?";
    $st = $conn->prepare($sql);
    if (!$st) {
        return 0;
    }
    $st->bind_param('i', $eventId);
    $st->execute();
    $n = (int) (($st->get_result()->fetch_assoc()['n'] ?? 0));
    $st->close();
    return $n;
}

function delete_event_rows(mysqli $conn, string $table, int $eventId): int
{
    $sql = "DELETE t
            FROM `{$table}` t
            INNER JOIN tbl_questions q ON q.question_id = t.question_id
            INNER JOIN tbl_categories cat ON cat.category_id = q.category_id
            WHERE cat.event_id = ?";
    $st = $conn->prepare($sql);
    if (!$st) {
        throw new RuntimeException($conn->error);
    }
    $st->bind_param('i', $eventId);
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    return $n;
}

$tables = ['tbl_twg_member_scores', 'tbl_twg_entry_member_scores', 'tbl_twg_rubric_scores', 'tbl_twg_scores'];
$counts = [];
foreach ($tables as $table) {
    $counts[$table] = table_exists($conn, $table) ? count_event_rows($conn, $table, $eventId) : null;
}

$onBallot = 0;
$st = $conn->prepare(
    'SELECT COUNT(*) AS n FROM tbl_choices WHERE event_id = ? AND COALESCE(on_ballot, 0) = 1'
);
if ($st) {
    $st->bind_param('i', $eventId);
    $st->execute();
    $onBallot = (int) (($st->get_result()->fetch_assoc()['n'] ?? 0));
    $st->close();
}

echo "Event #{$eventId} {$label}\n";
echo $apply ? "MODE apply\n" : "MODE dry-run (pass --apply to delete)\n";
foreach ($counts as $table => $n) {
    if ($n === null) {
        echo "  {$table}: (table not present)\n";
        continue;
    }
    echo "  {$table}: {$n} row(s)\n";
}
echo "  businesses currently confirmed for public voting: {$onBallot}\n";

if (!$apply) {
    echo "No rows deleted.\n";
    exit(0);
}

$conn->begin_transaction();
try {
    $deleted = [];
    foreach ($tables as $table) {
        if ($counts[$table] === null) {
            continue;
        }
        $deleted[$table] = delete_event_rows($conn, $table, $eventId);
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(4);
}

echo "Deleted:\n";
foreach ($deleted as $table => $n) {
    echo "  {$table}: {$n}\n";
}
echo "Done. TWG Evaluation cells for this event are empty again. Award titles and businesses were not removed.\n";
