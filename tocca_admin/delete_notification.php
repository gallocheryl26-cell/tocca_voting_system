<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/notification_helpers.php';

final class HttpJsonException extends RuntimeException
{
    public function __construct(string $message, private int $statusCode = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

function respond_success(array $data = []): void
{
    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
}

function respond_error(string $message, int $statusCode = 500): void
{
    http_response_code($statusCode);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
}

function fail(string $message, int $statusCode = 500): never
{
    throw new HttpJsonException($message, $statusCode);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (strcasecmp($method, 'POST') !== 0) {
        header('Allow: POST');
        fail('Method not allowed.', 405);
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    $payload = read_request_payload();
    $auditId = filter_audit_id($payload);

     $details = fetch_notification_details($conn, $auditId);

    $result = transform_details_with_dismissal($details);

    if (!update_notification_details($conn, $auditId, $result['details'])) {
        fail('Unable to update notifications.', 500);
    }

    respond_success([
        'deleted'       => true,
        'dismissed'     => true,
        'audit_id'      => $auditId,
        'dismissed_at'  => $result['dismissed_at'],
    ]);
} catch (HttpJsonException $httpError) {
    respond_error($httpError->getMessage(), $httpError->getStatusCode());
} catch (Throwable $error) {
    error_log('delete_notification.php: unexpected error - ' . $error->getMessage());
    respond_error('Unable to delete notification.', 500);
} finally {
    restore_error_handler();
}

function fetch_notification_details(mysqli $conn, int $auditId): array
{
    $stmt = $conn->prepare('SELECT action, details FROM tbl_nomination_audit WHERE audit_id = ? LIMIT 1');
    if (!$stmt) {
        fail('Unable to update notifications.', 500);
    }

    if (!$stmt->bind_param('i', $auditId)) {
        $stmt->close();
        fail('Unable to update notifications.', 500);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        fail('Unable to update notifications.', 500);
    }

    if (!$stmt->bind_result($action, $details)) {
        $stmt->close();
        fail('Unable to update notifications.', 500);
    }

    if (!$stmt->fetch()) {
        $stmt->close();
        fail('Notification not found or already deleted.', 404);
    }

    $stmt->close();

    return [
        'action'  => (string) ($action ?? ''),
        'details' => (string) ($details ?? ''),
    ];
}

function transform_details_with_dismissal(array $record): array
{
    $originalDetails = $record['details'] ?? '';
    $payload = null;

    if (is_string($originalDetails) && $originalDetails !== '') {
        $decoded = system_notif_decode_details($originalDetails);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    if (!is_array($payload)) {
        $payload = ['message' => (string) $originalDetails];
    }

    $existingDismissedAt = '';
    if (isset($payload['dismissed_at']) && is_string($payload['dismissed_at'])) {
        $existingDismissedAt = trim($payload['dismissed_at']);
    }

    $payload['dismissed'] = true;

    if ($existingDismissedAt === '') {
        $payload['dismissed_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM);
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        fail('Unable to update notifications.', 500);
    }

    $dismissedAt = $existingDismissedAt !== '' ? $existingDismissedAt : $payload['dismissed_at'];

    return [
        'details'      => $encoded,
        'dismissed_at' => (string) $dismissedAt,
    ];
}

function update_notification_details(mysqli $conn, int $auditId, string $details): bool
{
    $stmt = $conn->prepare('UPDATE tbl_nomination_audit SET details = ? WHERE audit_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    if (!$stmt->bind_param('si', $details, $auditId)) {
        $stmt->close();
        return false;
    }

    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function read_request_payload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            fail('Invalid request payload.', 400);
        }

        return $decoded;
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    return [];
}

function filter_audit_id(array $payload): int
{
    $value = $payload['audit_id'] ?? $payload['id'] ?? null;
    if ($value === null || $value === '') {
        fail('Notification id is required.', 422);
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if (!preg_match('/^-?\d+$/', $trimmed)) {
            fail('Notification id is invalid.', 422);
        }
        $value = $trimmed;
    }

    if (!is_int($value) && !is_string($value)) {
        fail('Notification id is invalid.', 422);
    }

    $auditId = (int) $value;
    if ($auditId <= 0) {
        fail('Notification id is invalid.', 422);
    }

    return $auditId;
}