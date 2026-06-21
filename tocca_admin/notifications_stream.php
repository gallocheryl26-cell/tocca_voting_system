<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_session.php';
tocca_admin_require_login(true);

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
ini_set('zlib.output_compression', '0');
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/db_connection.php';
const SYSTEM_ALERT_ACTIONS = [
    'system_pending_nomination',
    'system_nomination_start',
    'system_nomination_deadline',
    'system_voting_start',
    'system_voting_end',
    'system_pending_deadline',
    'system_nomination_submission',
];
while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

echo "retry: 5000\n\n";

const STREAM_MAX_SECONDS = 45;
const STREAM_SLEEP_MICROSECONDS = 2_000_000; // 2 seconds

function stream_event(string $type, array $payload = []): void
{
    echo "event: {$type}\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

function fetch_latest_audit(mysqli $conn): ?array
{
    $actions = array_values(SYSTEM_ALERT_ACTIONS);
    if (empty($actions)) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($actions), '?'));
    $sql = sprintf(
        'SELECT audit_id, created_at FROM tbl_nomination_audit WHERE action IN (%s) ORDER BY audit_id DESC LIMIT 1',
        $placeholders
    );

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $params = $actions;
    $types = str_repeat('s', count($actions));
    $bindArgs = [$types];
    foreach ($params as $index => $value) {
        $bindArgs[] = &$params[$index];
    }

    if (!call_user_func_array([$stmt, 'bind_param'], $bindArgs)) {
        $stmt->close();
        return null;
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    if (!$stmt->bind_result($auditId, $createdAt)) {
        $stmt->close();
        return null;
    }

    $row = null;
    if ($stmt->fetch()) {
        $row = [
            'audit_id'   => (int) ($auditId ?? 0),
            'created_at' => (string) ($createdAt ?? ''),
        ];
    }

    $stmt->close();

    return $row;
}

function count_new_audits(mysqli $conn, int $afterAuditId): int
{
    $actions = array_values(SYSTEM_ALERT_ACTIONS);
    if (empty($actions)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($actions), '?'));
    $sql = sprintf(
        'SELECT COUNT(*) FROM tbl_nomination_audit WHERE audit_id > ? AND action IN (%s)',
        $placeholders
    );

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    $params = array_merge([$afterAuditId], $actions);
    $types = 'i' . str_repeat('s', count($actions));
    $bindArgs = [$types];
    foreach ($params as $index => $value) {
        $bindArgs[] = &$params[$index];
    }

    if (!call_user_func_array([$stmt, 'bind_param'], $bindArgs)) {
        $stmt->close();
        return 0;
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    if (!$stmt->bind_result($count)) {
        $stmt->close();
        return 0;
    }

    $total = 0;
    if ($stmt->fetch()) {
        $total = (int) ($count ?? 0);
    }

    $stmt->close();

    return $total;
}

$since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
$lastNotifiedAuditId = max(0, $since);

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    $startedAt = time();
    $lastPingAt = 0;

    $latest = fetch_latest_audit($conn);
    if ($latest) {
        $latestId = (int) ($latest['audit_id'] ?? 0);
        if ($latestId > 0 && $lastNotifiedAuditId <= 0) {
            $lastNotifiedAuditId = $latestId;
        }
        stream_event('ping', [
            'latest_audit_id' => $latestId,
            'created_at'      => $latest['created_at'] ?? '',
        ]);
        $lastPingAt = time();
    }

    while (!connection_aborted()) {
        if ((time() - $startedAt) >= STREAM_MAX_SECONDS) {
            stream_event('complete', [
                'latest_audit_id' => $lastNotifiedAuditId,
            ]);
            break;
        }

        $latest = fetch_latest_audit($conn);
        if ($latest) {
            $latestId = (int) ($latest['audit_id'] ?? 0);
            if ($latestId > $lastNotifiedAuditId) {
                $newCount = count_new_audits($conn, $lastNotifiedAuditId);
                $lastNotifiedAuditId = $latestId;
                stream_event('notification', [
                    'latest_audit_id' => $latestId,
                    'created_at'      => $latest['created_at'] ?? '',
                    'new_count'       => $newCount,
                ]);
                $lastPingAt = time();
            } elseif ((time() - $lastPingAt) >= 10) {
                stream_event('ping', [
                    'latest_audit_id' => $latestId,
                ]);
                $lastPingAt = time();
            }
        } elseif ((time() - $lastPingAt) >= 10) {
            stream_event('ping', [
                'latest_audit_id' => $lastNotifiedAuditId,
            ]);
            $lastPingAt = time();
        }

        if (connection_aborted()) {
            break;
        }

        usleep(STREAM_SLEEP_MICROSECONDS);
    }
} catch (Throwable $error) {
    stream_event('error', [
        'message' => 'Stream unavailable',
    ]);
}