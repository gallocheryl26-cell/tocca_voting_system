<?php
declare(strict_types=1);

/**
 * Public-ballot gate for establishments (tbl_choices.on_ballot).
 *
 * Approve still creates the business record. Voters only see it after staff
 * confirm remaining award titles for public voting.
 *
 * Column default is 1 so File Maintenance / import rows stay on the ballot
 * unless this helper explicitly clears the flag.
 */

if (!function_exists('ballot_status_ensure_column')) {
    function ballot_status_ensure_column(mysqli $conn): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        $res = @$conn->query("SHOW COLUMNS FROM tbl_choices LIKE 'on_ballot'");
        if ($res && $res->num_rows > 0) {
            $res->close();
            $available = true;
            return true;
        }
        if ($res) {
            $res->close();
        }

        @$conn->query(
            "ALTER TABLE tbl_choices
             ADD COLUMN on_ballot TINYINT(1) NOT NULL DEFAULT 1 AFTER status"
        );

        $res = @$conn->query("SHOW COLUMNS FROM tbl_choices LIKE 'on_ballot'");
        $available = ($res && $res->num_rows > 0);
        if ($res) {
            $res->close();
        }

        if ($available) {
            ballot_status_backfill_pending_approvals($conn);
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
        $hasMerged = false;
        $chk = @$conn->query("SHOW COLUMNS FROM tbl_nominations LIKE 'merged_choice_id'");
        if ($chk) {
            $hasMerged = $chk->num_rows > 0;
            $chk->close();
        }
        if (!$hasMerged) {
            return;
        }

        @$conn->query(
            "UPDATE tbl_choices c
             INNER JOIN tbl_nominations n ON n.merged_choice_id = c.choice_id
             SET c.on_ballot = 0
             WHERE n.status IN ('approved','merged')
               AND IFNULL(c.qr_sent, 0) = 0"
        );
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

        if (!ballot_status_set($conn, $choice_id, true)) {
            return ['ok' => false, 'message' => 'Failed to confirm this business for public voting.'];
        }

        return ['ok' => true, 'message' => 'This business is now on the public ballot.'];
    }
}
