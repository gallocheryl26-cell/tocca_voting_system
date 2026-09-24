<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_schema.php';

/**
 * Public-ballot gate for establishments (tbl_choices.on_ballot).
 *
 * Proceed to evaluation still creates the business record. Voters only see it after staff
 * confirm remaining award titles for public voting (that step emails the QR and vote link).
 *
 * Column default is 1 so File Maintenance / import rows stay on the ballot
 * unless this helper explicitly clears the flag.
 *
 * Do not use SHOW COLUMNS LIKE 'on_ballot': '_' is a SQL wildcard, so that
 * check can match a different column and then inject on_ballot into queries
 * when the real column is missing (PHP 8.3 then 500s the vote page).
 */

if (!function_exists('ballot_status_ensure_column')) {
    function ballot_status_ensure_column(mysqli $conn): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        if (admin_schema_column_exists($conn, 'tbl_choices', 'on_ballot')) {
            $available = true;
            return true;
        }

        try {
            $conn->query(
                "ALTER TABLE tbl_choices
                 ADD COLUMN on_ballot TINYINT(1) NOT NULL DEFAULT 1 AFTER status"
            );
        } catch (Throwable $e) {
            error_log('ballot_status_ensure_column: ' . $e->getMessage());
        }

        $available = admin_schema_column_exists($conn, 'tbl_choices', 'on_ballot', true);
        if ($available) {
            try {
                ballot_status_backfill_pending_approvals($conn);
            } catch (Throwable $e) {
                error_log('ballot_status_backfill: ' . $e->getMessage());
            }
        }

        return (bool) $available;
    }
}

if (!function_exists('ballot_status_backfill_pending_approvals')) {
    /**
     * Approved registrations that have not been emailed a QR yet are still
     * under TWG evaluation — keep them off the public ballot.
     */
    function ballot_status_backfill_pending_approvals(mysqli $conn): void
    {
        if (!admin_schema_column_exists($conn, 'tbl_nominations', 'merged_choice_id')) {
            return;
        }

        try {
            $conn->query(
                "UPDATE tbl_choices c
                 INNER JOIN tbl_nominations n ON n.merged_choice_id = c.choice_id
                 SET c.on_ballot = 0
                 WHERE n.status IN ('approved','merged')
                   AND IFNULL(c.qr_sent, 0) = 0"
            );
        } catch (Throwable $e) {
            error_log('ballot_status_backfill_pending_approvals: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ballot_status_sql_and')) {
    /** Extra AND clause for voter queries. Empty string if the column is unavailable. */
    function ballot_status_sql_and(mysqli $conn, string $alias = 'c'): string
    {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'c';
        if (!ballot_status_ensure_column($conn)) {
            return '';
        }
        return " AND {$alias}.on_ballot = 1";
    }
}

if (!function_exists('ballot_status_flag')) {
    /** @return bool|null true/false, or null if the row/column cannot be read */
    function ballot_status_flag(mysqli $conn, int $choice_id): ?bool
    {
        if ($choice_id <= 0 || !ballot_status_ensure_column($conn)) {
            return null;
        }
        $st = $conn->prepare('SELECT on_ballot FROM tbl_choices WHERE choice_id = ? LIMIT 1');
        if (!$st) {
            return null;
        }
        $st->bind_param('i', $choice_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            return null;
        }
        return ((int) ($row['on_ballot'] ?? 0)) === 1;
    }
}

if (!function_exists('ballot_status_is_released')) {
    function ballot_status_is_released(mysqli $conn, int $choice_id): bool
    {
        $flag = ballot_status_flag($conn, $choice_id);
        return $flag === null ? true : $flag;
    }
}

if (!function_exists('ballot_status_set')) {
    function ballot_status_set(mysqli $conn, int $choice_id, bool $on_ballot): bool
    {
        if ($choice_id <= 0 || !ballot_status_ensure_column($conn)) {
            return false;
        }
        $flag = $on_ballot ? 1 : 0;
        $st = $conn->prepare('UPDATE tbl_choices SET on_ballot = ? WHERE choice_id = ?');
        if (!$st) {
            return false;
        }
        $st->bind_param('ii', $flag, $choice_id);
        $ok = $st->execute();
        $st->close();
        return (bool) $ok;
    }
}

if (!function_exists('ballot_status_release_choice')) {
    /**
     * @return array{ok:bool, message:string}
     */
    function ballot_status_release_choice(mysqli $conn, int $choice_id): array
    {
        if ($choice_id <= 0) {
            return ['ok' => false, 'message' => 'Invalid business.'];
        }
        if (!ballot_status_ensure_column($conn)) {
            return ['ok' => false, 'message' => 'Could not update ballot status.'];
        }

        $st = $conn->prepare('SELECT choice_id, status, on_ballot FROM tbl_choices WHERE choice_id = ? LIMIT 1');
        if (!$st) {
            return ['ok' => false, 'message' => 'Could not load business.'];
        }
        $st->bind_param('i', $choice_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Business not found.'];
        }
        if ((int) ($row['status'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'Activate this business before confirming it for public voting.'];
        }

        $alreadyOnBallot = ((int) ($row['on_ballot'] ?? 0)) === 1;
        $twgBallotFile = __DIR__ . '/twg_ballot.php';
        if (is_file($twgBallotFile)) {
            require_once $twgBallotFile;
        }
        if (function_exists('twg_ballot_apply_top10')) {
            $applied = twg_ballot_apply_top10($conn, $choice_id);
            if (!$applied['ok']) {
                return $applied + ['already_on_ballot' => $alreadyOnBallot];
            }
            return [
                'ok' => true,
                'message' => (string) ($applied['message'] ?? 'This business is now on the public ballot.'),
                'already_on_ballot' => $alreadyOnBallot,
                'eligibility' => $applied['eligibility'] ?? null,
                'top10_count' => $applied['top10_count'] ?? 0,
                'not_top10_count' => $applied['not_top10_count'] ?? 0,
            ];
        }

        $cnt = 0;
        $q = $conn->prepare('SELECT COUNT(*) AS cnt FROM tbl_question_choices WHERE choice_id = ?');
        if ($q) {
            $q->bind_param('i', $choice_id);
            $q->execute();
            $cntRow = $q->get_result()->fetch_assoc();
            $q->close();
            $cnt = (int) ($cntRow['cnt'] ?? 0);
        }
        if ($cnt < 1) {
            return ['ok' => false, 'message' => 'This business has no remaining award titles. Finish evaluation first, then confirm for public voting.'];
        }

        if (!$alreadyOnBallot && !ballot_status_set($conn, $choice_id, true)) {
            return ['ok' => false, 'message' => 'Failed to confirm this business for public voting.'];
        }

        return [
            'ok' => true,
            'message' => 'This business is now on the public ballot.',
            'already_on_ballot' => $alreadyOnBallot,
        ];
    }
}

if (!function_exists('ballot_award_ensure_column')) {
    function ballot_award_ensure_column(mysqli $conn): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        if (admin_schema_column_exists($conn, 'tbl_question_choices', 'on_ballot')) {
            $available = true;
            return true;
        }

        try {
            $conn->query(
                "ALTER TABLE tbl_question_choices
                 ADD COLUMN on_ballot TINYINT(1) NOT NULL DEFAULT 0 AFTER choice_id"
            );
        } catch (Throwable $e) {
            error_log('ballot_award_ensure_column: ' . $e->getMessage());
        }

        $available = admin_schema_column_exists($conn, 'tbl_question_choices', 'on_ballot', true);
        return (bool) $available;
    }
}

if (!function_exists('ballot_award_sql_and')) {
    /** Extra AND clause for voter queries that join tbl_question_choices. */
    function ballot_award_sql_and(mysqli $conn, string $alias = 'qc'): string
    {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'qc';
        if (!ballot_award_ensure_column($conn)) {
            return '';
        }
        return " AND {$alias}.on_ballot = 1";
    }
}

if (!function_exists('ballot_award_set_for_choice')) {
    /**
     * @param list<int> $on_question_ids
     */
    function ballot_award_set_for_choice(mysqli $conn, int $choice_id, array $on_question_ids): bool
    {
        if ($choice_id <= 0 || !ballot_award_ensure_column($conn)) {
            return false;
        }
        $on = [];
        foreach ($on_question_ids as $qid) {
            $qid = (int) $qid;
            if ($qid > 0) {
                $on[$qid] = true;
            }
        }
        $clear = $conn->prepare('UPDATE tbl_question_choices SET on_ballot = 0 WHERE choice_id = ?');
        if (!$clear) {
            return false;
        }
        $clear->bind_param('i', $choice_id);
        $ok = $clear->execute();
        $clear->close();
        if (!$ok) {
            return false;
        }
        if ($on === []) {
            return true;
        }
        $set = $conn->prepare('UPDATE tbl_question_choices SET on_ballot = 1 WHERE choice_id = ? AND question_id = ?');
        if (!$set) {
            return false;
        }
        foreach (array_keys($on) as $qid) {
            $set->bind_param('ii', $choice_id, $qid);
            if (!$set->execute()) {
                $set->close();
                return false;
            }
        }
        $set->close();
        return true;
    }
}
