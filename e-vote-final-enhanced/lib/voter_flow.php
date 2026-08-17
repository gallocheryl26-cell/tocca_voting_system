<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/voter_session.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/admin_schema.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/ballot_status.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/category_voting_profile.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/award_answer_fields.php';

/**
 * Canonical voting-flow rules (mobile lookup, eligibility, voting window).
 * All voter API endpoints should use these helpers for consistent behavior.
 *
 * tbl_voters.has_voted = 1 means the voter has finalized an answer for every
 * votable award in the current event. Votable = active category, and either a
 * freeform title or a dropdown title with at least one on-ballot business.
 * Drafts and per-category saves keep has_voted = 0.
 */

/**
 * SQL fragment: this award can appear on the public ballot.
 * Dropdown titles need a linked, active, on-ballot business.
 * Freeform / song titles (typed answers) stay votable without a business list.
 */
function voter_flow_votable_question_sql(mysqli $conn, string $questionAlias = 'q'): string
{
    category_voting_profile_ensure_schema($conn);
    award_answer_fields_ensure_schema($conn);
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $questionAlias) ?: 'q';
    $onBallot = ballot_status_sql_and($conn, 'ch_vote');
    $awardBallot = ballot_award_sql_and($conn, 'qc_vote');
    $fields = "LOWER(TRIM(COALESCE({$alias}.answer_fields, '')))";
    $openTextName = category_voting_profile_open_text_name_sql($alias);
    $hasBallotBusiness = "EXISTS (
            SELECT 1
            FROM tbl_question_choices qc_vote
            INNER JOIN tbl_choices ch_vote ON ch_vote.choice_id = qc_vote.choice_id
            WHERE qc_vote.question_id = {$alias}.question_id
              AND COALESCE(ch_vote.status, 1) = 1
              {$onBallot}
              {$awardBallot}
        )";
    // answer_fields is the source of truth. Name heuristics only apply when
    // the column is empty — otherwise "Make-up Artist" was treated as a song.
    return "(
        {$fields} IN ('song_singer', 'product_business')
        OR (
            {$fields} NOT IN ('business_photo', 'product_business', 'song_singer')
            AND (
                COALESCE({$alias}.choice_type, 1) <> 1
                OR EXISTS (
                    SELECT 1 FROM tbl_categories _vp_cat
                    WHERE _vp_cat.category_id = {$alias}.category_id
                      AND LOWER(TRIM(COALESCE(_vp_cat.voting_profile, ''))) = 'media'
                )
                OR {$openTextName}
            )
        )
        OR {$hasBallotBusiness}
    )";
}

/**
 * Normalize PH mobile to 09XXXXXXXXX or return null if invalid.
 */
function voter_flow_normalize_mobile(string $raw): ?string
{
    $digits = preg_replace('/\D/', '', trim($raw));
    if ($digits === '') {
        return null;
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        $digits = '0' . $digits;
    }
    if (!preg_match('/^09\d{9}$/', $digits)) {
        return null;
    }
    return $digits;
}

/**
 * @return array{event_id:int,voting_start:string,voting_end:string}|null
 */
function voter_flow_active_event(mysqli $conn): ?array
{
    $archived = admin_unarchived_events_where($conn, 'e');
    $res = $conn->query(
        "SELECT e.event_id, e.voting_start, e.voting_end, e.event_name, e.year
         FROM tbl_events e
         WHERE e.is_active = 1 AND {$archived}
         ORDER BY e.year DESC, e.event_id DESC
         LIMIT 1"
    );
    if (!$res || $res->num_rows === 0) {
        return null;
    }
    $row = $res->fetch_assoc();
    return [
        'event_id' => (int)$row['event_id'],
        'voting_start' => (string)$row['voting_start'],
        'voting_end' => (string)$row['voting_end'],
        'event_name' => (string)($row['event_name'] ?? ''),
        'year' => (string)($row['year'] ?? ''),
    ];
}

function voter_flow_is_voting_open(mysqli $conn, ?string $now = null): bool
{
    $event = voter_flow_active_event($conn);
    if ($event === null) {
        return false;
    }
    $tz = new DateTimeZone('Asia/Manila');
    $at = $now !== null
        ? DateTime::createFromFormat('Y-m-d H:i:s', $now, $tz)
        : new DateTime('now', $tz);
    if ($at === false) {
        return false;
    }
    $start = DateTime::createFromFormat('Y-m-d H:i:s', $event['voting_start'], $tz);
    $end = DateTime::createFromFormat('Y-m-d H:i:s', $event['voting_end'], $tz);
    if ($start === false || $end === false) {
        return false;
    }
    return $at >= $start && $at <= $end;
}

/**
 * True if the voter has any saved draft or finalized answer for the active event.
 */
function voter_flow_has_ballot_data(mysqli $conn, int $voterId, ?int $eventId = null): bool
{
    if ($eventId === null) {
        $event = voter_flow_active_event($conn);
        $eventId = $event['event_id'] ?? null;
    }

    $tables = [
        'SELECT 1 FROM tbl_draft_choice WHERE voters_id = ? LIMIT 1',
        'SELECT 1 FROM tbl_draft_freetext WHERE voters_id = ? LIMIT 1',
    ];

    if ($eventId !== null) {
        $tables[] = 'SELECT 1 FROM tbl_poll_choice pc
            INNER JOIN tbl_questions q ON pc.question_id = q.question_id
            INNER JOIN tbl_categories c ON q.category_id = c.category_id
            WHERE pc.voters_id = ? AND c.event_id = ? LIMIT 1';
        $tables[] = 'SELECT 1 FROM tbl_poll_freetext pf
            INNER JOIN tbl_questions q ON pf.question_id = q.question_id
            INNER JOIN tbl_categories c ON q.category_id = c.category_id
            WHERE pf.voters_id = ? AND c.event_id = ? LIMIT 1';
    } else {
        $tables[] = 'SELECT 1 FROM tbl_poll_choice WHERE voters_id = ? LIMIT 1';
        $tables[] = 'SELECT 1 FROM tbl_poll_freetext WHERE voters_id = ? LIMIT 1';
    }

    foreach ($tables as $sql) {
        $stmt = $conn->prepare($sql);
        if ($eventId !== null && str_contains($sql, 'event_id')) {
            $stmt->bind_param('ii', $voterId, $eventId);
        } else {
            $stmt->bind_param('i', $voterId);
        }
        $stmt->execute();
        $has = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($has) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{voters_id:int,has_voted:int,draft_code:?string,mobile_number:string}|null
 */
function voter_flow_find_by_mobile(mysqli $conn, string $mobile): ?array
{
    $stmt = $conn->prepare(
        'SELECT voters_id, has_voted, draft_code, mobile_number FROM tbl_voters WHERE mobile_number = ? LIMIT 1'
    );
    $stmt->bind_param('s', $mobile);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    return [
        'voters_id' => (int)$row['voters_id'],
        'has_voted' => (int)$row['has_voted'],
        'draft_code' => $row['draft_code'] !== null ? (string)$row['draft_code'] : null,
        'mobile_number' => (string)$row['mobile_number'],
    ];
}

/**
 * Mobile lookup for the entry gate (index "Check Number", OTP pre-check).
 *
 * status values:
 * - closed   voting period inactive
 * - error    invalid mobile
 * - new      not registered
 * - exists   registered (includes has_voted, has_data flags)
 */
function voter_flow_mobile_gate_status(mysqli $conn, string $mobileRaw): array
{
    $mobile = voter_flow_normalize_mobile($mobileRaw);
    if ($mobile === null) {
        return ['status' => 'error', 'message' => 'Invalid mobile number format.'];
    }

    if (!voter_flow_is_voting_open($conn)) {
        return [
            'status' => 'closed',
            'message' => 'Voting is not open at this time.',
        ];
    }

    $voter = voter_flow_find_by_mobile($conn, $mobile);
    if ($voter === null) {
        return ['status' => 'new'];
    }

    $hasVoted = voter_flow_sync_has_voted_if_complete($conn, $voter['voters_id']);

    $event = voter_flow_active_event($conn);
    $eventId = $event['event_id'] ?? null;

    return [
        'status' => 'exists',
        'has_voted' => $hasVoted,
        'has_data' => voter_flow_has_ballot_data($conn, $voter['voters_id'], $eventId) ? 1 : 0,
        'has_draft_code' => ($voter['draft_code'] !== null && $voter['draft_code'] !== '') ? 1 : 0,
        'voters_id' => $voter['voters_id'],
    ];
}

/**
 * Whether a new-voter OTP registration may proceed for this mobile.
 */
function voter_flow_can_register_mobile(mysqli $conn, string $mobileRaw): array
{
    $gate = voter_flow_mobile_gate_status($conn, $mobileRaw);
    if ($gate['status'] === 'error' || $gate['status'] === 'closed') {
        return $gate;
    }
    if ($gate['status'] === 'new') {
        return ['status' => 'ok'];
    }

    if ((int)($gate['has_voted'] ?? 0) === 1) {
        return [
            'status' => 'blocked',
            'message' => 'This mobile number has already submitted a vote.',
        ];
    }

    return [
        'status' => 'exists',
        'message' => 'Mobile number already registered. Please continue as an existing voter.',
    ];
}

/**
 * Count of votable award questions (on-ballot businesses, or freeform) for an event.
 */
function voter_flow_count_required_questions(mysqli $conn, int $eventId): int
{
    $catSql = admin_active_category_sql($conn, 'c');
    $qSql = admin_active_question_sql($conn, 'q');
    $votableSql = voter_flow_votable_question_sql($conn, 'q');
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM tbl_questions q
         INNER JOIN tbl_categories c ON q.category_id = c.category_id
         WHERE {$catSql} AND {$qSql} AND {$votableSql} AND c.event_id = ?"
    );
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

/**
 * Count of distinct questions with a finalized choice or freetext for this event.
 */
function voter_flow_count_finalized_questions(mysqli $conn, int $voterId, int $eventId): int
{
    $catSql = admin_active_category_sql($conn, 'c');
    $votableSql = voter_flow_votable_question_sql($conn, 'q');
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT question_id) AS finalized_total FROM (
            SELECT q.question_id
            FROM tbl_poll_choice pc
            INNER JOIN tbl_questions q ON pc.question_id = q.question_id
            INNER JOIN tbl_categories c ON q.category_id = c.category_id
            WHERE pc.voters_id = ? AND {$catSql} AND {$votableSql} AND c.event_id = ?
            UNION
            SELECT q.question_id
            FROM tbl_poll_freetext pf
            INNER JOIN tbl_questions q ON pf.question_id = q.question_id
            INNER JOIN tbl_categories c ON q.category_id = c.category_id
            WHERE pf.voters_id = ? AND {$catSql} AND {$votableSql} AND c.event_id = ?
        ) AS finalized_questions"
    );
    $stmt->bind_param('iiii', $voterId, $eventId, $voterId, $eventId);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['finalized_total'] ?? 0);
    $stmt->close();
    return $count;
}

/**
 * True when every required award question has a finalized answer (by data, not only the flag).
 */
function voter_flow_has_finalized_all_awards(mysqli $conn, int $voterId, ?int $eventId = null): bool
{
    if ($eventId === null) {
        $event = voter_flow_active_event($conn);
        $eventId = $event['event_id'] ?? null;
    }
    if ($eventId === null) {
        return false;
    }
    $total = voter_flow_count_required_questions($conn, $eventId);
    if ($total === 0) {
        return false;
    }
    return voter_flow_count_finalized_questions($conn, $voterId, $eventId) >= $total;
}

/**
 * Set has_voted = 1 when all award questions are finalized. Returns the flag after sync.
 */
function voter_flow_sync_has_voted_if_complete(mysqli $conn, int $voterId): int
{
    if (!voter_flow_has_finalized_all_awards($conn, $voterId)) {
        $stmt = $conn->prepare('SELECT has_voted FROM tbl_voters WHERE voters_id = ? LIMIT 1');
        $stmt->bind_param('i', $voterId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? (int)$row['has_voted'] : 0;
    }

    $stmt = $conn->prepare('UPDATE tbl_voters SET has_voted = 1 WHERE voters_id = ? AND has_voted = 0');
    $stmt->bind_param('i', $voterId);
    $stmt->execute();
    $stmt->close();

    return 1;
}

/**
 * Whether the voter has completed all awards (tbl_voters.has_voted = 1).
 */
function voter_flow_voter_has_submitted(mysqli $conn, int $voterId): bool
{
    voter_flow_sync_has_voted_if_complete($conn, $voterId);
    $stmt = $conn->prepare('SELECT has_voted FROM tbl_voters WHERE voters_id = ? LIMIT 1');
    $stmt->bind_param('i', $voterId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row !== null && (int)$row['has_voted'] === 1;
}

/**
 * True when the voter has a 4-digit access code saved (required before ballot work).
 */
function voter_flow_voter_has_access_code(mysqli $conn, int $voterId): bool
{
    $stmt = $conn->prepare('SELECT draft_code FROM tbl_voters WHERE voters_id = ? LIMIT 1');
    $stmt->bind_param('i', $voterId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row === null) {
        return false;
    }
    $code = trim((string)($row['draft_code'] ?? ''));
    return $code !== '' && preg_match('/^\d{4}$/', $code) === 1;
}

/**
 * JSON exit when ballot reads/writes are not allowed.
 */
function voter_flow_json_ballot_denied(mysqli $conn, int $voterId): void
{
    if (!voter_flow_is_voting_open($conn)) {
        voter_json_error('Voting is not open at this time.', 403);
    }
    if (!voter_flow_voter_has_access_code($conn, $voterId)) {
        voter_json_error(
            'Access code required. Set your 4-digit access code before saving or submitting votes.',
            403
        );
    }
    if (voter_flow_voter_has_submitted($conn, $voterId)) {
        voter_json_error('You have already submitted your vote.', 403);
    }
}
