<?php
// tocca_admin/notification_helpers.php
// Shared utilities for recording system-level notifications.
declare(strict_types=1);

if (!function_exists('system_notif_ensure_action_column_ready')) {
    function system_notif_ensure_action_column_ready(mysqli $conn, bool $forceRefresh = false): bool
    {
        static $status = null;
        if ($forceRefresh) {
            $status = null;
        }
        if ($status === true) {
            return true;
        }
        if ($status === false) {
            return false;
        }

        $result = $conn->query("SHOW COLUMNS FROM `tbl_nomination_audit` LIKE 'action'");
        if (!$result) {
            error_log('system_notif: audit column introspection failed: ' . $conn->error);
            $status = false;
            return false;
        }

        $info = $result->fetch_assoc();
        $result->close();
        if (!$info) {
            $status = false;
            return false;
        }

        $type = strtolower((string)($info['Type'] ?? ''));
        if ($type === '') {
            $status = false;
            return false;
        }

        $needsAlter = false;
        if (str_starts_with($type, 'enum(')) {
            $needsAlter = true;
        } elseif (preg_match('/varchar\((\d+)\)/', $type, $matches)) {
            $length = (int)($matches[1] ?? 0);
            if ($length > 0 && $length < 32) {
                $needsAlter = true;
            }
        }

        if ($needsAlter) {
            if (!$conn->query("ALTER TABLE `tbl_nomination_audit` MODIFY COLUMN `action` VARCHAR(32) NOT NULL")) {
                error_log('system_notif: audit action column alter failed: ' . $conn->error);
                $status = false;
                return false;
            }
        }

        $status = true;
        return true;
    }
}

if (!function_exists('system_notif_is_schema_error')) {
    function system_notif_is_schema_error(string $error): bool
    {
        $err = strtolower($error);
        if ($err === '') {
            return false;
        }

        $contains = static function (string $needle) use ($err): bool {
            return strpos($err, $needle) !== false;
        };

        return (
            ($contains('incorrect') && $contains('action'))
            || $contains("data truncated for column 'action'")
            || ($contains('enum') && $contains('action'))
            || ($contains('constraint') && $contains('action'))
        );
    }
}

if (!function_exists('system_notif_decode_details')) {
    function system_notif_decode_details(?string $details): ?array
    {
        if ($details === null) {
            return null;
        }

        $trimmed = trim($details);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $error) {
            return null;
        }
    }
}

if (!function_exists('system_notif_marker_exists')) {
    function system_notif_marker_exists(mysqli $conn, string $action, string $marker, int $eventId = 0, int $limit = 25): bool
    {
        $marker = trim($marker);
        if ($marker === '') {
            return false;
        }

        $stmt = $conn->prepare('SELECT details FROM tbl_nomination_audit WHERE action = ? ORDER BY audit_id DESC LIMIT ?');
        if (!$stmt) {
            return false;
        }

        if (!$stmt->bind_param('si', $action, $limit)) {
            $stmt->close();
            return false;
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        if (!$stmt->bind_result($details)) {
            $stmt->close();
            return false;
        }

        $found = false;
        while ($stmt->fetch()) {
            $payload = system_notif_decode_details($details);
            if (!is_array($payload)) {
                continue;
            }

            if (($payload['marker'] ?? '') !== $marker) {
                continue;
            }

            if ($eventId > 0 && isset($payload['event_id']) && (int)$payload['event_id'] !== $eventId) {
                continue;
            }

            $found = true;
            break;
        }

        $stmt->close();
        return $found;
    }
}

if (!function_exists('system_notif_insert')) {
    function system_notif_insert(mysqli $conn, string $action, array $payload, int $nominationId = 0): void
    {
        $forceRefresh = false;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if (!system_notif_ensure_action_column_ready($conn, $forceRefresh)) {
                return;
            }

            if (!isset($payload['generated_at'])) {
                try {
                    $tz = new DateTimeZone('Asia/Manila');
                    $payload['generated_at'] = (new DateTime('now', $tz))->format(DATE_ATOM);
                } catch (Throwable $e) {
                    $payload['generated_at'] = date(DATE_ATOM);
                }
            }

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }

            $stmt = $conn->prepare('INSERT INTO tbl_nomination_audit (nomination_id, action, details) VALUES (?, ?, ?)');
            if (!$stmt) {
                error_log('system_notif: insert prepare failed: ' . $conn->error);
                return;
            }

            if (!$stmt->bind_param('iss', $nominationId, $action, $json)) {
                error_log('system_notif: insert bind failed: ' . $conn->error);
                $stmt->close();
                return;
            }

            if ($stmt->execute()) {
                $stmt->close();
                return;
            }

            $error = $stmt->error ?: $conn->error;
            $stmt->close();

            if ($forceRefresh || !system_notif_is_schema_error($error)) {
                if ($error !== '') {
                    error_log('system_notif: insert execute failed: ' . $error);
                }
                return;
            }

            $forceRefresh = true;
        }
    }
}

if (!function_exists('system_notif_record_once')) {
    function system_notif_record_once(mysqli $conn, string $action, array $payload, string $marker, int $nominationId = 0, int $eventId = 0): void
    {
        $marker = trim($marker);
        if ($marker === '') {
            return;
        }

        $eventContext = $eventId > 0 ? $eventId : (int)($payload['event_id'] ?? 0);
        if (system_notif_marker_exists($conn, $action, $marker, $eventContext)) {
            return;
        }

        $payloadWithMarker = $payload;
        $payloadWithMarker['marker'] = $marker;
        system_notif_insert($conn, $action, $payloadWithMarker, $nominationId);
    }
}