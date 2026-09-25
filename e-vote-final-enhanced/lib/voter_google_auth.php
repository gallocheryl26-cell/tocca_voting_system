<?php
declare(strict_types=1);

function voter_google_auth_enabled(): bool
{
    return strtolower(trim((string) tocca_config('voter_auth_mode'))) === 'google_with_legacy';
}

function voter_google_auth_client_key(): string
{
    // REMOTE_ADDR cannot be spoofed by a caller-controlled forwarding header.
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return hash('sha256', $ip !== '' ? $ip : 'unknown');
}

/**
 * Limit token-verification attempts per IP. A successful Google login is still
 * protected by Firebase; this prevents the PHP endpoint from being hammered.
 */
function voter_google_auth_rate_limit(mysqli $conn, int $maxAttempts = 30, int $windowSeconds = 300): void
{
    $conn->query(
        'CREATE TABLE IF NOT EXISTS tbl_voter_auth_attempts (
            attempt_key CHAR(64) NOT NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            PRIMARY KEY (attempt_key),
            KEY idx_voter_auth_window (window_started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $key = voter_google_auth_client_key();
    $stmt = $conn->prepare(
        'INSERT INTO tbl_voter_auth_attempts (attempt_key, attempt_count, window_started_at)
         VALUES (?, 1, NOW())
         ON DUPLICATE KEY UPDATE
           attempt_count = IF(window_started_at < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, attempt_count + 1),
           window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), window_started_at)'
    );
    $stmt->bind_param('sii', $key, $windowSeconds, $windowSeconds);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('SELECT attempt_count, window_started_at FROM tbl_voter_auth_attempts WHERE attempt_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && (int) $row['attempt_count'] > $maxAttempts) {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);
        echo json_encode([
            'status' => 'error',
            'code' => 'rate_limited',
            'message' => 'Too many login attempts. Please wait a few minutes and try again.',
        ]);
        exit;
    }
}

function voter_google_auth_clear_rate_limit(mysqli $conn): void
{
    $key = voter_google_auth_client_key();
    $stmt = $conn->prepare('DELETE FROM tbl_voter_auth_attempts WHERE attempt_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
}
