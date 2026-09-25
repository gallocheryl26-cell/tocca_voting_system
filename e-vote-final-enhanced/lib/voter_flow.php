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
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $questionAlias) ?: 'q';

    try {
        category_voting_profile_ensure_schema($conn);
    } catch (Throwable $e) {
        error_log('voter_flow_votable_question_sql profile: ' . $e->getMessage());
    }

    $onBallotChoice = '';
    $onBallotNamed = '';
    $awardBallot = '';
    try {
        $onBallotChoice = ballot_status_sql_and($conn, 'ch_vote');
        $onBallotNamed = ballot_status_sql_and($conn, 'ch_be');
    } catch (Throwable $e) {
        error_log('voter_flow_votable_question_sql on_ballot: ' . $e->getMessage());
    }
    try {
        $awardBallot = ballot_award_sql_and($conn, 'qc_vote');
    } catch (Throwable $e) {
        error_log('voter_flow_votable_question_sql award ballot: ' . $e->getMessage());
    }

    $hasBallotBusiness = "EXISTS (
            SELECT 1
            FROM tbl_question_choices qc_vote
            INNER JOIN tbl_choices ch_vote ON ch_vote.choice_id = qc_vote.choice_id
            WHERE qc_vote.question_id = {$alias}.question_id
              AND COALESCE(ch_vote.status, 1) = 1
              {$onBallotChoice}
              {$awardBallot}
        )";

    $hasAnswerFields = admin_schema_column_exists($conn, 'tbl_questions', 'answer_fields');
    if (!$hasAnswerFields) {
        return "(COALESCE({$alias}.choice_type, 1) <> 1 OR {$hasBallotBusiness})";
    }

    $hasNamedBallotEntries = '0';
    if (admin_table_exists($conn, 'tbl_award_ballot_entries')) {
        $hasNamedBallotEntries = "EXISTS (
            SELECT 1
            FROM tbl_award_ballot_entries be_vote
            INNER JOIN tbl_choices ch_be ON ch_be.choice_id = be_vote.choice_id
            WHERE be_vote.question_id = {$alias}.question_id
              AND be_vote.is_active = 1
              AND COALESCE(ch_be.status, 1) = 1
              {$onBallotNamed}
        )";
    }

    $fields = "LOWER(TRIM(COALESCE({$alias}.answer_fields, '')))";
    $mediaProfile = '0';
    if (admin_schema_column_exists($conn, 'tbl_categories', 'voting_profile')) {
        $mediaProfile = "EXISTS (
            SELECT 1 FROM tbl_categories _vp_cat
            WHERE _vp_cat.category_id = {$alias}.category_id
              AND LOWER(TRIM(COALESCE(_vp_cat.voting_profile, ''))) = 'media'
        )";
    }
    $openTextName = '0';
    try {
        $openTextName = category_voting_profile_open_text_name_sql($alias);
    } catch (Throwable $e) {
        $openTextName = '0';
    }

    return "(
        {$fields} = 'song_singer'
        OR (
            {$fields} = 'product_business'
            AND ({$hasNamedBallotEntries} OR {$hasBallotBusiness})
        )
        OR (
            {$fields} NOT IN ('business_photo', 'product_business', 'song_singer')
            AND (
                COALESCE({$alias}.choice_type, 1) <> 1
                OR {$mediaProfile}
                OR {$openTextName}
            )
        )
        OR {$hasBallotBusiness}
    )";
}

/**
 * Active event categories for the public ballot.
 * Falls back to "any category with an award title" if the strict votable filter fails
 * (missing columns/tables after an admin/DB change).
 *
 * @return array{categories:list<array{id:int,name:string,voting_profile:string,field_labels:array}>,event_id:?int}
 */
function voter_flow_public_categories(mysqli $conn): array
{
    $archived = admin_unarchived_events_where($conn, 'e');
    $hasProfile = admin_schema_column_exists($conn, 'tbl_categories', 'voting_profile');
    $profileSelect = $hasProfile ? 'c.voting_profile' : "'business' AS voting_profile";

    $eventId = null;
    $event = voter_flow_active_event($conn);
    if ($event !== null) {
        $eventId = (int) $event['event_id'];
    }

    $run = static function (mysqli $conn, string $sql) {
        $result = $conn->query($sql);
        if ($result === false) {
            throw new RuntimeException($conn->error ?: 'Category query failed.');
        }
        $out = [];
        while ($row = $result->fetch_assoc()) {
            $profile = category_voting_profile_from_row($row);
            $out[] = [
                'id' => (int) $row['category_id'],
                'name' => (string) $row['category_name'],
                'voting_profile' => $profile,
                'field_labels' => category_voting_profile_labels($profile),
            ];
        }
        $result->free();
        return $out;
    };

    $base = "SELECT c.category_id, c.category_name, {$profileSelect}
            FROM tbl_categories AS c
            INNER JOIN tbl_events AS e ON c.event_id = e.event_id
            WHERE COALESCE(c.status, 1) = 1 AND e.is_active = 1 AND {$archived}";

    try {
        $votableSql = voter_flow_votable_question_sql($conn, 'q');
        $sql = $base . "
              AND EXISTS (
                    SELECT 1 FROM tbl_questions q
                    WHERE q.category_id = c.category_id AND {$votableSql}
              )
            ORDER BY c.category_name ASC";
        return ['categories' => $run($conn, $sql), 'event_id' => $eventId];
    } catch (Throwable $e) {
        error_log('voter_flow_public_categories strict: ' . $e->getMessage());
        $sql = $base . "
              AND EXISTS (
                    SELECT 1 FROM tbl_questions q
                    WHERE q.category_id = c.category_id
              )
            ORDER BY c.category_name ASC";
        return ['categories' => $run($conn, $sql), 'event_id' => $eventId];
    }
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
 * True if the voter has any saved draft or finalized answer for the requested
 * event.  Drafts from a previous event must not make a voter appear as drafted
 * in the current one.
 */
function voter_flow_has_ballot_data(mysqli $conn, int $voterId, ?int $eventId = null): bool
{
    if ($eventId === null) {
        $event = voter_flow_active_event($conn);
        $eventId = $event['event_id'] ?? null;
    }

    if ($eventId !== null) {
        $tables = [
            'SELECT 1 FROM tbl_draft_choice dc
                INNER JOIN tbl_questions q ON dc.question_id = q.question_id
                INNER JOIN tbl_categories c ON q.category_id = c.category_id
                WHERE dc.voters_id = ? AND c.event_id = ? LIMIT 1',
            'SELECT 1 FROM tbl_draft_freetext df
                INNER JOIN tbl_questions q ON df.question_id = q.question_id
                INNER JOIN tbl_categories c ON q.category_id = c.category_id
                WHERE df.voters_id = ? AND c.event_id = ? LIMIT 1',
        ];
        $tables[] = 'SELECT 1 FROM tbl_poll_choice pc
            INNER JOIN tbl_questions q ON pc.question_id = q.question_id
            INNER JOIN tbl_categories c ON q.category_id = c.category_id
            WHERE pc.voters_id = ? AND c.event_id = ? LIMIT 1';
        $tables[] = 'SELECT 1 FROM tbl_poll_freetext pf
            INNER JOIN tbl_questions q ON pf.question_id = q.question_id
            INNER JOIN tbl_categories c ON q.category_id = c.category_id
            WHERE pf.voters_id = ? AND c.event_id = ? LIMIT 1';
    } else {
        $tables = [
            'SELECT 1 FROM tbl_draft_choice WHERE voters_id = ? LIMIT 1',
            'SELECT 1 FROM tbl_draft_freetext WHERE voters_id = ? LIMIT 1',
        ];
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

    // Use the stored flag only. Completeness recount joins on_ballot and must
    // not run on the public "Check Number" / PIN-save path.
    $hasVoted = (int) ($voter['has_voted'] ?? 0);

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
 * Synchronize has_voted with the current event's actual finalized answers.
 *
 * The flag predates event-scoped voting. It can therefore be stale (for
 * example, a legacy mobile voter who completed an earlier event). Both mobile
 * and Google sign-in must use the current event data, so this writes 0 as well
 * as 1 when necessary.
 */
function voter_flow_sync_has_voted_if_complete(mysqli $conn, int $voterId): int
{
    try {
        $isComplete = voter_flow_has_finalized_all_awards($conn, $voterId) ? 1 : 0;
        $stmt = $conn->prepare(
            'UPDATE tbl_voters SET has_voted = ? WHERE voters_id = ? AND COALESCE(has_voted, 0) <> ?'
        );
        $stmt->bind_param('iii', $isComplete, $voterId, $isComplete);
        $stmt->execute();
        $stmt->close();
        return $isComplete;
    } catch (Throwable $e) {
        error_log('voter_flow_sync_has_voted_if_complete: ' . $e->getMessage());
        try {
            $stmt = $conn->prepare('SELECT has_voted FROM tbl_voters WHERE voters_id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $voterId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                return $row !== null ? (int)$row['has_voted'] : 0;
            }
        } catch (Throwable $ignored) {
        }
        return 0;
    }
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
 * True when this request has a Google-authenticated voter session, or the
 * legacy voter has a 4-digit access code saved.
 */
function voter_flow_voter_has_access_code(mysqli $conn, int $voterId): bool
{
    if (
        (int) ($_SESSION['voter_id'] ?? 0) === $voterId
        && ($_SESSION['voter_auth_provider'] ?? '') === 'google'
        && trim((string) ($_SESSION['firebase_uid'] ?? '')) !== ''
    ) {
        return true;
    }

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
            'Authentication required. Sign in with Google or enter your existing mobile access code.',
            403
        );
    }
    if (voter_flow_voter_has_submitted($conn, $voterId)) {
        voter_json_error('You have already submitted your vote.', 403);
    }
}
