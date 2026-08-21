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

function voter_session_release(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
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
    require_once dirname(__DIR__) . '/tocca_admin/includes/award_entry_helpers.php';
    award_entry_ensure_schema($conn);

    // Named ballot entry ids are sent as choice_id from the voter UI.
    $st = $conn->prepare(
        'SELECT be.choice_id
         FROM tbl_award_ballot_entries be
         INNER JOIN tbl_choices c ON c.choice_id = be.choice_id
         WHERE be.ballot_entry_id = ? AND be.question_id = ? AND be.is_active = 1 AND c.status = 1
         LIMIT 1'
    );
    if ($st) {
        $st->bind_param('ii', $choiceId, $questionId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            return true;
        }
    }

    $awardSql = ballot_award_sql_and($conn, 'qc');
    $stmt = $conn->prepare("SELECT 1 FROM tbl_question_choices qc WHERE qc.choice_id = ? AND qc.question_id = ?{$awardSql} LIMIT 1");
    $stmt->bind_param('ii', $choiceId, $questionId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

/**
 * Resolve a voter select value to business choice_id + optional ballot_entry_id.
 *
 * @return array{choice_id:int,ballot_entry_id:?int,entry_name:string,display:string}|null
 */
function voter_resolve_ballot_selection(mysqli $conn, int $questionId, int $selectedId): ?array
{
    require_once dirname(__DIR__) . '/tocca_admin/includes/award_entry_helpers.php';
    award_entry_ensure_schema($conn);
    if ($selectedId <= 0 || $questionId <= 0) {
        return null;
    }

    $st = $conn->prepare(
        'SELECT be.ballot_entry_id, be.choice_id, be.entry_name, c.choice_name
         FROM tbl_award_ballot_entries be
         INNER JOIN tbl_choices c ON c.choice_id = be.choice_id
         WHERE be.ballot_entry_id = ? AND be.question_id = ? AND be.is_active = 1 AND c.status = 1
         LIMIT 1'
    );
    if ($st) {
        $st->bind_param('ii', $selectedId, $questionId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            $business = (string) $row['choice_name'];
            $entry = (string) $row['entry_name'];
            return [
                'choice_id' => (int) $row['choice_id'],
                'ballot_entry_id' => (int) $row['ballot_entry_id'],
                'entry_name' => $entry,
                'display' => award_entry_display_label($business, $entry),
            ];
        }
    }

    if (!voter_choice_valid_for_question($conn, $selectedId, $questionId)) {
        return null;
    }
    return [
        'choice_id' => $selectedId,
        'ballot_entry_id' => null,
        'entry_name' => '',
        'display' => '',
    ];
}
