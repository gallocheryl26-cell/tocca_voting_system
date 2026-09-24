<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_schema.php';
require_once __DIR__ . '/freetext_vote.php';
require_once __DIR__ . '/award_answer_fields.php';
if (is_file(__DIR__ . '/admin_active_event.php')) {
    require_once __DIR__ . '/admin_active_event.php';
}
require_once __DIR__ . '/twg_criteria.php';
if (is_file(__DIR__ . '/ballot_status.php')) {
    require_once __DIR__ . '/ballot_status.php';
}
if (is_file(__DIR__ . '/twg_rubric.php')) {
    require_once __DIR__ . '/twg_rubric.php';
}

/**
 * Official TOCCA ranking:
 *   TWG average (0–100) = mean of judges who scored that award
 *   Community score (0–10) = (votes ÷ total votes in the award) × 10
 *   Final score = ((TWG ÷ 10) × 40%) + (community score × 60%)
 *   Standing / Top 5 = rank by final score (ties share a dense rank)
 *   Food and Service use a TWG Top 5 shortlist for the public ballot.
 *   Feelings skips shortlisting. TWG averages only judges who scored that award.
 */

if (!function_exists('twg_choice_scores_locked')) {
    /**
     * Scores stay editable after save. They lock only after the business is
     * confirmed for public voting (on the ballot).
     */
    function twg_choice_scores_locked(mysqli $conn, int $choiceId): bool
    {
        if ($choiceId <= 0) {
            return false;
        }
        if (function_exists('ballot_status_flag')) {
            return ballot_status_flag($conn, $choiceId) === true;
        }
        return false;
    }
}

if (!function_exists('twg_score_min')) {
    function twg_score_min(): float
    {
        return 0.0;
    }
}

if (!function_exists('twg_score_max')) {
    function twg_score_max(): float
    {
        return 100.0;
    }
}

if (!function_exists('twg_score_range_message')) {
    function twg_score_range_message(): string
    {
        return 'Score must be from 0 to 100.';
    }
}

if (!function_exists('twg_average_on_ten')) {
    /** Convert a 0–100 TWG average onto the 0–10 scale used with community 0–10. */
    function twg_average_on_ten(?float $avg): ?float
    {
        if ($avg === null) {
            return null;
        }
        $max = twg_score_max();
        if ($max <= 0) {
            return $avg;
        }
        $scaled = $avg * (10.0 / $max);
        return function_exists('results_formula_round')
            ? results_formula_round($scaled, 2)
            : round($scaled, 2);
    }
}

if (!function_exists('twg_member_scores_scale_legacy_to_100')) {
    /**
     * One-time: stored judge totals used to be 1–10. If every saved score is
     * still on that scale, multiply by 10 so 8 becomes 80.
     */
    function twg_member_scores_scale_legacy_to_100(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $max = 0.0;
        $checks = [
            ['tbl_twg_member_scores', 'score'],
            ['tbl_twg_entry_member_scores', 'score'],
            ['tbl_twg_scores', 'twg_average'],
        ];
        foreach ($checks as [$table, $col]) {
            $res = $conn->query('SELECT MAX(`' . $col . '`) AS m FROM `' . $table . '`');
            if ($res) {
                $max = max($max, (float) ($res->fetch_assoc()['m'] ?? 0));
            }
        }
        if ($max <= 0 || $max > 10) {
            return;
        }
        $conn->query('UPDATE tbl_twg_member_scores SET score = ROUND(score * 10, 2)');
        $conn->query('UPDATE tbl_twg_entry_member_scores SET score = ROUND(score * 10, 2)');
        $conn->query('UPDATE tbl_twg_scores SET twg_average = ROUND(twg_average * 10, 2)');
    }
}

if (!function_exists('twg_category_uses_shortlist')) {
    /** Food and Service shortlist to Top 5. Feelings and Informal Sector do not. */
    function twg_category_uses_shortlist(string $categoryName): bool
    {
        $n = mb_strtolower(trim($categoryName));
        if ($n === '') {
            return false;
        }
        if (str_contains($n, 'feeling') || str_contains($n, 'informal')) {
            return false;
        }
        return true;
    }
}

if (!function_exists('twg_panel_members_for_award')) {
    /**
     * Judges who have entered at least one score on this award.
     * Completeness and the average use this panel, so a blank is not a zero
     * and extra unused judge columns do not block already-scored titles.
     *
     * @param list<array{key?:string}> $members
     * @param array<int|string, array<string, mixed>> $titleByChoice  [choice_id][member_key]
     * @param array<int|string, array<int|string, array<string, mixed>>> $entryByChoice  [choice_id][entry_id][member_key]
     * @return list<array{key?:string}>
     */
    function twg_panel_members_for_award(array $members, array $titleByChoice, array $entryByChoice): array
    {
        $seen = [];
        foreach ($titleByChoice as $byKey) {
            if (!is_array($byKey)) {
                continue;
            }
            foreach ($byKey as $key => $val) {
                if ($val === null || $val === '') {
                    continue;
                }
                $k = strtolower(trim((string) $key));
                if ($k !== '') {
                    $seen[$k] = true;
                }
            }
        }
        foreach ($entryByChoice as $byEntry) {
            if (!is_array($byEntry)) {
                continue;
            }
            foreach ($byEntry as $byKey) {
                if (!is_array($byKey)) {
                    continue;
                }
                foreach ($byKey as $key => $val) {
                    if ($val === null || $val === '') {
                        continue;
                    }
                    $k = strtolower(trim((string) $key));
                    if ($k !== '') {
                        $seen[$k] = true;
                    }
                }
            }
        }
        if ($seen === []) {
            return $members;
        }
        $out = [];
        foreach ($members as $member) {
            $k = strtolower(trim((string) ($member['key'] ?? '')));
            if ($k !== '' && isset($seen[$k])) {
                $out[] = $member;
            }
        }
        return $out !== [] ? $out : $members;
    }
}

if (!function_exists('twg_panel_members_by_question')) {
    /**
     * @param list<array{key?:string}> $members
     * @param array<int, array<int, array<string, mixed>>> $titleScoreMap  [question_id][choice_id][member_key]
     * @param array<int, array<int, array<int, array<string, mixed>>>> $entryScoreMap  [question_id][choice_id][entry_id][member_key]
     * @return array<int, list<array{key?:string}>>
     */
    function twg_panel_members_by_question(array $members, array $titleScoreMap, array $entryScoreMap): array
    {
        $qids = [];
        foreach (array_keys($titleScoreMap) as $qid) {
            $qids[(int) $qid] = true;
        }
        foreach (array_keys($entryScoreMap) as $qid) {
            $qids[(int) $qid] = true;
        }
        $out = [];
        foreach (array_keys($qids) as $qid) {
            $out[$qid] = twg_panel_members_for_award(
                $members,
                $titleScoreMap[$qid] ?? [],
                $entryScoreMap[$qid] ?? []
            );
        }
        return $out;
    }
}

if (!function_exists('results_formula_ensure_schema')) {
    function results_formula_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $conn->query(
            "CREATE TABLE IF NOT EXISTS tbl_twg_scores (
                question_id INT NOT NULL,
                choice_id INT NOT NULL,
                twg_average DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (question_id, choice_id),
                KEY idx_twg_question (question_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    }
}

if (!function_exists('results_formula_save_twg')) {
    function results_formula_save_twg(mysqli $conn, int $question_id, int $choice_id, float $twg_average): bool
    {
        if ($question_id <= 0 || $choice_id <= 0) {
            return false;
        }
        if ($twg_average < 0) {
            $twg_average = 0;
        }
        if ($twg_average > twg_score_max()) {
            $twg_average = twg_score_max();
        }
        results_formula_ensure_schema($conn);
        $st = $conn->prepare(
            'INSERT INTO tbl_twg_scores (question_id, choice_id, twg_average)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE twg_average = VALUES(twg_average), updated_at = NOW()'
        );
        if (!$st) {
            return false;
        }
        $st->bind_param('iid', $question_id, $choice_id, $twg_average);
        $ok = $st->execute();
        $st->close();
        return (bool) $ok;
    }
}

if (!function_exists('results_formula_round')) {
    function results_formula_round(float $n, int $places = 2): float
    {
        return round($n, $places);
    }
}

if (!function_exists('results_formula_poll_choice_has_ballot_entry')) {
    function results_formula_poll_choice_has_ballot_entry(mysqli $conn): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $res = $conn->query("SHOW COLUMNS FROM `tbl_poll_choice` LIKE 'ballot_entry_id'");
        $has = $res instanceof mysqli_result && $res->num_rows > 0;
        if ($res instanceof mysqli_result) {
            $res->free();
        }

        return $has;
    }
}

if (!function_exists('results_formula_row_identity_key')) {
    /** @param array<string,mixed> $row */
    function results_formula_row_identity_key(array $row): string
    {
        $eid = (int) ($row['ballot_entry_id'] ?? 0);
        if ($eid > 0) {
            return 'e:' . $eid;
        }
        if (!empty($row['choice_id'])) {
            return 'c:' . (int) $row['choice_id'];
        }

        return 'f:' . (string) ($row['choice_name'] ?? '');
    }
}

if (!function_exists('results_formula_song_freetext_sql')) {
    /** Only typed songs count as freetext votes. Named-entry display copies do not. */
    function results_formula_song_freetext_sql(string $questionAlias = 'q'): string
    {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $questionAlias) ?: 'q';

        return "({$alias}.answer_fields = 'song_singer'
            OR (COALESCE({$alias}.choice_type, 1) = 0
                AND COALESCE({$alias}.answer_fields, '') IN ('', 'song_singer')))";
    }
}

if (!function_exists('results_formula_unique_cast_votes')) {
    /**
     * One Cast per voter per award: poll_choice ∪ song freetext (not named-entry copies).
     *
     * @return array{
     *   total:int,
     *   by_category:array<string,int>,
     *   top_by_category:array<string,array{choice_name:string,vote_count:int}>
     * }
     */
    function results_formula_unique_cast_votes(mysqli $conn, int $eventId): array
    {
        $out = ['total' => 0, 'by_category' => [], 'top_by_category' => []];
        if ($eventId <= 0) {
            return $out;
        }
        $helpers = __DIR__ . '/award_entry_helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
        $songSql = results_formula_song_freetext_sql('q');
        $sql = "
            SELECT c.category_name, t.question_id, t.voters_id
            FROM (
                SELECT pc.question_id, pc.voters_id
                FROM tbl_poll_choice pc
                UNION
                SELECT pf.question_id, pf.voters_id
                FROM tbl_poll_freetext pf
                INNER JOIN tbl_questions q ON q.question_id = pf.question_id
                WHERE {$songSql}
            ) t
            INNER JOIN tbl_questions q ON q.question_id = t.question_id
            INNER JOIN tbl_categories c ON c.category_id = q.category_id
            WHERE c.event_id = ?
        ";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('i', $eventId);
            $st->execute();
            $res = $st->get_result();
            $seen = [];
            while ($row = $res->fetch_assoc()) {
                $cat = (string) ($row['category_name'] ?? '');
                $key = (int) ($row['voters_id'] ?? 0) . ':' . (int) ($row['question_id'] ?? 0);
                if ($cat === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out['by_category'][$cat] = (int) ($out['by_category'][$cat] ?? 0) + 1;
                $out['total']++;
            }
            $st->close();
        }

        $ballotSelect = 'NULL AS ballot_entry_id, NULL AS entry_name';
        $ballotJoin = '';
        if (results_formula_poll_choice_has_ballot_entry($conn)) {
            $ballotSelect = 'pc.ballot_entry_id, be.entry_name';
            $ballotJoin = 'LEFT JOIN tbl_award_ballot_entries be ON be.ballot_entry_id = pc.ballot_entry_id';
        }
        $topSql = "
            SELECT c.category_name, ch.choice_name, {$ballotSelect},
                   COUNT(DISTINCT pc.voters_id) AS vote_count
            FROM tbl_poll_choice pc
            INNER JOIN tbl_questions q ON pc.question_id = q.question_id
            INNER JOIN tbl_categories c ON q.category_id = c.category_id
            INNER JOIN tbl_choices ch ON pc.choice_id = ch.choice_id
            {$ballotJoin}
            WHERE c.event_id = ?
            GROUP BY c.category_id, c.category_name, pc.choice_id, ch.choice_name,
                     pc.ballot_entry_id, be.entry_name
        ";
        if (!results_formula_poll_choice_has_ballot_entry($conn)) {
            $topSql = "
                SELECT c.category_name, ch.choice_name, NULL AS ballot_entry_id, NULL AS entry_name,
                       COUNT(DISTINCT pc.voters_id) AS vote_count
                FROM tbl_poll_choice pc
                INNER JOIN tbl_questions q ON pc.question_id = q.question_id
                INNER JOIN tbl_categories c ON q.category_id = c.category_id
                INNER JOIN tbl_choices ch ON pc.choice_id = ch.choice_id
                WHERE c.event_id = ?
                GROUP BY c.category_id, c.category_name, pc.choice_id, ch.choice_name
            ";
        }
        $st = $conn->prepare($topSql);
        if ($st) {
            $st->bind_param('i', $eventId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $cat = (string) ($row['category_name'] ?? '');
                $business = (string) ($row['choice_name'] ?? '');
                $entry = trim((string) ($row['entry_name'] ?? ''));
                $label = ($entry !== '' && function_exists('award_entry_display_label'))
                    ? award_entry_display_label($business, $entry)
                    : $business;
                $count = (int) ($row['vote_count'] ?? 0);
                if ($cat === '' || $count <= 0) {
                    continue;
                }
                $prev = $out['top_by_category'][$cat]['vote_count'] ?? 0;
                if ($count > $prev) {
                    $out['top_by_category'][$cat] = [
                        'choice_name' => $label,
                        'vote_count' => $count,
                    ];
                }
            }
            $st->close();
        }

        $songSql = results_formula_song_freetext_sql('q');
        $st = $conn->prepare(
            "SELECT c.category_name, pf.freetext, COUNT(DISTINCT pf.voters_id) AS vote_count
             FROM tbl_poll_freetext pf
             INNER JOIN tbl_questions q ON pf.question_id = q.question_id
             INNER JOIN tbl_categories c ON q.category_id = c.category_id
             WHERE c.event_id = ? AND {$songSql}
             GROUP BY c.category_id, c.category_name, pf.freetext"
        );
        if ($st) {
            $st->bind_param('i', $eventId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $cat = (string) ($row['category_name'] ?? '');
                $label = trim((string) ($row['freetext'] ?? ''));
                $count = (int) ($row['vote_count'] ?? 0);
                if ($cat === '' || $label === '' || $count <= 0) {
                    continue;
                }
                $prev = $out['top_by_category'][$cat]['vote_count'] ?? 0;
                if ($count > $prev) {
                    $out['top_by_category'][$cat] = [
                        'choice_name' => $label,
                        'vote_count' => $count,
                    ];
                }
            }
            $st->close();
        }

        return $out;
    }
}

if (!function_exists('results_formula_fetch_for_award')) {
    /**
     * @return array{
     *   results: list<array<string,mixed>>,
     *   total_votes: int,
     *   nominee_count: int,
     *   twg_entered: int,
     *   leader: ?array<string,mixed>,
     *   twg_leader: ?array<string,mixed>,
     *   twg_members: list<array{key:string,label:string,short:string}>
     * }
     */
    function results_formula_fetch_for_award(mysqli $conn, int $event_id, int $question_id): array
    {
        results_formula_ensure_schema($conn);
        award_answer_fields_ensure_schema($conn);
        $answerFields = award_answer_fields_for_question($conn, $question_id);
        $hasOnBallot = false;
        if (is_file(__DIR__ . '/ballot_status.php')) {
            require_once __DIR__ . '/ballot_status.php';
            $hasOnBallot = function_exists('ballot_status_ensure_column') && ballot_status_ensure_column($conn);
        }

        $onBallotSelect = $hasOnBallot ? ', c.on_ballot' : ', 1 AS on_ballot';
        $awardName = award_answer_fields_question_name($conn, $question_id);
        $usesNamedEntries = award_answer_fields_uses_ballot_entries($answerFields, $awardName)
            && results_formula_poll_choice_has_ballot_entry($conn);
        $helpers = __DIR__ . '/award_entry_helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }

        $businessMeta = [];
        $sql = "
            SELECT c.choice_id, c.choice_name, c.status{$onBallotSelect}
            FROM tbl_question_choices qc
            INNER JOIN tbl_choices c ON c.choice_id = qc.choice_id
            WHERE qc.question_id = ? AND c.event_id = ?
              AND " . twg_award_link_matches_remaining_sql($conn, 'c.choice_id', 'qc.question_id') . "
        ";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('ii', $question_id, $event_id);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $cid = (int) ($row['choice_id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $businessMeta[$cid] = [
                    'choice_id' => $cid,
                    'choice_name' => (string) ($row['choice_name'] ?? 'Unknown'),
                    'status' => (int) ($row['status'] ?? 0),
                    'on_ballot' => (int) ($row['on_ballot'] ?? 0) === 1,
                ];
            }
            $st->close();
        }

        $voteByChoice = [];
        $voteByEntry = [];
        $entryVoteChoice = [];
        if ($usesNamedEntries) {
            $sql = "
                SELECT p.choice_id, COALESCE(p.ballot_entry_id, 0) AS ballot_entry_id,
                       COUNT(DISTINCT p.voters_id) AS vote_count
                FROM tbl_poll_choice p
                JOIN tbl_questions q ON p.question_id = q.question_id
                JOIN tbl_categories c ON q.category_id = c.category_id
                WHERE p.question_id = ? AND c.event_id = ?
                GROUP BY p.choice_id, COALESCE(p.ballot_entry_id, 0)
            ";
        } else {
            $sql = "
                SELECT p.choice_id, 0 AS ballot_entry_id,
                       COUNT(DISTINCT p.voters_id) AS vote_count
                FROM tbl_poll_choice p
                JOIN tbl_questions q ON p.question_id = q.question_id
                JOIN tbl_categories c ON q.category_id = c.category_id
                WHERE p.question_id = ? AND c.event_id = ?
                GROUP BY p.choice_id
            ";
        }
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('ii', $question_id, $event_id);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $cid = (int) ($row['choice_id'] ?? 0);
                $eid = (int) ($row['ballot_entry_id'] ?? 0);
                $count = (int) ($row['vote_count'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                if ($eid > 0) {
                    $voteByEntry[$eid] = ($voteByEntry[$eid] ?? 0) + $count;
                    $entryVoteChoice[$eid] = $cid;
                } else {
                    $voteByChoice[$cid] = ($voteByChoice[$cid] ?? 0) + $count;
                }
            }
            $st->close();
        }

        $sql = "
            SELECT p.choice_id, COALESCE(ch.choice_name, 'Unknown/Deleted') AS choice_name
            FROM tbl_poll_choice p
            LEFT JOIN tbl_choices ch ON p.choice_id = ch.choice_id
            JOIN tbl_questions q ON p.question_id = q.question_id
            JOIN tbl_categories c ON q.category_id = c.category_id
            WHERE p.question_id = ? AND c.event_id = ?
            GROUP BY p.choice_id, choice_name
        ";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('ii', $question_id, $event_id);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $cid = (int) ($row['choice_id'] ?? 0);
                if ($cid <= 0 || isset($businessMeta[$cid])) {
                    continue;
                }
                $businessMeta[$cid] = [
                    'choice_id' => $cid,
                    'choice_name' => (string) ($row['choice_name'] ?? 'Unknown'),
                    'status' => 0,
                    'on_ballot' => false,
                ];
            }
            $st->close();
        }

        $entryRowsByChoice = [];
        if ($question_id > 0 && function_exists('award_entry_score_rows_for_questions')) {
            $byQ = award_entry_score_rows_for_questions($conn, [$question_id]);
            $entryRowsByChoice = is_array($byQ[$question_id] ?? null) ? $byQ[$question_id] : [];
        }

        $rows = [];
        $seenEntry = [];
        $pushBusinessRow = static function (
            array $meta,
            int $votes,
            ?int $ballotEntryId,
            string $entryName,
            string $entryKind
        ) use (&$rows): void {
            $cid = (int) ($meta['choice_id'] ?? 0);
            $business = (string) ($meta['choice_name'] ?? 'Unknown');
            $display = $business;
            if ($ballotEntryId && $entryName !== '' && function_exists('award_entry_display_label')) {
                $display = award_entry_display_label($business, $entryName);
            }
            $rows[] = [
                'choice_id' => $cid > 0 ? $cid : null,
                'ballot_entry_id' => $ballotEntryId,
                'choice_name' => $display,
                'vote_count' => $votes,
                'status' => (int) ($meta['status'] ?? 0),
                'on_ballot' => !empty($meta['on_ballot']),
                'is_freetext' => false,
                'entry_kind' => $entryKind,
            ];
        };

        foreach ($businessMeta as $cid => $meta) {
            $entries = is_array($entryRowsByChoice[$cid] ?? null) ? $entryRowsByChoice[$cid] : [];
            if ($usesNamedEntries && $entries !== []) {
                foreach ($entries as $entry) {
                    $eid = (int) ($entry['ballot_entry_id'] ?? 0);
                    if ($eid <= 0) {
                        continue;
                    }
                    $seenEntry[$eid] = true;
                    $pushBusinessRow(
                        $meta,
                        (int) ($voteByEntry[$eid] ?? 0),
                        $eid,
                        (string) ($entry['entry_name'] ?? ''),
                        (string) ($entry['entry_kind'] ?? 'product')
                    );
                }
                $orphan = (int) ($voteByChoice[$cid] ?? 0);
                if ($orphan > 0) {
                    $pushBusinessRow($meta, $orphan, null, '', '');
                }
                continue;
            }
            $votes = (int) ($voteByChoice[$cid] ?? 0);
            if ($usesNamedEntries) {
                foreach ($voteByEntry as $eid => $count) {
                    if ((int) ($entryVoteChoice[$eid] ?? 0) === $cid) {
                        $votes += (int) $count;
                    }
                }
            }
            $pushBusinessRow($meta, $votes, null, '', '');
        }

        if ($usesNamedEntries) {
            foreach ($voteByEntry as $eid => $count) {
                if (isset($seenEntry[$eid])) {
                    continue;
                }
                $cid = (int) ($entryVoteChoice[$eid] ?? 0);
                $meta = $businessMeta[$cid] ?? [
                    'choice_id' => $cid,
                    'choice_name' => 'Unknown',
                    'status' => 0,
                    'on_ballot' => false,
                ];
                $entryName = '';
                $entryKind = 'product';
                $st = $conn->prepare(
                    'SELECT entry_name, entry_kind, choice_id FROM tbl_award_ballot_entries WHERE ballot_entry_id = ? LIMIT 1'
                );
                if ($st) {
                    $st->bind_param('i', $eid);
                    $st->execute();
                    $found = $st->get_result()->fetch_assoc();
                    $st->close();
                    if ($found) {
                        $entryName = (string) ($found['entry_name'] ?? '');
                        $entryKind = (string) ($found['entry_kind'] ?? 'product');
                        if ($cid <= 0) {
                            $cid = (int) ($found['choice_id'] ?? 0);
                            $meta['choice_id'] = $cid;
                        }
                    }
                }
                $seenEntry[$eid] = true;
                $pushBusinessRow($meta, (int) $count, (int) $eid, $entryName, $entryKind);
            }
        }

        if (award_answer_fields_uses_open_text($answerFields)) {
            $sql = "
                SELECT pf.freetext AS choice_name, COUNT(DISTINCT pf.voters_id) AS vote_count
                FROM tbl_poll_freetext pf
                JOIN tbl_questions q ON pf.question_id = q.question_id
                JOIN tbl_categories c ON q.category_id = c.category_id
                WHERE pf.question_id = ? AND c.event_id = ?
                GROUP BY pf.freetext
            ";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param('ii', $question_id, $event_id);
                $st->execute();
                $res = $st->get_result();
                $freetextRows = [];
                while ($row = $res->fetch_assoc()) {
                    $freetextRows[] = [
                        'choice_id' => null,
                        'ballot_entry_id' => null,
                        'choice_name' => (string) ($row['choice_name'] ?? ''),
                        'vote_count' => (int) ($row['vote_count'] ?? 0),
                        'status' => 0,
                        'on_ballot' => false,
                        'is_freetext' => true,
                    ];
                }
                $st->close();
                $mergedRows = award_answer_fields_is_place_award($awardName)
                    ? freetext_vote_merge_rows_single($freetextRows)
                    : freetext_vote_merge_rows($freetextRows);
                foreach ($mergedRows as $merged) {
                    $rows[] = $merged;
                }
            }
        }

        twg_member_scores_ensure_schema($conn);
        $twgMembers = twg_member_definitions($conn, $event_id);
        $twgMap = [];
        $twgScoreMap = [];
        $ids = [];
        foreach ($rows as $row) {
            if (!empty($row['choice_id'])) {
                $ids[] = (int) $row['choice_id'];
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $types = 'i' . str_repeat('i', count($ids));
            $sql = "SELECT choice_id, twg_average FROM tbl_twg_scores WHERE question_id = ? AND choice_id IN ($ph)";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param($types, $question_id, ...$ids);
                $st->execute();
                $res = $st->get_result();
                while ($row = $res->fetch_assoc()) {
                    $twgMap[(int) $row['choice_id']] = (float) $row['twg_average'];
                }
                $st->close();
            }

            $sql = "SELECT choice_id, member_key, score
                    FROM tbl_twg_member_scores
                    WHERE question_id = ? AND choice_id IN ($ph)";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param($types, $question_id, ...$ids);
                $st->execute();
                $res = $st->get_result();
                while ($row = $res->fetch_assoc()) {
                    $cid = (int) ($row['choice_id'] ?? 0);
                    $key = (string) ($row['member_key'] ?? '');
                    if ($cid > 0 && $key !== '') {
                        $twgScoreMap[$cid][$key] = results_formula_round((float) $row['score'], 2);
                    }
                }
                $st->close();
            }
        }

        $entryScoreMap = [];
        if ($question_id > 0) {
            $entryScoreMap = twg_fetch_entry_member_score_map($conn, [$question_id]);
        }

        $totalVotes = 0;
        foreach ($rows as $row) {
            $totalVotes += (int) $row['vote_count'];
        }

        $awardPanel = twg_panel_members_for_award(
            $twgMembers,
            $twgScoreMap,
            $entryScoreMap[$question_id] ?? []
        );

        $twgEntered = 0;
        foreach ($rows as &$row) {
            $votes = (int) $row['vote_count'];
            $share = $totalVotes > 0 ? ($votes / $totalVotes) * 100 : 0.0;
            $community = $totalVotes > 0 ? ($votes / $totalVotes) * 10 : 0.0;
            $cid = (int) ($row['choice_id'] ?? 0);
            $eid = (int) ($row['ballot_entry_id'] ?? 0);
            $titleScores = [];
            foreach ($awardPanel as $member) {
                $titleScores[$member['key']] = ($cid > 0) ? ($twgScoreMap[$cid][$member['key']] ?? null) : null;
            }
            $allEntries = ($cid > 0 && is_array($entryRowsByChoice[$cid] ?? null)) ? $entryRowsByChoice[$cid] : [];
            if ($eid > 0) {
                $entries = array_values(array_filter(
                    $allEntries,
                    static fn($entry): bool => (int) ($entry['ballot_entry_id'] ?? 0) === $eid
                ));
                if ($entries === []) {
                    $entries = [[
                        'ballot_entry_id' => $eid,
                        'entry_kind' => (string) ($row['entry_kind'] ?? 'product'),
                        'entry_name' => (string) ($row['choice_name'] ?? ''),
                    ]];
                }
            } elseif ($usesNamedEntries) {
                // Bare business casts stay on this row. Named products are separate rows.
                $entries = [];
            } else {
                $entries = $allEntries;
            }
            $grade = twg_grade_award_pair(
                $entries,
                $titleScores,
                $entryScoreMap[$question_id][$cid] ?? [],
                $awardPanel
            );
            $hasTwg = $grade['average'] !== null;
            $twg = $hasTwg ? (float) $grade['average'] : null;
            if ($hasTwg) {
                $twgEntered++;
            }
            $twgForFormula = twg_average_on_ten($twg) ?? 0.0;
            $final = ($twgForFormula * 0.40) + ($community * 0.60);
            $memberScores = $grade['scores'];
            $row['vote_share'] = results_formula_round($share, 2);
            $row['community_score'] = results_formula_round($community, 2);
            $row['twg_average'] = $twg === null ? null : results_formula_round($twg, 2);
            $row['twg_entered'] = $hasTwg;
            $row['twg_scores'] = $memberScores;
            $row['twg_scored'] = (int) $grade['scored'];
            $row['twg_member_count'] = (int) $grade['member_count'];
            if ($eid > 0) {
                $row['entry_names'] = [];
                $row['entry_kind'] = (string) ($grade['entries'][0]['entry_kind'] ?? ($row['entry_kind'] ?? ''));
                $row['entries'] = $grade['entries'];
            } else {
                $row['entry_names'] = array_values(array_filter(array_map(
                    static fn($e) => trim((string) ($e['entry_name'] ?? '')),
                    $grade['entries']
                )));
                $row['entry_kind'] = (string) ($grade['entries'][0]['entry_kind'] ?? '');
                $row['entries'] = $grade['entries'];
            }
            $row['final_score'] = results_formula_round($final, 2);
        }
        unset($row);

        usort($rows, static function ($a, $b) {
            $finalCmp = ((float) $b['final_score']) <=> ((float) $a['final_score']);
            if ($finalCmp !== 0) {
                return $finalCmp;
            }
            $voteCmp = ((int) $b['vote_count']) <=> ((int) $a['vote_count']);
            if ($voteCmp !== 0) {
                return $voteCmp;
            }
            return strcasecmp((string) $a['choice_name'], (string) $b['choice_name']);
        });

        $rank = 0;
        $previousFinal = null;
        foreach ($rows as &$row) {
            $final = (float) $row['final_score'];
            if ($previousFinal === null || abs($final - $previousFinal) > 0.0001) {
                $rank++;
            }
            $row['rank'] = $rank;
            $row['display_rank'] = (string) $rank;
            $row['top10'] = $rank <= 5;
            $previousFinal = $final;
        }
        unset($row);

        $twgOrder = $rows;
        usort($twgOrder, static function ($a, $b) {
            $aHas = !empty($a['twg_entered']);
            $bHas = !empty($b['twg_entered']);
            if ($aHas !== $bHas) {
                return $aHas ? -1 : 1;
            }
            if ($aHas) {
                $avgCmp = ((float) $b['twg_average']) <=> ((float) $a['twg_average']);
                if ($avgCmp !== 0) {
                    return $avgCmp;
                }
            }
            return strcasecmp((string) $a['choice_name'], (string) $b['choice_name']);
        });
        $twgRank = 0;
        $previousTwg = null;
        $twgRankByKey = [];
        foreach ($twgOrder as $twgRow) {
            $key = results_formula_row_identity_key($twgRow);
            if (empty($twgRow['twg_entered'])) {
                $twgRankByKey[$key] = null;
                continue;
            }
            $avg = (float) $twgRow['twg_average'];
            if ($previousTwg === null || abs($avg - $previousTwg) > 0.0001) {
                $twgRank++;
            }
            $twgRankByKey[$key] = $twgRank;
            $previousTwg = $avg;
        }
        foreach ($rows as &$row) {
            $key = results_formula_row_identity_key($row);
            $row['twg_rank'] = $twgRankByKey[$key] ?? null;
        }
        unset($row);

        $leader = $rows[0] ?? null;
        $twgLeader = null;
        foreach ($twgOrder as $twgRow) {
            if (!empty($twgRow['twg_entered'])) {
                $twgLeader = $twgRow;
                break;
            }
        }

        return [
            'results' => $rows,
            'total_votes' => $totalVotes,
            'nominee_count' => count($rows),
            'twg_entered' => $twgEntered,
            'leader' => $leader,
            'twg_leader' => $twgLeader,
            'twg_members' => $twgMembers,
        ];
    }
}

if (!function_exists('twg_member_definitions')) {
    /**
     * Active TWG scoring columns for the event (criteria). Falls back to seven default members.
     *
     * @return list<array{key:string,label:string,short:string,weight?:float,criterion_id?:int}>
     */
    function twg_member_definitions(?mysqli $conn = null, ?int $eventId = null): array
    {
        if ($conn === null && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $conn = $GLOBALS['conn'];
        }
        if ($conn instanceof mysqli) {
            return twg_criteria_for_event($conn, $eventId);
        }
        return array_map(static function (array $row): array {
            return twg_criteria_normalize_row($row);
        }, twg_criteria_defaults());
    }
}

if (!function_exists('twg_member_keys')) {
    /** @return list<string> */
    function twg_member_keys(?mysqli $conn = null, ?int $eventId = null): array
    {
        return array_values(array_filter(array_map(
            static fn(array $m): string => (string) ($m['key'] ?? ''),
            twg_member_definitions($conn, $eventId)
        )));
    }
}

if (!function_exists('twg_member_scores_ensure_schema')) {
    function twg_member_scores_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        results_formula_ensure_schema($conn);
        if (function_exists('twg_criteria_ensure_schema')) {
            twg_criteria_ensure_schema($conn);
        }
        $conn->query(
            "CREATE TABLE IF NOT EXISTS tbl_twg_member_scores (
                question_id INT NOT NULL,
                choice_id INT NOT NULL,
                member_key VARCHAR(32) NOT NULL,
                score DECIMAL(5,2) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (question_id, choice_id, member_key),
                KEY idx_twg_member_award (question_id, choice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->query(
            "CREATE TABLE IF NOT EXISTS tbl_twg_entry_member_scores (
                question_id INT NOT NULL,
                choice_id INT NOT NULL,
                ballot_entry_id INT UNSIGNED NOT NULL,
                member_key VARCHAR(32) NOT NULL,
                score DECIMAL(5,2) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (question_id, choice_id, ballot_entry_id, member_key),
                KEY idx_twg_entry_award (question_id, choice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        twg_member_scores_scale_legacy_to_100($conn);
        $done = true;
    }
}

if (!function_exists('twg_entry_belongs_to_choice')) {
    function twg_entry_belongs_to_choice(mysqli $conn, int $question_id, int $choice_id, int $ballot_entry_id): bool
    {
        if ($question_id <= 0 || $choice_id <= 0 || $ballot_entry_id <= 0) {
            return false;
        }
        $st = $conn->prepare(
            'SELECT 1 FROM tbl_award_ballot_entries
             WHERE ballot_entry_id = ? AND question_id = ? AND choice_id = ? AND is_active = 1 LIMIT 1'
        );
        if (!$st) {
            return false;
        }
        $st->bind_param('iii', $ballot_entry_id, $question_id, $choice_id);
        $st->execute();
        $ok = (bool) $st->get_result()->fetch_row();
        $st->close();
        return $ok;
    }
}

if (!function_exists('twg_get_entry_member_score')) {
    function twg_get_entry_member_score(
        mysqli $conn,
        int $question_id,
        int $choice_id,
        int $ballot_entry_id,
        string $member_key
    ): ?float {
        $member_key = strtolower(trim($member_key));
        if ($question_id <= 0 || $choice_id <= 0 || $ballot_entry_id <= 0 || $member_key === '') {
            return null;
        }
        twg_member_scores_ensure_schema($conn);
        $st = $conn->prepare(
            'SELECT score FROM tbl_twg_entry_member_scores
             WHERE question_id = ? AND choice_id = ? AND ballot_entry_id = ? AND member_key = ? LIMIT 1'
        );
        if (!$st) {
            return null;
        }
        $st->bind_param('iiis', $question_id, $choice_id, $ballot_entry_id, $member_key);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row || !isset($row['score'])) {
            return null;
        }
        return (float) $row['score'];
    }
}

if (!function_exists('twg_save_entry_member_score')) {
    /**
     * @return array{ok:bool,message:string,average:?float,scored:int,locked?:bool}
     */
    function twg_save_entry_member_score(
        mysqli $conn,
        int $question_id,
        int $choice_id,
        int $ballot_entry_id,
        string $member_key,
        ?float $score
    ): array {
        $member_key = strtolower(trim($member_key));
        if (!in_array($member_key, twg_member_keys(), true)) {
            return ['ok' => false, 'message' => 'Unknown scoring criterion.', 'average' => null, 'scored' => 0];
        }
        if ($question_id <= 0 || $choice_id <= 0 || $ballot_entry_id <= 0) {
            return ['ok' => false, 'message' => 'Missing award, business, or product.', 'average' => null, 'scored' => 0];
        }
        if (!twg_entry_belongs_to_choice($conn, $question_id, $choice_id, $ballot_entry_id)) {
            return ['ok' => false, 'message' => 'That product is not linked to this award.', 'average' => null, 'scored' => 0];
        }
        twg_member_scores_ensure_schema($conn);
        if (twg_choice_scores_locked($conn, $choice_id)) {
            return [
                'ok' => false,
                'message' => 'Scores are locked after this business is confirmed for public voting.',
                'average' => null,
                'scored' => 0,
                'locked' => true,
            ];
        }
        if ($score === null || $score < twg_score_min() || $score > twg_score_max()) {
            return ['ok' => false, 'message' => twg_score_range_message(), 'average' => null, 'scored' => 0];
        }
        $ins = $conn->prepare(
            'INSERT INTO tbl_twg_entry_member_scores (question_id, choice_id, ballot_entry_id, member_key, score)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()'
        );
        if (!$ins) {
            return ['ok' => false, 'message' => 'Could not save score.', 'average' => null, 'scored' => 0];
        }
        $ins->bind_param('iiisd', $question_id, $choice_id, $ballot_entry_id, $member_key, $score);
        $ok = $ins->execute();
        $ins->close();
        if (!$ok) {
            return ['ok' => false, 'message' => 'Could not save score.', 'average' => null, 'scored' => 0];
        }
        $avg = twg_recompute_average($conn, $question_id, $choice_id);
        $cnt = 0;
        $c = $conn->prepare(
            'SELECT COUNT(*) AS n FROM tbl_twg_entry_member_scores
             WHERE question_id = ? AND choice_id = ? AND ballot_entry_id = ?'
        );
        if ($c) {
            $c->bind_param('iii', $question_id, $choice_id, $ballot_entry_id);
            $c->execute();
            $cnt = (int) (($c->get_result()->fetch_assoc()['n'] ?? 0));
            $c->close();
        }
        return ['ok' => true, 'message' => 'Saved.', 'average' => $avg, 'scored' => $cnt];
    }
}

if (!function_exists('twg_entry_kind_label')) {
    function twg_entry_kind_label(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if ($kind === 'artist') {
            return 'Artist';
        }
        if ($kind === 'stylist') {
            return 'Stylist';
        }
        return 'Product';
    }
}

if (!function_exists('twg_delete_stored_average')) {
    function twg_delete_stored_average(mysqli $conn, int $question_id, int $choice_id): void
    {
        if ($question_id <= 0 || $choice_id <= 0) {
            return;
        }
        $del = $conn->prepare('DELETE FROM tbl_twg_scores WHERE question_id = ? AND choice_id = ?');
        if (!$del) {
            return;
        }
        $del->bind_param('ii', $question_id, $choice_id);
        $del->execute();
        $del->close();
    }
}

if (!function_exists('twg_fetch_entry_member_score_map')) {
    /**
     * @param list<int> $questionIds
     * @return array<int, array<int, array<int, array<string, float>>>>
     */
    function twg_fetch_entry_member_score_map(mysqli $conn, array $questionIds, ?int $choice_id = null): array
    {
        twg_member_scores_ensure_schema($conn);
        $questionIds = array_values(array_unique(array_filter(array_map('intval', $questionIds))));
        if ($questionIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($questionIds), '?'));
        $sql = "SELECT question_id, choice_id, ballot_entry_id, member_key, score
                FROM tbl_twg_entry_member_scores
                WHERE question_id IN ($ph)";
        $types = str_repeat('i', count($questionIds));
        $params = $questionIds;
        if ($choice_id !== null && $choice_id > 0) {
            $sql .= ' AND choice_id = ?';
            $types .= 'i';
            $params[] = $choice_id;
        }
        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $qid = (int) ($row['question_id'] ?? 0);
            $cid = (int) ($row['choice_id'] ?? 0);
            $eid = (int) ($row['ballot_entry_id'] ?? 0);
            $key = strtolower(trim((string) ($row['member_key'] ?? '')));
            if ($qid <= 0 || $cid <= 0 || $eid <= 0 || $key === '') {
                continue;
            }
            $out[$qid][$cid][$eid][$key] = results_formula_round((float) $row['score'], 2);
        }
        $st->close();
        return $out;
    }
}

if (!function_exists('twg_grade_award_pair')) {
    /**
     * @param list<array{ballot_entry_id:int,entry_kind?:string,entry_name?:string}> $entries
     * @param array<string, float|null> $titleScores
     * @param array<int, array<string, float>> $entryScoreMap
     * @param list<array{key:string}> $members
     * @return array{
     *   has_entries:bool,
     *   scored:int,
     *   member_count:int,
     *   scores:array<string, float|null>,
     *   average:?float,
     *   fully:bool,
     *   entries:list<array<string,mixed>>
     * }
     */
    function twg_grade_award_pair(array $entries, array $titleScores, array $entryScoreMap, array $members): array
    {
        $memberKeys = [];
        foreach ($members as $member) {
            $key = (string) ($member['key'] ?? '');
            if ($key !== '') {
                $memberKeys[] = $key;
            }
        }
        $nMembers = count($memberKeys) ?: 5;

        if ($entries === []) {
            $scores = [];
            $filled = 0;
            foreach ($memberKeys as $key) {
                $val = $titleScores[$key] ?? null;
                $scores[$key] = $val;
                if ($val !== null) {
                    $filled++;
                }
            }
            return [
                'has_entries' => false,
                'scored' => $filled,
                'member_count' => $nMembers,
                'scores' => $scores,
                'average' => twg_criteria_weighted_mean($scores, $members),
                'fully' => $filled >= $nMembers && $nMembers > 0,
                'entries' => [],
            ];
        }

        $outEntries = [];
        $productAvgs = [];
        $totalScored = 0;
        $allFull = true;
        foreach ($entries as $entry) {
            $eid = (int) ($entry['ballot_entry_id'] ?? 0);
            $eScores = [];
            $eFilled = 0;
            foreach ($memberKeys as $key) {
                $val = $entryScoreMap[$eid][$key] ?? null;
                $eScores[$key] = $val;
                if ($val !== null) {
                    $eFilled++;
                }
            }
            $totalScored += $eFilled;
            $eFull = $eid > 0 && $eFilled >= $nMembers;
            if (!$eFull) {
                $allFull = false;
            }
            $eAvg = twg_criteria_weighted_mean($eScores, $members);
            if ($eAvg !== null) {
                $productAvgs[] = $eAvg;
            }
            $outEntries[] = [
                'ballot_entry_id' => $eid,
                'entry_kind' => (string) ($entry['entry_kind'] ?? 'product'),
                'entry_name' => (string) ($entry['entry_name'] ?? ''),
                'scores' => $eScores,
                'scored' => $eFilled,
                'member_count' => $nMembers,
                'average' => $eAvg,
            ];
        }

        $rollupScores = [];
        foreach ($memberKeys as $key) {
            $vals = [];
            foreach ($outEntries as $entryRow) {
                $val = $entryRow['scores'][$key] ?? null;
                if ($val === null) {
                    $vals = [];
                    break;
                }
                $vals[] = (float) $val;
            }
            $rollupScores[$key] = $vals === [] ? null : results_formula_round(array_sum($vals) / count($vals), 2);
        }

        return [
            'has_entries' => true,
            'scored' => $totalScored,
            'member_count' => $nMembers * count($entries),
            'scores' => $rollupScores,
            'average' => $productAvgs !== []
                ? results_formula_round(array_sum($productAvgs) / count($productAvgs), 2)
                : null,
            'fully' => $allFull,
            'entries' => $outEntries,
        ];
    }
}

if (!function_exists('twg_recompute_average')) {
    function twg_recompute_average(mysqli $conn, int $question_id, int $choice_id): ?float
    {
        twg_member_scores_ensure_schema($conn);
        $helpers = __DIR__ . '/award_entry_helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }

        $entryRows = [];
        if (function_exists('award_entry_score_rows_for_choice')) {
            $byQ = award_entry_score_rows_for_choice($conn, $choice_id);
            $entryRows = is_array($byQ[$question_id] ?? null) ? $byQ[$question_id] : [];
        }
        if ($entryRows === []
            && function_exists('twg_rubric_choice_average_100')
            && function_exists('twg_rubric_event_id_for_question')
        ) {
            $rubricEventId = twg_rubric_event_id_for_question($conn, $question_id);
            $avg100 = $rubricEventId > 0
                ? twg_rubric_choice_average_100($conn, $rubricEventId, $question_id, $choice_id, 0)
                : null;
            if ($avg100 !== null) {
                results_formula_save_twg($conn, $question_id, $choice_id, $avg100);
                return $avg100;
            }
        }

        $members = twg_member_definitions($conn);
        $titleByChoice = [];
        $stAll = $conn->prepare(
            'SELECT choice_id, member_key, score
             FROM tbl_twg_member_scores
             WHERE question_id = ?'
        );
        if ($stAll) {
            $stAll->bind_param('i', $question_id);
            $stAll->execute();
            $resAll = $stAll->get_result();
            while ($row = $resAll->fetch_assoc()) {
                $cid = (int) ($row['choice_id'] ?? 0);
                $key = strtolower(trim((string) ($row['member_key'] ?? '')));
                if ($cid > 0 && $key !== '') {
                    $titleByChoice[$cid][$key] = results_formula_round((float) $row['score'], 2);
                }
            }
            $stAll->close();
        }
        $entryByChoice = twg_fetch_entry_member_score_map($conn, [$question_id])[$question_id] ?? [];
        $panel = twg_panel_members_for_award($members, $titleByChoice, $entryByChoice);

        if ($entryRows !== []) {
            $grade = twg_grade_award_pair(
                $entryRows,
                [],
                $entryByChoice[$choice_id] ?? [],
                $panel
            );
            if ($grade['average'] !== null) {
                results_formula_save_twg($conn, $question_id, $choice_id, (float) $grade['average']);
                return (float) $grade['average'];
            }
            twg_delete_stored_average($conn, $question_id, $choice_id);
            return null;
        }

        $grade = twg_grade_award_pair([], $titleByChoice[$choice_id] ?? [], [], $panel);
        if ($grade['average'] !== null) {
            results_formula_save_twg($conn, $question_id, $choice_id, (float) $grade['average']);
            return (float) $grade['average'];
        }
        twg_delete_stored_average($conn, $question_id, $choice_id);
        return null;
    }
}

if (!function_exists('twg_get_member_score')) {
    function twg_get_member_score(mysqli $conn, int $question_id, int $choice_id, string $member_key): ?float
    {
        $member_key = strtolower(trim($member_key));
        if ($question_id <= 0 || $choice_id <= 0 || $member_key === '') {
            return null;
        }
        twg_member_scores_ensure_schema($conn);
        $st = $conn->prepare(
            'SELECT score FROM tbl_twg_member_scores
             WHERE question_id = ? AND choice_id = ? AND member_key = ? LIMIT 1'
        );
        if (!$st) {
            return null;
        }
        $st->bind_param('iis', $question_id, $choice_id, $member_key);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row || !isset($row['score'])) {
            return null;
        }
        return (float) $row['score'];
    }
}

if (!function_exists('twg_save_member_score')) {
    /**
     * @return array{ok:bool,message:string,average:?float,scored:int,locked?:bool}
     */
    function twg_save_member_score(mysqli $conn, int $question_id, int $choice_id, string $member_key, ?float $score): array
    {
        $member_key = strtolower(trim($member_key));
        if (!in_array($member_key, twg_member_keys(), true)) {
            return ['ok' => false, 'message' => 'Unknown scoring criterion.', 'average' => null, 'scored' => 0];
        }
        if ($question_id <= 0 || $choice_id <= 0) {
            return ['ok' => false, 'message' => 'Missing award or business.', 'average' => null, 'scored' => 0];
        }
        twg_member_scores_ensure_schema($conn);
        if (twg_choice_scores_locked($conn, $choice_id)) {
            return [
                'ok' => false,
                'message' => 'Scores are locked after this business is confirmed for public voting.',
                'average' => null,
                'scored' => 0,
                'locked' => true,
            ];
        }
        $helpers = __DIR__ . '/award_entry_helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
        if (function_exists('award_entry_score_rows_for_choice')) {
            $named = award_entry_score_rows_for_choice($conn, $choice_id);
            if (!empty($named[$question_id])) {
                return [
                    'ok' => false,
                    'message' => 'Score each remaining product on this award, not the title as a whole.',
                    'average' => null,
                    'scored' => 0,
                ];
            }
        }

        if ($score === null) {
            $del = $conn->prepare(
                'DELETE FROM tbl_twg_member_scores WHERE question_id = ? AND choice_id = ? AND member_key = ?'
            );
            if ($del) {
                $del->bind_param('iis', $question_id, $choice_id, $member_key);
                $del->execute();
                $del->close();
            }
        } else {
            if ($score < twg_score_min() || $score > twg_score_max()) {
                return ['ok' => false, 'message' => twg_score_range_message(), 'average' => null, 'scored' => 0];
            }
            $ins = $conn->prepare(
                'INSERT INTO tbl_twg_member_scores (question_id, choice_id, member_key, score)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()'
            );
            if (!$ins) {
                return ['ok' => false, 'message' => 'Could not save score.', 'average' => null, 'scored' => 0];
            }
            $ins->bind_param('iisd', $question_id, $choice_id, $member_key, $score);
            $ok = $ins->execute();
            $ins->close();
            if (!$ok) {
                return ['ok' => false, 'message' => 'Could not save score.', 'average' => null, 'scored' => 0];
            }
        }

        $avg = twg_recompute_average($conn, $question_id, $choice_id);
        $cnt = 0;
        $c = $conn->prepare('SELECT COUNT(*) AS n FROM tbl_twg_member_scores WHERE question_id = ? AND choice_id = ?');
        if ($c) {
            $c->bind_param('ii', $question_id, $choice_id);
            $c->execute();
            $cnt = (int) (($c->get_result()->fetch_assoc()['n'] ?? 0));
            $c->close();
        }
        return [
            'ok' => true,
            'message' => 'Saved.',
            'average' => $avg,
            'scored' => $cnt,
        ];
    }
}

if (!function_exists('twg_parse_score_value')) {
    /**
     * @return array{ok:bool,score:?float,message:string}
     */
    function twg_parse_score_value(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['ok' => true, 'score' => null, 'message' => ''];
        }
        if (is_string($raw) && trim($raw) === '') {
            return ['ok' => true, 'score' => null, 'message' => ''];
        }
        if (!is_numeric($raw)) {
            return ['ok' => false, 'score' => null, 'message' => 'Score must be a number from 0 to 100.'];
        }
        $score = round((float) $raw, 2);
        if ($score < twg_score_min() || $score > twg_score_max()) {
            return ['ok' => false, 'score' => null, 'message' => twg_score_range_message()];
        }
        return ['ok' => true, 'score' => $score, 'message' => ''];
    }
}

if (!function_exists('twg_save_sheet')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @return array{ok:bool,message:string,saved:int,rows:list<array<string,mixed>>}
     */
    function twg_save_sheet(mysqli $conn, int $choice_id, array $rows): array
    {
        if ($choice_id <= 0) {
            return ['ok' => false, 'message' => 'Choose a business first.', 'saved' => 0, 'rows' => []];
        }
        if (twg_choice_scores_locked($conn, $choice_id)) {
            return ['ok' => false, 'message' => 'Scores are locked after this business is confirmed for public voting.', 'saved' => 0, 'rows' => []];
        }
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Enter at least one score before saving.', 'saved' => 0, 'rows' => []];
        }

        $saved = 0;
        $outRows = [];
        $seen = [];
        $pending = [];
        $skippedLocked = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $questionId = (int) ($row['question_id'] ?? 0);
            $memberKey = strtolower(trim((string) ($row['member_key'] ?? '')));
            $ballotEntryId = (int) ($row['ballot_entry_id'] ?? 0);
            $cellChoice = (int) ($row['choice_id'] ?? $choice_id);
            if ($cellChoice !== $choice_id) {
                return ['ok' => false, 'message' => 'Scores do not match the selected business.', 'saved' => 0, 'rows' => []];
            }
            if ($questionId <= 0 || $memberKey === '') {
                continue;
            }
            $parsed = twg_parse_score_value($row['score'] ?? null);
            if (!$parsed['ok']) {
                return [
                    'ok' => false,
                    'message' => $parsed['message'],
                    'saved' => 0,
                    'rows' => [],
                    'invalid' => [
                        'question_id' => $questionId,
                        'ballot_entry_id' => $ballotEntryId,
                        'member_key' => $memberKey,
                    ],
                ];
            }
            if ($parsed['score'] === null) {
                continue;
            }
            $dedupe = $questionId . ':' . $ballotEntryId . ':' . $memberKey;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $pending[] = [
                'question_id' => $questionId,
                'ballot_entry_id' => $ballotEntryId,
                'member_key' => $memberKey,
                'score' => $parsed['score'],
            ];
        }

        if ($pending !== []) {
            $eventId = 0;
            $eventSt = $conn->prepare('SELECT event_id FROM tbl_choices WHERE choice_id = ? LIMIT 1');
            if ($eventSt) {
                $eventSt->bind_param('i', $choice_id);
                $eventSt->execute();
                $eventRow = $eventSt->get_result()->fetch_assoc();
                $eventSt->close();
                $eventId = (int) ($eventRow['event_id'] ?? 0);
            }
            if ($eventId > 0 && function_exists('twg_fetch_sheet_for_choice')) {
                $sheet = twg_fetch_sheet_for_choice($conn, $eventId, $choice_id);
                $pendingMap = [];
                foreach ($pending as $item) {
                    $qid = (int) $item['question_id'];
                    $eid = (int) ($item['ballot_entry_id'] ?? 0);
                    $pendingMap[$qid][$eid][(string) $item['member_key']] = true;
                }
                $memberDefs = twg_member_definitions($conn, $eventId);
                $missingParts = [];
                foreach ($sheet['awards'] ?? [] as $award) {
                    if (!is_array($award)) {
                        continue;
                    }
                    $qid = (int) ($award['question_id'] ?? 0);
                    $awardName = trim((string) ($award['category_name'] ?? '') . ' - ' . (string) ($award['question_name'] ?? 'Award'), " -\t\n\r\0\x0B");
                    $entryList = is_array($award['entries'] ?? null) ? $award['entries'] : [];
                    if ($entryList !== []) {
                        foreach ($entryList as $entry) {
                            if (!is_array($entry)) {
                                continue;
                            }
                            $eid = (int) ($entry['ballot_entry_id'] ?? 0);
                            $entryScores = is_array($entry['scores'] ?? null) ? $entry['scores'] : [];
                            $missingLabels = [];
                            foreach ($memberDefs as $member) {
                                $mk = (string) ($member['key'] ?? '');
                                if ($mk === '') {
                                    continue;
                                }
                                $have = $entryScores[$mk] ?? null;
                                if ($have === null && empty($pendingMap[$qid][$eid][$mk])) {
                                    $missingLabels[] = (string) ($member['short'] ?? $mk);
                                }
                            }
                            if ($missingLabels === []) {
                                continue;
                            }
                            $entryName = trim((string) ($entry['entry_name'] ?? ''));
                            $label = $entryName !== '' ? ($awardName . ' (' . $entryName . ')') : $awardName;
                            $total = count($memberDefs) ?: 5;
                            $filled = $total - count($missingLabels);
                            $missingParts[] = $label . ' is missing ' . implode(', ', $missingLabels) . ' (' . $filled . '/' . $total . ')';
                        }
                        continue;
                    }
                    $scores = is_array($award['scores'] ?? null) ? $award['scores'] : [];
                    $missingLabels = [];
                    foreach ($memberDefs as $member) {
                        $mk = (string) ($member['key'] ?? '');
                        if ($mk === '') {
                            continue;
                        }
                        $have = $scores[$mk] ?? null;
                        if ($have === null && empty($pendingMap[$qid][0][$mk])) {
                            $missingLabels[] = (string) ($member['short'] ?? $mk);
                        }
                    }
                    if ($missingLabels === []) {
                        continue;
                    }
                    $total = count($memberDefs) ?: 5;
                    $filled = $total - count($missingLabels);
                    $missingParts[] = $awardName . ' is missing ' . implode(', ', $missingLabels) . ' (' . $filled . '/' . $total . ')';
                }
                if ($missingParts !== []) {
                    return [
                        'ok' => false,
                        'message' => 'Cannot save while scores are missing. ' . $missingParts[0]
                            . (count($missingParts) > 1
                                ? ' ' . (count($missingParts) - 1) . ' more award' . (count($missingParts) === 2 ? '' : 's') . ' also lack scores.'
                                : ''),
                        'saved' => 0,
                        'rows' => [],
                    ];
                }
            }
        }

        $affectedQuestions = [];
        foreach ($pending as $item) {
            $questionId = (int) $item['question_id'];
            $memberKey = (string) $item['member_key'];
            $ballotEntryId = (int) ($item['ballot_entry_id'] ?? 0);
            if (!twg_choice_linked_to_question($conn, $questionId, $choice_id)) {
                return ['ok' => false, 'message' => 'That business is not linked to this award.', 'saved' => 0, 'rows' => []];
            }
            $result = $ballotEntryId > 0
                ? twg_save_entry_member_score($conn, $questionId, $choice_id, $ballotEntryId, $memberKey, $item['score'])
                : twg_save_member_score($conn, $questionId, $choice_id, $memberKey, $item['score']);
            if (!$result['ok']) {
                return ['ok' => false, 'message' => $result['message'], 'saved' => 0, 'rows' => []];
            }
            $saved++;
            $affectedQuestions[$questionId] = true;
            $rowKey = $questionId . ':' . $ballotEntryId;
            $outRows[$rowKey] = [
                'question_id' => $questionId,
                'choice_id' => $choice_id,
                'ballot_entry_id' => $ballotEntryId,
                'average' => $result['average'],
                'scored' => $result['scored'],
                'member_count' => count(twg_member_keys()),
            ];
        }

        if ($saved < 1) {
            return [
                'ok' => false,
                'message' => 'Enter at least one score from 0 to 100 before saving.',
                'saved' => 0,
                'rows' => [],
            ];
        }

        $eventId = 0;
        $eventSt = $conn->prepare('SELECT event_id FROM tbl_choices WHERE choice_id = ? LIMIT 1');
        if ($eventSt) {
            $eventSt->bind_param('i', $choice_id);
            $eventSt->execute();
            $eventRow = $eventSt->get_result()->fetch_assoc();
            $eventSt->close();
            $eventId = (int) ($eventRow['event_id'] ?? 0);
        }
        if ($eventId > 0 && function_exists('twg_fetch_sheet_for_choice')) {
            $sheet = twg_fetch_sheet_for_choice($conn, $eventId, $choice_id);
            foreach ($sheet['awards'] ?? [] as $award) {
                $qid = (int) ($award['question_id'] ?? 0);
                if ($qid <= 0 || empty($affectedQuestions[$qid])) {
                    continue;
                }
                $outRows[$qid . ':0'] = [
                    'question_id' => $qid,
                    'choice_id' => $choice_id,
                    'ballot_entry_id' => 0,
                    'average' => $award['average'] ?? null,
                    'scored' => (int) ($award['scored'] ?? 0),
                    'member_count' => (int) ($award['member_count'] ?? count(twg_member_keys())),
                ];
                foreach (is_array($award['entries'] ?? null) ? $award['entries'] : [] as $entry) {
                    $eid = (int) ($entry['ballot_entry_id'] ?? 0);
                    if ($eid <= 0) {
                        continue;
                    }
                    $outRows[$qid . ':' . $eid] = [
                        'question_id' => $qid,
                        'choice_id' => $choice_id,
                        'ballot_entry_id' => $eid,
                        'average' => $entry['average'] ?? null,
                        'scored' => (int) ($entry['scored'] ?? 0),
                        'member_count' => (int) ($entry['member_count'] ?? count(twg_member_keys())),
                    ];
                }
            }
        }

        return [
            'ok' => true,
            'message' => $saved === 1 ? 'Saved 1 score.' : ('Saved ' . $saved . ' scores.'),
            'saved' => $saved,
            'rows' => array_values($outRows),
        ];
    }
}

if (!function_exists('twg_question_in_event')) {
    function twg_question_in_event(mysqli $conn, int $event_id, int $question_id): bool
    {
        if ($event_id <= 0 || $question_id <= 0) {
            return false;
        }
        $st = $conn->prepare(
            'SELECT q.question_id
             FROM tbl_questions q
             INNER JOIN tbl_categories c ON c.category_id = q.category_id
             WHERE q.question_id = ? AND c.event_id = ?
               AND ' . admin_active_category_sql($conn, 'c') . '
               AND ' . admin_active_question_sql($conn, 'q') . '
             LIMIT 1'
        );
        if (!$st) {
            return false;
        }
        $st->bind_param('ii', $question_id, $event_id);
        $st->execute();
        $ok = (bool) $st->get_result()->fetch_assoc();
        $st->close();
        return $ok;
    }
}

if (!function_exists('twg_choice_linked_to_question')) {
    function twg_choice_linked_to_question(mysqli $conn, int $question_id, int $choice_id): bool
    {
        if ($question_id <= 0 || $choice_id <= 0) {
            return false;
        }
        $st = $conn->prepare(
            'SELECT 1 FROM tbl_question_choices WHERE question_id = ? AND choice_id = ? LIMIT 1'
        );
        if (!$st) {
            return false;
        }
        $st->bind_param('ii', $question_id, $choice_id);
        $st->execute();
        $ok = (bool) $st->get_result()->fetch_assoc();
        $st->close();
        if (!$ok) {
            return false;
        }
        $remaining = twg_remaining_question_ids_for_choice($conn, $choice_id);
        if ($remaining === null) {
            return true;
        }
        return in_array($question_id, $remaining, true);
    }
}

if (!function_exists('twg_fetch_sheet')) {
    /**
     * Nominees currently linked to the award (including under evaluation, 0 votes).
     *
     * @return array{members:list<array<string,string>>,nominees:list<array<string,mixed>>}
     */
    function twg_fetch_sheet(mysqli $conn, int $event_id, int $question_id): array
    {
        twg_member_scores_ensure_schema($conn);
        $members = twg_member_definitions($conn, $event_id);
        $hasOnBallot = false;
        if (is_file(__DIR__ . '/ballot_status.php')) {
            require_once __DIR__ . '/ballot_status.php';
            $hasOnBallot = function_exists('ballot_status_ensure_column') && ballot_status_ensure_column($conn);
        }
        $onBallotSelect = $hasOnBallot ? ', c.on_ballot' : ', 1 AS on_ballot';
        $remainingSql = twg_award_link_matches_remaining_sql($conn, 'c.choice_id', 'qc.question_id');

        $linked = [];
        $sql = "
            SELECT c.choice_id, c.choice_name{$onBallotSelect}, t.twg_average
            FROM tbl_question_choices qc
            INNER JOIN tbl_choices c ON c.choice_id = qc.choice_id
            LEFT JOIN tbl_twg_scores t
              ON t.choice_id = c.choice_id AND t.question_id = qc.question_id
            WHERE qc.question_id = ? AND c.event_id = ?
              AND {$remainingSql}
            ORDER BY c.choice_name ASC
        ";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('ii', $question_id, $event_id);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $linked[] = $row;
            }
            $st->close();
        }

        $ids = [];
        foreach ($linked as $row) {
            $ids[] = (int) ($row['choice_id'] ?? 0);
        }
        $ids = array_values(array_filter($ids));
        $scoreMap = [];
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $types = 'i' . str_repeat('i', count($ids));
            $sql = "SELECT choice_id, member_key, score
                    FROM tbl_twg_member_scores
                    WHERE question_id = ? AND choice_id IN ($ph)";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param($types, $question_id, ...$ids);
                $st->execute();
                $res = $st->get_result();
                while ($r = $res->fetch_assoc()) {
                    $cid = (int) $r['choice_id'];
                    $scoreMap[$cid][(string) $r['member_key']] = results_formula_round((float) $r['score'], 2);
                }
                $st->close();
            }
        }

        $nominees = [];
        foreach ($linked as $row) {
            $cid = (int) ($row['choice_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $scores = [];
            foreach ($members as $m) {
                $scores[$m['key']] = $scoreMap[$cid][$m['key']] ?? null;
            }
            $filled = array_values(array_filter($scores, static fn($v) => $v !== null));
            $avg = $row['twg_average'] ?? null;
            $nominees[] = [
                'choice_id' => $cid,
                'choice_name' => (string) ($row['choice_name'] ?? ''),
                'on_ballot' => (int) ($row['on_ballot'] ?? 0) === 1,
                'scores' => $scores,
                'scored' => count($filled),
                'member_count' => count($members),
                'average' => $avg === null ? null : results_formula_round((float) $avg, 2),
            ];
        }

        return [
            'members' => $members,
            'nominees' => $nominees,
        ];
    }
}

if (!function_exists('twg_choice_in_event')) {
    function twg_choice_in_event(mysqli $conn, int $event_id, int $choice_id): bool
    {
        if ($event_id <= 0 || $choice_id <= 0) {
            return false;
        }
        $st = $conn->prepare('SELECT 1 FROM tbl_choices WHERE choice_id = ? AND event_id = ? LIMIT 1');
        if (!$st) {
            return false;
        }
        $st->bind_param('ii', $choice_id, $event_id);
        $st->execute();
        $ok = (bool) $st->get_result()->fetch_assoc();
        $st->close();
        return $ok;
    }
}

if (!function_exists('twg_award_link_matches_remaining_sql')) {
    /**
     * Keep leftover File Maintenance links only when the business has no registration.
     * Once a registration is linked, TWG / ballot follow remaining nomination titles.
     */
    function twg_award_link_matches_remaining_sql(mysqli $conn, string $choiceIdExpr, string $questionIdExpr): string
    {
        $reasons = __DIR__ . '/award_removal_reasons.php';
        if (is_file($reasons)) {
            require_once $reasons;
        }
        if (!function_exists('award_has_merged_choice_id') || !award_has_merged_choice_id($conn)) {
            return '1=1';
        }
        $choiceIdExpr = preg_replace('/[^a-zA-Z0-9_.]/', '', $choiceIdExpr) ?: 'c.choice_id';
        $questionIdExpr = preg_replace('/[^a-zA-Z0-9_.]/', '', $questionIdExpr) ?: 'q.question_id';
        return "(
            NOT EXISTS (
                SELECT 1 FROM tbl_nominations twg_n_rem
                WHERE twg_n_rem.merged_choice_id = {$choiceIdExpr}
            )
            OR EXISTS (
                SELECT 1 FROM tbl_nominations twg_n_rem
                INNER JOIN tbl_nomination_questions twg_nq_rem
                    ON twg_nq_rem.nomination_id = twg_n_rem.nomination_id
                WHERE twg_n_rem.merged_choice_id = {$choiceIdExpr}
                  AND twg_nq_rem.question_id = {$questionIdExpr}
            )
        )";
    }
}

if (!function_exists('twg_remaining_question_ids_for_choice')) {
    /**
     * Remaining award titles from linked registrations.
     * null = no registration is linked (use tbl_question_choices as-is).
     *
     * @return list<int>|null
     */
    function twg_remaining_question_ids_for_choice(mysqli $conn, int $choice_id): ?array
    {
        if ($choice_id <= 0) {
            return null;
        }
        $reasons = __DIR__ . '/award_removal_reasons.php';
        if (is_file($reasons)) {
            require_once $reasons;
        }
        if (!function_exists('award_nomination_ids_for_choice')) {
            return null;
        }
        $nomIds = award_nomination_ids_for_choice($conn, $choice_id);
        if ($nomIds === []) {
            return null;
        }

        $eventId = 0;
        $st = $conn->prepare('SELECT event_id FROM tbl_choices WHERE choice_id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $choice_id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            $eventId = (int) ($row['event_id'] ?? 0);
        }
        if ($eventId <= 0) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($nomIds), '?'));
        $types = str_repeat('i', count($nomIds)) . 'i';
        $catSql = admin_active_category_sql($conn, 'cat');
        $qSql = admin_active_question_sql($conn, 'q');
        $sql = "SELECT DISTINCT nq.question_id
                FROM tbl_nomination_questions nq
                INNER JOIN tbl_questions q ON q.question_id = nq.question_id
                INNER JOIN tbl_categories cat ON cat.category_id = q.category_id
                WHERE nq.nomination_id IN ($ph)
                  AND cat.event_id = ?
                  AND {$catSql}
                  AND {$qSql}";
        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param($types, ...[...$nomIds, $eventId]);
        $st->execute();
        $res = $st->get_result();
        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid > 0) {
                $ids[] = $qid;
            }
        }
        $st->close();
        return $ids;
    }
}

if (!function_exists('twg_sync_choice_awards_to_remaining')) {
    /**
     * Drop leftover award links that are not remaining on linked registrations.
     */
    function twg_sync_choice_awards_to_remaining(mysqli $conn, int $choice_id): void
    {
        if ($choice_id <= 0) {
            return;
        }
        $remaining = twg_remaining_question_ids_for_choice($conn, $choice_id);
        if ($remaining === null) {
            return;
        }
        $allowed = array_fill_keys($remaining, true);

        $current = [];
        $st = $conn->prepare('SELECT question_id FROM tbl_question_choices WHERE choice_id = ?');
        if ($st) {
            $st->bind_param('i', $choice_id);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $qid = (int) ($row['question_id'] ?? 0);
                if ($qid > 0) {
                    $current[$qid] = true;
                }
            }
            $st->close();
        }

        $reasons = __DIR__ . '/award_removal_reasons.php';
        if (is_file($reasons)) {
            require_once $reasons;
        }
        foreach ($current as $qid => $_) {
            if (!isset($allowed[$qid]) && function_exists('award_unlink_choice_question')) {
                award_unlink_choice_question($conn, $choice_id, (int) $qid);
            }
        }
        if ($remaining === []) {
            return;
        }
        $ins = $conn->prepare('INSERT IGNORE INTO tbl_question_choices (question_id, choice_id) VALUES (?, ?)');
        if (!$ins) {
            return;
        }
        foreach ($remaining as $qid) {
            $qid = (int) $qid;
            if ($qid <= 0 || isset($current[$qid])) {
                continue;
            }
            $ins->bind_param('ii', $qid, $choice_id);
            $ins->execute();
        }
        $ins->close();
        $helpers = __DIR__ . '/award_entry_helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
        if (function_exists('award_entry_ensure_ballot_for_choice')) {
            award_entry_ensure_ballot_for_choice($conn, $choice_id);
        }
    }
}

if (!function_exists('twg_list_businesses_for_event')) {
    /**
     * @return list<array{choice_id:int,choice_name:string,award_count:int,on_ballot:bool}>
     */
    function twg_list_businesses_for_event(mysqli $conn, int $event_id): array
    {
        if ($event_id <= 0) {
            return [];
        }
        $catSql = admin_active_category_sql($conn, 'cat');
        $qSql = admin_active_question_sql($conn, 'q');
        $hasOnBallot = false;
        if (is_file(__DIR__ . '/ballot_status.php')) {
            require_once __DIR__ . '/ballot_status.php';
            $hasOnBallot = function_exists('ballot_status_ensure_column') && ballot_status_ensure_column($conn);
        }
        $onBallotSelect = $hasOnBallot ? ', c.on_ballot' : ', 1 AS on_ballot';
        $remainingSql = twg_award_link_matches_remaining_sql($conn, 'c.choice_id', 'q.question_id');
        $sql = "
            SELECT c.choice_id, c.choice_name{$onBallotSelect},
                   COUNT(DISTINCT CASE WHEN {$catSql} AND {$qSql} AND {$remainingSql} THEN q.question_id END) AS award_count
            FROM tbl_choices c
            LEFT JOIN tbl_question_choices qc ON qc.choice_id = c.choice_id
            LEFT JOIN tbl_questions q ON q.question_id = qc.question_id
            LEFT JOIN tbl_categories cat ON cat.category_id = q.category_id AND cat.event_id = c.event_id
            WHERE c.event_id = ? AND COALESCE(c.status, 1) = 1
            GROUP BY c.choice_id, c.choice_name" . ($hasOnBallot ? ', c.on_ballot' : '') . "
            ORDER BY c.choice_name ASC
        ";
        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param('i', $event_id);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'choice_id' => (int) ($row['choice_id'] ?? 0),
                'choice_name' => (string) ($row['choice_name'] ?? ''),
                'award_count' => (int) ($row['award_count'] ?? 0),
                'on_ballot' => (int) ($row['on_ballot'] ?? 0) === 1,
            ];
        }
        $st->close();
        return $out;
    }
}

if (!function_exists('twg_fetch_results_overview')) {
    /**
     * All linked business × award TWG scores for an event.
     *
     * @return array{members:list<array{key:string,label:string,short:string}>,rows:list<array<string,mixed>>}
     */
    function twg_fetch_results_overview(mysqli $conn, int $event_id): array
    {
        twg_member_scores_ensure_schema($conn);
        $members = twg_member_definitions($conn, $event_id);
        if ($event_id <= 0) {
            return ['members' => $members, 'rows' => []];
        }

        $catSql = admin_active_category_sql($conn, 'cat');
        $qSql = admin_active_question_sql($conn, 'q');
        $remainingSql = twg_award_link_matches_remaining_sql($conn, 'c.choice_id', 'q.question_id');
        $linked = [];
        $sql = "
            SELECT c.choice_id, c.choice_name,
                   q.question_id, q.question_name,
                   cat.category_id, cat.category_name,
                   t.twg_average
            FROM tbl_choices c
            INNER JOIN tbl_question_choices qc ON qc.choice_id = c.choice_id
            INNER JOIN tbl_questions q ON q.question_id = qc.question_id
            INNER JOIN tbl_categories cat ON cat.category_id = q.category_id AND cat.event_id = c.event_id
            LEFT JOIN tbl_twg_scores t
              ON t.choice_id = c.choice_id AND t.question_id = q.question_id
            WHERE c.event_id = ?
              AND COALESCE(c.status, 1) = 1
              AND {$catSql}
              AND {$qSql}
              AND {$remainingSql}
            ORDER BY cat.category_name ASC, q.question_name ASC, c.choice_name ASC
        ";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('i', $event_id);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $linked[] = $row;
            }
            $st->close();
        }

        $scoreMap = [];
        $ms = $conn->prepare(
            "SELECT m.choice_id, m.question_id, m.member_key, m.score
             FROM tbl_twg_member_scores m
             INNER JOIN tbl_choices c ON c.choice_id = m.choice_id
             WHERE c.event_id = ?"
        );
        if ($ms) {
            $ms->bind_param('i', $event_id);
            $ms->execute();
            $res = $ms->get_result();
            while ($row = $res->fetch_assoc()) {
                $cid = (int) ($row['choice_id'] ?? 0);
                $qid = (int) ($row['question_id'] ?? 0);
                $key = (string) ($row['member_key'] ?? '');
                if ($cid > 0 && $qid > 0 && $key !== '') {
                    $scoreMap[$qid][$cid][$key] = results_formula_round((float) $row['score'], 2);
                }
            }
            $ms->close();
        }

        $helpers = __DIR__ . '/award_entry_helpers.php';
        $reasons = __DIR__ . '/award_removal_reasons.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
        if (is_file($reasons)) {
            require_once $reasons;
        }
        $qids = [];
        foreach ($linked as $row) {
            $qids[] = (int) ($row['question_id'] ?? 0);
        }
        $qids = array_values(array_unique(array_filter($qids)));
        $entryRowsByQ = ($qids !== [] && function_exists('award_entry_score_rows_for_questions'))
            ? award_entry_score_rows_for_questions($conn, $qids)
            : [];
        $entryScoreMap = $qids !== []
            ? twg_fetch_entry_member_score_map($conn, $qids)
            : [];
        $panelByQ = twg_panel_members_by_question($members, $scoreMap, $entryScoreMap);

        $rows = [];
        foreach ($linked as $row) {
            $cid = (int) ($row['choice_id'] ?? 0);
            $qid = (int) ($row['question_id'] ?? 0);
            if ($cid <= 0 || $qid <= 0) {
                continue;
            }
            $panel = $panelByQ[$qid] ?? $members;
            $titleScores = [];
            foreach ($panel as $member) {
                $titleScores[$member['key']] = $scoreMap[$qid][$cid][$member['key']] ?? null;
            }
            $entries = is_array($entryRowsByQ[$qid][$cid] ?? null) ? $entryRowsByQ[$qid][$cid] : [];
            $grade = twg_grade_award_pair(
                $entries,
                $titleScores,
                $entryScoreMap[$qid][$cid] ?? [],
                $panel
            );
            $entryNames = [];
            $entryKind = '';
            foreach ($grade['entries'] as $entry) {
                $name = trim((string) ($entry['entry_name'] ?? ''));
                if ($name !== '') {
                    $entryNames[] = $name;
                    if ($entryKind === '') {
                        $entryKind = (string) ($entry['entry_kind'] ?? 'product');
                    }
                }
            }
            $avg = $grade['average'];
            $scores100 = [];
            foreach ($grade['scores'] as $k => $v) {
                $scores100[$k] = $v === null ? null : results_formula_round((float) $v, 2);
            }
            $rows[] = [
                'choice_id' => $cid,
                'choice_name' => (string) ($row['choice_name'] ?? ''),
                'question_id' => $qid,
                'question_name' => (string) ($row['question_name'] ?? ''),
                'category_id' => (int) ($row['category_id'] ?? 0),
                'category_name' => (string) ($row['category_name'] ?? ''),
                'scores' => $grade['scores'],
                'scores_100' => $scores100,
                'scored' => (int) $grade['scored'],
                'member_count' => (int) $grade['member_count'],
                'twg_average' => $avg,
                'twg_average_100' => $avg === null ? null : results_formula_round((float) $avg, 2),
                'twg_average_10' => twg_average_on_ten($avg === null ? null : (float) $avg),
                'twg_entered' => $avg !== null,
                'entry_names' => $entryNames,
                'entry_kind' => $entryKind,
                'entries' => $grade['entries'],
            ];
        }

        $byAward = [];
        foreach ($rows as $i => $row) {
            $byAward[$row['question_id']][] = $i;
        }
        foreach ($byAward as $indexes) {
            usort($indexes, static function ($a, $b) use ($rows) {
                $aHas = !empty($rows[$a]['twg_entered']);
                $bHas = !empty($rows[$b]['twg_entered']);
                if ($aHas !== $bHas) {
                    return $aHas ? -1 : 1;
                }
                if ($aHas) {
                    $cmp = ((float) $rows[$b]['twg_average']) <=> ((float) $rows[$a]['twg_average']);
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }
                return strcasecmp((string) $rows[$a]['choice_name'], (string) $rows[$b]['choice_name']);
            });
            $rank = 0;
            $previous = null;
            foreach ($indexes as $i) {
                if (empty($rows[$i]['twg_entered'])) {
                    $rows[$i]['twg_rank'] = null;
                    continue;
                }
                $avg = (float) $rows[$i]['twg_average'];
                if ($previous === null || abs($avg - $previous) > 0.0001) {
                    $rank++;
                }
                $rows[$i]['twg_rank'] = $rank;
                $previous = $avg;
            }
        }

        $rubric = function_exists('twg_rubric_for_event') ? twg_rubric_for_event($conn, $event_id) : [];
        $rubricMap = ($qids !== [] && function_exists('twg_rubric_fetch_score_map'))
            ? twg_rubric_fetch_score_map($conn, $qids)
            : [];
        foreach ($rows as &$row) {
            $qid = (int) $row['question_id'];
            $cid = (int) $row['choice_id'];
            $row['rubric_scores'] = $rubricMap[$qid][$cid][0] ?? [];
        }
        unset($row);

        return [
            'members' => $members,
            'rubric' => $rubric,
            'rows' => $rows,
        ];
    }
}

if (!function_exists('twg_fetch_sheet_for_choice')) {
    /**
     * Award titles currently linked to one business.
     *
     * @return array{members:list<array<string,string>>,choice:?array<string,mixed>,awards:list<array<string,mixed>>}
     */
    function twg_fetch_sheet_for_choice(mysqli $conn, int $event_id, int $choice_id): array
    {
        twg_member_scores_ensure_schema($conn);
        $members = twg_member_definitions($conn, $event_id);
        $empty = ['members' => $members, 'choice' => null, 'awards' => []];
        if ($event_id <= 0 || $choice_id <= 0) {
            return $empty;
        }

        $hasOnBallot = false;
        if (is_file(__DIR__ . '/ballot_status.php')) {
            require_once __DIR__ . '/ballot_status.php';
            $hasOnBallot = function_exists('ballot_status_ensure_column') && ballot_status_ensure_column($conn);
        }
        $onBallotSelect = $hasOnBallot ? ', on_ballot' : ', 1 AS on_ballot';
        $catSql = admin_active_category_sql($conn, 'cat');
        $qSql = admin_active_question_sql($conn, 'q');

        $biz = null;
        $st = $conn->prepare("SELECT choice_id, choice_name{$onBallotSelect} FROM tbl_choices WHERE choice_id = ? AND event_id = ? LIMIT 1");
        if ($st) {
            $st->bind_param('ii', $choice_id, $event_id);
            $st->execute();
            $biz = $st->get_result()->fetch_assoc() ?: null;
            $st->close();
        }
        if (!$biz) {
            return $empty;
        }

        twg_sync_choice_awards_to_remaining($conn, $choice_id);

        $linked = [];
        $remainingSql = twg_award_link_matches_remaining_sql($conn, 'qc.choice_id', 'q.question_id');
        $sql = "
            SELECT q.question_id, q.question_name, cat.category_name, t.twg_average
            FROM tbl_question_choices qc
            INNER JOIN tbl_questions q ON q.question_id = qc.question_id
            INNER JOIN tbl_categories cat ON cat.category_id = q.category_id
            LEFT JOIN tbl_twg_scores t
              ON t.question_id = q.question_id AND t.choice_id = qc.choice_id
            WHERE qc.choice_id = ? AND cat.event_id = ? AND {$catSql} AND {$qSql}
              AND {$remainingSql}
            ORDER BY cat.category_name ASC, q.question_name ASC
        ";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('ii', $choice_id, $event_id);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $linked[] = $row;
            }
            $st->close();
        }

        $qids = [];
        foreach ($linked as $row) {
            $qids[] = (int) ($row['question_id'] ?? 0);
        }
        $qids = array_values(array_filter($qids));
        $titleByQ = [];
        if ($qids !== []) {
            $ph = implode(',', array_fill(0, count($qids), '?'));
            $types = str_repeat('i', count($qids));
            $sql = "SELECT question_id, choice_id, member_key, score
                    FROM tbl_twg_member_scores
                    WHERE question_id IN ($ph)";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param($types, ...$qids);
                $st->execute();
                $res = $st->get_result();
                while ($r = $res->fetch_assoc()) {
                    $qid = (int) $r['question_id'];
                    $cid = (int) ($r['choice_id'] ?? 0);
                    $key = (string) $r['member_key'];
                    if ($qid > 0 && $cid > 0 && $key !== '') {
                        $titleByQ[$qid][$cid][$key] = results_formula_round((float) $r['score'], 2);
                    }
                }
                $st->close();
            }
        }

        $awards = [];
        $helpers = __DIR__ . '/award_entry_helpers.php';
        $reasons = __DIR__ . '/award_removal_reasons.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
        if (is_file($reasons)) {
            require_once $reasons;
        }
        $entryRowsByQ = function_exists('award_entry_score_rows_for_choice')
            ? award_entry_score_rows_for_choice($conn, $choice_id)
            : [];
        $entryScoreMap = $qids !== []
            ? twg_fetch_entry_member_score_map($conn, $qids)
            : [];
        $panelByQ = twg_panel_members_by_question($members, $titleByQ, $entryScoreMap);
        foreach ($linked as $row) {
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                continue;
            }
            $panel = $panelByQ[$qid] ?? $members;
            $titleScores = [];
            foreach ($panel as $m) {
                $titleScores[$m['key']] = $titleByQ[$qid][$choice_id][$m['key']] ?? null;
            }
            $entries = is_array($entryRowsByQ[$qid] ?? null) ? $entryRowsByQ[$qid] : [];
            $grade = twg_grade_award_pair(
                $entries,
                $titleScores,
                $entryScoreMap[$qid][$choice_id] ?? [],
                $panel
            );
            $entryNames = [];
            $entryKind = '';
            foreach ($grade['entries'] as $entry) {
                $name = trim((string) ($entry['entry_name'] ?? ''));
                if ($name !== '') {
                    $entryNames[] = $name;
                    if ($entryKind === '') {
                        $entryKind = (string) ($entry['entry_kind'] ?? 'product');
                    }
                }
            }
            $awards[] = [
                'question_id' => $qid,
                'question_name' => (string) ($row['question_name'] ?? ''),
                'category_name' => (string) ($row['category_name'] ?? ''),
                'choice_id' => $choice_id,
                'entry_names' => $entryNames,
                'entry_kind' => $entryKind,
                'entries' => $grade['entries'],
                'scores' => $grade['scores'],
                'scored' => (int) $grade['scored'],
                'member_count' => (int) $grade['member_count'],
                'average' => $grade['average'],
            ];
        }

        return [
            'members' => $members,
            'choice' => [
                'choice_id' => (int) $biz['choice_id'],
                'choice_name' => (string) ($biz['choice_name'] ?? ''),
                'on_ballot' => (int) ($biz['on_ballot'] ?? 0) === 1,
            ],
            'awards' => $awards,
        ];
    }
}
