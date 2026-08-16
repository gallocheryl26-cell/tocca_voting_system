<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function voter_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name((string) tocca_config('voter_session_name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function voter_json_error(string $message, int $code = 401): void
{
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function voter_require_authenticated(): int
{
    voter_session_start();
    $voterId = (int)($_SESSION['voter_id'] ?? 0);
    if ($voterId <= 0) {
        voter_json_error('Authentication required. Please verify your mobile number or draft code.');
    }
    return $voterId;
}

function voter_assert_matches_session(int $voterId): void
{
    voter_session_start();
    if ((int)($_SESSION['voter_id'] ?? 0) !== $voterId) {
        voter_json_error('Session mismatch.', 403);
    }
}

/**
 * @param mixed $input
 */
function voter_resolve_id(mysqli $conn, $input): ?int
{
    if ($input === null || $input === '') {
        return null;
    }
    if (preg_match('/^\d{11}$/', (string)$input)) {
        $stmt = $conn->prepare('SELECT voters_id FROM tbl_voters WHERE mobile_number = ?');
        $stmt->bind_param('s', $input);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['voters_id'] : null;
    }
    if (is_numeric($input)) {
        return (int)$input;
    }
    $stmt = $conn->prepare('SELECT voters_id FROM tbl_voters WHERE mobile_number = ?');
    $stmt->bind_param('s', $input);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['voters_id'] : null;
}

function voter_get_active_event_id(mysqli $conn): ?int
{
    $res = $conn->query(
        'SELECT event_id FROM tbl_events
         WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0
         ORDER BY year DESC, event_id DESC LIMIT 1'
    );
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        return (int)$row['event_id'];
    }
    return null;
}

function voter_choice_valid_for_question(mysqli $conn, int $choiceId, int $questionId): bool
{
    require_once dirname(__DIR__) . '/tocca_admin/includes/ballot_status.php';
    $awardSql = ballot_award_sql_and($conn, 'qc');
    $stmt = $conn->prepare("SELECT 1 FROM tbl_question_choices qc WHERE qc.choice_id = ? AND qc.question_id = ?{$awardSql} LIMIT 1");
    $stmt->bind_param('ii', $choiceId, $questionId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}
