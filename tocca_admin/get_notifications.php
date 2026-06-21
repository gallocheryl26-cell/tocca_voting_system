<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/notification_helpers.php';

date_default_timezone_set('Asia/Manila');

const SYSTEM_ALERT_ACTIONS = [
    'system_pending_nomination',
    'system_nomination_start',
    'system_nomination_deadline',
    'system_voting_start',
    'system_voting_end',
    'system_pending_deadline',
    'system_nomination_submission',
];

final class HttpJsonException extends RuntimeException
{
    public function __construct(private int $statusCode, string $message)
    {
        parent::__construct($message, 0, null);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

function respond_success(array $payload): void
{
    echo json_encode(['status' => 'success', 'data' => $payload], JSON_UNESCAPED_UNICODE);
}

function respond_error(string $message, int $statusCode = 500): void
{
    http_response_code($statusCode);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
}

function fail(string $message, int $statusCode = 500): never
{
    throw new HttpJsonException($statusCode, $message);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    if ($limit <= 0) {
        $limit = 20;
    }
    $limit = min($limit, 100);

    $actionFilters = array_values(SYSTEM_ALERT_ACTIONS);
    $placeholders = implode(',', array_fill(0, count($actionFilters), '?'));
    $sql = sprintf(
        'SELECT audit_id, nomination_id, action, details, created_at
           FROM tbl_nomination_audit
          WHERE action IN (%s)
          ORDER BY created_at DESC
          LIMIT ?',
        $placeholders
    );

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Unable to load notifications.', 500);
    }

    $params = $actionFilters;
    $params[] = $limit;
    $types = str_repeat('s', count($actionFilters)) . 'i';

    $bindArgs = [$types];
    foreach ($params as $index => $value) {
        $bindArgs[] = &$params[$index];
    }

    if (!call_user_func_array([$stmt, 'bind_param'], $bindArgs)) {
        $stmt->close();
        fail('Unable to load notifications.', 500);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        fail('Unable to load notifications.', 500);
    }

    if (!$stmt->bind_result($auditId, $nominationId, $action, $details, $createdAt)) {
        $stmt->close();
        fail('Unable to load notifications.', 500);
    }

    $rows = [];
    while ($stmt->fetch()) {
        $rows[] = [
            'audit_id'      => (int) ($auditId ?? 0),
            'nomination_id' => (int) ($nominationId ?? 0),
            'action'        => (string) ($action ?? ''),
            'details'       => (string) ($details ?? ''),
            'created_at'    => (string) ($createdAt ?? ''),
        ];
    }

    if ($stmt->errno) {
        $stmt->close();
        fail('Unable to load notifications.', 500);
    }

    $stmt->close();

    $rows = array_values(array_filter($rows, static function (array $row): bool {
        return !is_notification_dismissed($row['details'] ?? '');
    }));

    $hasBusinessColumn = column_exists($conn, 'tbl_nominations', 'business_name');
    $businessCache = [];

    foreach ($rows as &$row) {
        $nomId = (int) $row['nomination_id'];
        if ($nomId === 0) {
            $row['business_name'] = '—';
            continue;
        }

        if (!array_key_exists($nomId, $businessCache)) {
            $businessCache[$nomId] = load_business_name($conn, $nomId, $hasBusinessColumn);
        }

        $name = trim($businessCache[$nomId]);
        $row['business_name'] = ($name !== '') ? $name : '—';
    }
    unset($row);

    respond_success([
        'notifications' => $rows,
        'count'         => count($rows),
    ]);
} catch (HttpJsonException $httpError) {
    respond_error($httpError->getMessage(), $httpError->getStatusCode());
} catch (Throwable $error) {
    error_log('get_notifications.php: unexpected error - ' . $error->getMessage());
    respond_error('Failed to load notifications.', 500);
} finally {
    restore_error_handler();
}

function is_notification_dismissed(?string $details): bool
{
    if ($details === null) {
        return false;
    }

    $payload = system_notif_decode_details($details);
    if (!is_array($payload)) {
        return false;
    }

    if (!empty($payload['dismissed_at'])) {
        return true;
    }

    if (array_key_exists('dismissed', $payload)) {
        return filter_var($payload['dismissed'], FILTER_VALIDATE_BOOLEAN) === true;
    }

    return false;
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = 'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Unable to inspect database schema.', 500);
    }

    if (!$stmt->bind_param('ss', $table, $column)) {
        $stmt->close();
        fail('Unable to inspect database schema.', 500);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        fail('Unable to inspect database schema.', 500);
    }

    if (!$stmt->bind_result($dummy)) {
        $stmt->close();
        fail('Unable to inspect database schema.', 500);
    }

    $exists = $stmt->fetch() !== null;
    $stmt->close();

    return $exists;
}

function load_business_name(mysqli $conn, int $nominationId, bool $hasDirectColumn): string
{
    if ($nominationId <= 0) {
        return '';
    }

    if ($hasDirectColumn) {
        $stmt = $conn->prepare('SELECT business_name FROM tbl_nominations WHERE nomination_id = ? LIMIT 1');
        if (!$stmt) {
            fail('Unable to read nomination details.', 500);
        }

        if (!$stmt->bind_param('i', $nominationId)) {
            $stmt->close();
            fail('Unable to read nomination details.', 500);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            fail('Unable to read nomination details.', 500);
        }

        if (!$stmt->bind_result($businessName)) {
            $stmt->close();
            fail('Unable to read nomination details.', 500);
        }

        $name = '';
        if ($stmt->fetch()) {
            $name = (string) ($businessName ?? '');
        }
        $stmt->close();

        return $name;
    }

    $sql = 'SELECT a.answer
              FROM tbl_nomination_answers a
              JOIN tbl_nomination_fields f ON f.id = a.field_id
             WHERE a.nomination_id = ?
               AND f.name IN (\'business_name\', \'official_business_name\', \'company\', \'company_name\', \'business\')
             ORDER BY a.id ASC
             LIMIT 1';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Unable to read nomination details.', 500);
    }

    if (!$stmt->bind_param('i', $nominationId)) {
        $stmt->close();
        fail('Unable to read nomination details.', 500);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        fail('Unable to read nomination details.', 500);
    }

    if (!$stmt->bind_result($answer)) {
        $stmt->close();
        fail('Unable to read nomination details.', 500);
    }

    $name = '';
    if ($stmt->fetch()) {
        $name = (string) ($answer ?? '');
    }
    $stmt->close();

    return $name;
}