<?php
declare(strict_types=1);
/**
 * QR + email DB health (CLI). Focus: backlog, stuck rows, table size, missing indexes.
 */
require_once dirname(__DIR__) . '/db_connection.php';

$issues = [];
$warn = [];
$info = [];

function table_exists(mysqli $conn, string $t): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function has_index_on(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
         LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function status_counts(mysqli $conn, string $table, string $statusCol): array
{
    $out = [];
    $res = $conn->query("SELECT `$statusCol` AS st, COUNT(*) AS c FROM `$table` GROUP BY `$statusCol`");
    while ($row = $res->fetch_assoc()) {
        $out[(string) $row['st']] = (int) $row['c'];
    }
    return $out;
}

// --- Email: tbl_comm_messages ---
if (table_exists($conn, 'tbl_comm_messages')) {
    $info['comm_messages_total'] = (int) $conn->query('SELECT COUNT(*) c FROM tbl_comm_messages')->fetch_assoc()['c'];
    $info['comm_messages_by_status'] = status_counts($conn, 'tbl_comm_messages', 'status');
    $info['comm_qr_by_status'] = [];
    $res = $conn->query("SELECT status, COUNT(*) c FROM tbl_comm_messages WHERE type='qr_email' GROUP BY status");
    while ($row = $res->fetch_assoc()) {
        $info['comm_qr_by_status'][(string) $row['status']] = (int) $row['c'];
    }

    $pending = (int) ($info['comm_messages_by_status']['pending'] ?? 0);
    if ($pending > 50) {
        $warn[] = "$pending rows stuck in tbl_comm_messages.status=pending (app sends inline; pending usually means interrupted request).";
    }

    $res = $conn->query(
        "SELECT COUNT(*) c FROM tbl_comm_messages
         WHERE status='failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $recentFailed = (int) $res->fetch_assoc()['c'];
    $info['comm_failed_last_7d'] = $recentFailed;
    if ($recentFailed > 20) {
        $warn[] = "$recentFailed failed comm messages in the last 7 days — check SMTP / QR attachment errors.";
    }

    // Large HTML bodies bloat table and slow Communications search
    $res = $conn->query(
        'SELECT
           ROUND(AVG(LENGTH(body_html))/1024, 1) AS avg_kb,
           ROUND(MAX(LENGTH(body_html))/1024, 1) AS max_kb,
           SUM(LENGTH(body_html) > 500000) AS huge_rows
         FROM tbl_comm_messages'
    );
    $sizes = $res->fetch_assoc();
    $info['comm_body_html_kb'] = ['avg' => $sizes['avg_kb'], 'max' => $sizes['max_kb']];
    if ((int) ($sizes['huge_rows'] ?? 0) > 0) {
        $warn[] = (int) $sizes['huge_rows'] . ' comm message(s) with body_html > 500 KB — can slow list/search pages.';
    }

    if (!has_index_on($conn, 'tbl_comm_messages', 'status')) {
        $warn[] = 'tbl_comm_messages has no index on status — filters may scan full table as log grows.';
    }
    if (!has_index_on($conn, 'tbl_comm_messages', 'type')) {
        $warn[] = 'tbl_comm_messages has no index on type — QR email reports may slow down over time.';
    }
    if (!has_index_on($conn, 'tbl_comm_messages', 'created_at')) {
        $warn[] = 'tbl_comm_messages has no index on created_at — date filters may slow down.';
    }
} else {
    $issues[] = 'Missing table tbl_comm_messages.';
}

// --- Email outbox worker: tbl_msg_outbox ---
if (table_exists($conn, 'tbl_msg_outbox')) {
    $info['outbox_by_status'] = status_counts($conn, 'tbl_msg_outbox', 'status');
    $queued = (int) ($info['outbox_by_status']['queued'] ?? 0);
    $sending = (int) ($info['outbox_by_status']['sending'] ?? 0);
    if ($queued > 100) {
        $warn[] = "$queued emails queued in tbl_msg_outbox — run process_outbox.php (cron) or sends will backlog.";
    }
    if ($sending > 0) {
        $warn[] = "$sending outbox row(s) stuck in status=sending (crashed worker?). Reset to queued if older than 1 hour.";
        $res = $conn->query(
            "SELECT outbox_id, message_type, created_at FROM tbl_msg_outbox
             WHERE status='sending' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
             LIMIT 5"
        );
        $info['outbox_stuck_sending_sample'] = $res->fetch_all(MYSQLI_ASSOC);
    }
    if (!has_index_on($conn, 'tbl_msg_outbox', 'status')) {
        $warn[] = 'tbl_msg_outbox has no index on status — worker dequeue may slow as queue grows.';
    }
} else {
    $info['outbox_note'] = 'tbl_msg_outbox not present (optional queue path).';
}

if (table_exists($conn, 'tbl_msg_attachment')) {
    $info['msg_attachments'] = (int) $conn->query('SELECT COUNT(*) c FROM tbl_msg_attachment')->fetch_assoc()['c'];
}

// --- QR: choices without email, config blob size ---
if (table_exists($conn, 'tbl_choices')) {
    $res = $conn->query('SELECT COUNT(*) c FROM tbl_choices WHERE status = 1');
    $info['active_choices'] = (int) $res->fetch_assoc()['c'];
    $res = $conn->query(
        "SELECT COUNT(*) c FROM tbl_choices WHERE status = 1 AND (email IS NULL OR TRIM(email) = '')"
    );
    $noEmail = (int) $res->fetch_assoc()['c'];
    $info['active_choices_no_email'] = $noEmail;
    if ($noEmail > 0) {
        $warn[] = "$noEmail active choice(s) have no email — bulk QR email will skip or fail for them.";
    }
    if (!has_index_on($conn, 'tbl_choices', 'email')) {
        $info['choices_email_index'] = false;
    }
}

if (table_exists($conn, 'tbl_config')) {
    $res = $conn->query(
        "SELECT config_key,
                ROUND(LENGTH(config_value)/1024, 1) AS kb
         FROM tbl_config
         WHERE config_key LIKE 'qr%'
         ORDER BY LENGTH(config_value) DESC"
    );
    $info['qr_config_sizes_kb'] = $res->fetch_all(MYSQLI_ASSOC);
    foreach ($info['qr_config_sizes_kb'] as $row) {
        if ((float) $row['kb'] > 512) {
            $warn[] = 'tbl_config.' . $row['config_key'] . ' is ' . $row['kb'] . ' KB — large JSON can slow QR admin pages.';
        }
    }
}

// Table storage size (MB) for hot tables
$hot = ['tbl_comm_messages', 'tbl_msg_outbox', 'tbl_msg_attachment', 'tbl_choices', 'tbl_config', 'tbl_admin_audit_log'];
$info['storage_mb'] = [];
foreach ($hot as $t) {
    if (!table_exists($conn, $t)) {
        continue;
    }
    $stmt = $conn->prepare(
        'SELECT ROUND((data_length + index_length) / 1024 / 1024, 2) AS mb
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $mb = $stmt->get_result()->fetch_assoc()['mb'] ?? 0;
    $stmt->close();
    $info['storage_mb'][$t] = (float) $mb;
    if ((float) $mb > 100) {
        $warn[] = "$t is {$mb} MB on disk — unlikely to hang locally yet; archive old comm logs if it grows.";
    }
}

$verdict = empty($issues)
    ? (empty($warn) ? 'good' : 'good_with_notes')
    : 'issues';

echo json_encode([
    'verdict' => $verdict,
    'summary' => empty($issues) && empty($warn)
        ? 'No DB conditions found that would typically cause QR/email lag on this install.'
        : 'See issues/warnings below.',
    'issues' => $issues,
    'warnings' => $warn,
    'info' => $info,
    'not_db_bottlenecks' => [
        'QR image render is CPU/disk (qr_utils.php, PNG files under qrcodes/) — not slow SQL at current row counts.',
        'Send QR / Send All emails wait on SMTP + generating each PNG — browser timeout risk before DB size matters.',
        'Run process_outbox.php on a schedule only if you use tbl_msg_outbox queue path (qr_comm.php).',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
