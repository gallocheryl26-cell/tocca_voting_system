<?php
declare(strict_types=1);

/**
 * Rate limiting for existing-voter access code login (verify_existing.php).
 */

if (!function_exists('voter_access_login_max_attempts')) {
    function voter_access_login_max_attempts(): int
    {
        return max(3, (int) tocca_config('voter_access_max_attempts'));
    }
}

if (!function_exists('voter_access_login_lock_seconds')) {
    function voter_access_login_lock_seconds(): int
    {
        return max(60, (int) tocca_config('voter_access_lock_seconds'));
    }
}

if (!function_exists('voter_access_login_ensure_schema')) {
    function voter_access_login_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `tbl_voter_access_attempts` (
  `mobile_key` CHAR(64) NOT NULL,
  `fail_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `last_attempt_at` DATETIME NOT NULL,
  PRIMARY KEY (`mobile_key`),
  KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        @$conn->query($sql);
    }
}

if (!function_exists('voter_access_login_mobile_key')) {
    function voter_access_login_mobile_key(string $normalizedMobile): string
    {
        return hash('sha256', $normalizedMobile);
    }
}

/**
 * @return array{locked:bool,lock_seconds:int,attempts_remaining:int,message:string}
 */
if (!function_exists('voter_access_login_status')) {
    function voter_access_login_status(mysqli $conn, string $normalizedMobile): array
    {
        voter_access_login_ensure_schema($conn);

        $maxAttempts = voter_access_login_max_attempts();
        $key = voter_access_login_mobile_key($normalizedMobile);

        $stmt = $conn->prepare(
            'SELECT fail_count, locked_until FROM tbl_voter_access_attempts WHERE mobile_key = ? LIMIT 1'
        );
        if (!$stmt) {
            return [
                'locked' => false,
                'lock_seconds' => 0,
                'attempts_remaining' => $maxAttempts,
                'message' => '',
            ];
        }
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return [
                'locked' => false,
                'lock_seconds' => 0,
                'attempts_remaining' => $maxAttempts,
                'message' => '',
            ];
        }

        $failCount = (int) ($row['fail_count'] ?? 0);
        $lockedUntil = $row['locked_until'] ?? null;

        if ($lockedUntil !== null && $lockedUntil !== '') {
            $until = strtotime((string) $lockedUntil);
            if ($until !== false && $until > time()) {
                $secs = $until - time();

                return [
                    'locked' => true,
                    'lock_seconds' => $secs,
                    'attempts_remaining' => 0,
                    'message' => 'Too many incorrect access code attempts. Use Forgot access code to reset your PIN.',
                ];
            }
            // Lock expired — reset counter so the voter can try again.
            voter_access_login_clear($conn, $normalizedMobile);
            $failCount = 0;
        }

        $remaining = max(0, $maxAttempts - $failCount);

        return [
            'locked' => false,
            'lock_seconds' => 0,
            'attempts_remaining' => $remaining,
            'message' => '',
        ];
    }
}

if (!function_exists('voter_access_login_format_wait')) {
    function voter_access_login_format_wait(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds >= 3600) {
            $h = (int) floor($seconds / 3600);
            $m = (int) floor(($seconds % 3600) / 60);

            return $h . ' hr' . ($h === 1 ? '' : 's')
                . ($m > 0 ? ' ' . $m . ' min' : '');
        }
        if ($seconds >= 60) {
            $m = (int) floor($seconds / 60);
            $s = $seconds % 60;

            return $m . ' min' . ($s > 0 ? ' ' . $s . ' sec' : '');
        }

        return $seconds . ' sec';
    }
}

if (!function_exists('voter_access_login_clear')) {
    function voter_access_login_clear(mysqli $conn, string $normalizedMobile): void
    {
        voter_access_login_ensure_schema($conn);
        $key = voter_access_login_mobile_key($normalizedMobile);
        $stmt = $conn->prepare('DELETE FROM tbl_voter_access_attempts WHERE mobile_key = ?');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Record a failed attempt; returns updated lock status.
 *
 * @return array{locked:bool,lock_seconds:int,attempts_remaining:int,message:string}
 */
if (!function_exists('voter_access_login_record_failure')) {
    function voter_access_login_record_failure(mysqli $conn, string $normalizedMobile): array
    {
        voter_access_login_ensure_schema($conn);

        $maxAttempts = voter_access_login_max_attempts();
        $lockSeconds = voter_access_login_lock_seconds();
        $key = voter_access_login_mobile_key($normalizedMobile);
        $now = date('Y-m-d H:i:s');

        $stmt = $conn->prepare(
            'INSERT INTO tbl_voter_access_attempts (mobile_key, fail_count, locked_until, last_attempt_at)
             VALUES (?, 1, NULL, ?)
             ON DUPLICATE KEY UPDATE
               fail_count = fail_count + 1,
               last_attempt_at = VALUES(last_attempt_at),
               locked_until = IF(
                 fail_count + 1 >= ?,
                 DATE_ADD(VALUES(last_attempt_at), INTERVAL ? SECOND),
                 locked_until
               )'
        );
        if (!$stmt) {
            return voter_access_login_status($conn, $normalizedMobile);
        }
        $stmt->bind_param('ssii', $key, $now, $maxAttempts, $lockSeconds);
        $stmt->execute();
        $stmt->close();

        // Small delay to slow automated guessing (does not replace lockout).
        usleep(400000);

        return voter_access_login_status($conn, $normalizedMobile);
    }
}

if (!function_exists('voter_access_login_error_payload')) {
    /**
     * @param array{locked:bool,lock_seconds:int,attempts_remaining:int,message:string} $status
     * @return array<string, mixed>
     */
    function voter_access_login_error_payload(
        array $status,
        string $baseMessage = 'Incorrect access code.'
    ): array {
        if (!empty($status['locked'])) {
            return [
                'status' => 'error',
                'code' => 'locked',
                'message' => $status['message'] !== ''
                    ? $status['message']
                    : 'Too many incorrect attempts. Please try again later.',
                'lock_seconds' => (int) ($status['lock_seconds'] ?? 0),
                'attempts_remaining' => 0,
            ];
        }

        $remaining = (int) ($status['attempts_remaining'] ?? 0);
        $message = $baseMessage;
        if ($remaining > 0 && $remaining <= 3) {
            $message .= ' ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.';
        } elseif ($remaining === 0) {
            $message .= ' Next failed attempt will temporarily lock this number.';
        }

        return [
            'status' => 'error',
            'code' => 'invalid',
            'message' => $message,
            'attempts_remaining' => $remaining,
        ];
    }
}
