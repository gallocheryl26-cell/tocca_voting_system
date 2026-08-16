<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_schema.php';

/**
 * Official TOCCA ranking:
 *   Community score (0–10) = (votes ÷ total votes in the award) × 10
 *   Final score = (TWG weighted average × 30%) + (community score × 70%)
 *   Standing / Top 10 = rank by final score (ties share a dense rank)
 */

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
        if ($twg_average > 10) {
            $twg_average = 10;
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
        $hasOnBallot = false;
        if (is_file(__DIR__ . '/ballot_status.php')) {
            require_once __DIR__ . '/ballot_status.php';
            $hasOnBallot = function_exists('ballot_status_ensure_column') && ballot_status_ensure_column($conn);
        }

        $onBallotSelect = $hasOnBallot ? ', c.on_ballot' : ', 1 AS on_ballot';
        $rows = [];
        $seen = [];

        $sql = "
            SELECT c.choice_id, c.choice_name, c.status{$onBallotSelect},
                   COUNT(DISTINCT p.voters_id) AS vote_count
            FROM tbl_question_choices qc
            INNER JOIN tbl_choices c ON c.choice_id = qc.choice_id
            LEFT JOIN tbl_poll_choice p
              ON p.choice_id = c.choice_id AND p.question_id = qc.question_id
            WHERE qc.question_id = ? AND c.event_id = ?
            GROUP BY c.choice_id, c.choice_name, c.status" . ($hasOnBallot ? ', c.on_ballot' : '') . "
        ";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('ii', $question_id, $event_id);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $cid = (int) ($row['choice_id'] ?? 0);
                $rows[] = [
                    'choice_id' => $cid > 0 ? $cid : null,
                    'choice_name' => (string) ($row['choice_name'] ?? 'Unknown'),
                    'vote_count' => (int) ($row['vote_count'] ?? 0),
                    'status' => (int) ($row['status'] ?? 0),
                    'on_ballot' => (int) ($row['on_ballot'] ?? 0) === 1,
                    'is_freetext' => false,
                ];
                if ($cid > 0) {
                    $seen[$cid] = true;
                }
            }
            $st->close();
        }

        // Votes for businesses no longer linked to the award (keep history).
        $sql = "
            SELECT p.choice_id,
                   COALESCE(ch.choice_name, 'Unknown/Deleted') AS choice_name,
                   COUNT(DISTINCT p.voters_id) AS vote_count
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
                if ($cid > 0 && isset($seen[$cid])) {
                    continue;
                }
                $rows[] = [
                    'choice_id' => $cid > 0 ? $cid : null,
                    'choice_name' => (string) ($row['choice_name'] ?? 'Unknown'),
                    'vote_count' => (int) ($row['vote_count'] ?? 0),
                    'status' => 0,
                    'on_ballot' => false,
                    'is_freetext' => false,
                ];
                if ($cid > 0) {
                    $seen[$cid] = true;
                }
            }
            $st->close();
        }

        $sql = "
            SELECT pf.freetext AS choice_name, COUNT(*) AS vote_count
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
            while ($row = $res->fetch_assoc()) {
                $rows[] = [
                    'choice_id' => null,
                    'choice_name' => (string) ($row['choice_name'] ?? ''),
                    'vote_count' => (int) ($row['vote_count'] ?? 0),
                    'status' => 0,
                    'on_ballot' => false,
                    'is_freetext' => true,
                ];
            }
            $st->close();
        }

        twg_member_scores_ensure_schema($conn);
        $twgMembers = twg_member_definitions();
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

        $totalVotes = 0;
        foreach ($rows as $row) {
            $totalVotes += (int) $row['vote_count'];
        }

        $twgEntered = 0;
        foreach ($rows as &$row) {
            $votes = (int) $row['vote_count'];
            $share = $totalVotes > 0 ? ($votes / $totalVotes) * 100 : 0.0;
            $community = $totalVotes > 0 ? ($votes / $totalVotes) * 10 : 0.0;
            $cid = (int) ($row['choice_id'] ?? 0);
            $hasTwg = $cid > 0 && array_key_exists($cid, $twgMap);
            $twg = $hasTwg ? (float) $twgMap[$cid] : null;
            if ($hasTwg) {
                $twgEntered++;
            }
            $twgForFormula = $twg ?? 0.0;
            $final = ($twgForFormula * 0.30) + ($community * 0.70);
            $memberScores = [];
            if ($cid > 0) {
                foreach ($twgMembers as $member) {
                    $memberScores[$member['key']] = $twgScoreMap[$cid][$member['key']] ?? null;
                }
            } else {
                foreach ($twgMembers as $member) {
                    $memberScores[$member['key']] = null;
                }
            }
            $filled = array_values(array_filter($memberScores, static fn($v) => $v !== null));
            $row['vote_share'] = results_formula_round($share, 2);
            $row['community_score'] = results_formula_round($community, 2);
            $row['twg_average'] = $twg === null ? null : results_formula_round($twg, 2);
            $row['twg_entered'] = $hasTwg;
            $row['twg_scores'] = $memberScores;
            $row['twg_scored'] = count($filled);
            $row['twg_member_count'] = count($twgMembers);
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
            $row['top10'] = $rank <= 10;
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
            $key = !empty($twgRow['choice_id'])
                ? 'c:' . (int) $twgRow['choice_id']
                : 'f:' . (string) $twgRow['choice_name'];
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
            $key = !empty($row['choice_id'])
                ? 'c:' . (int) $row['choice_id']
                : 'f:' . (string) $row['choice_name'];
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
    /** @return list<array{key:string,label:string,short:string}> */
    function twg_member_definitions(): array
    {
        return [
            ['key' => 'lgu_1', 'label' => 'LGU Head 1', 'short' => 'LGU 1'],
            ['key' => 'lgu_2', 'label' => 'LGU Head 2', 'short' => 'LGU 2'],
            ['key' => 'bplo', 'label' => 'BPLO', 'short' => 'BPLO'],
            ['key' => 'ledipo', 'label' => 'LEDIPO', 'short' => 'LEDIPO'],
            ['key' => 'orcham', 'label' => 'ORCHAM', 'short' => 'ORCHAM'],
        ];
    }
}

if (!function_exists('twg_member_keys')) {
    /** @return list<string> */
    function twg_member_keys(): array
    {
        return array_map(static fn(array $m): string => $m['key'], twg_member_definitions());
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
        $done = true;
    }
}

if (!function_exists('twg_recompute_average')) {
    function twg_recompute_average(mysqli $conn, int $question_id, int $choice_id): ?float
    {
        twg_member_scores_ensure_schema($conn);
        $st = $conn->prepare(
            'SELECT AVG(score) AS avg_score, COUNT(*) AS n
             FROM tbl_twg_member_scores
             WHERE question_id = ? AND choice_id = ?'
        );
        if (!$st) {
            return null;
        }
        $st->bind_param('ii', $question_id, $choice_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        $n = (int) ($row['n'] ?? 0);
        if ($n < 1) {
            $del = $conn->prepare('DELETE FROM tbl_twg_scores WHERE question_id = ? AND choice_id = ?');
            if ($del) {
                $del->bind_param('ii', $question_id, $choice_id);
                $del->execute();
                $del->close();
            }
            return null;
        }
        $avg = results_formula_round((float) $row['avg_score'], 2);
        results_formula_save_twg($conn, $question_id, $choice_id, $avg);
        return $avg;
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
            return ['ok' => false, 'message' => 'Unknown TWG member.', 'average' => null, 'scored' => 0];
        }
        if ($question_id <= 0 || $choice_id <= 0) {
            return ['ok' => false, 'message' => 'Missing award or business.', 'average' => null, 'scored' => 0];
        }
        twg_member_scores_ensure_schema($conn);

        $existing = twg_get_member_score($conn, $question_id, $choice_id, $member_key);
        if ($existing !== null) {
            return [
                'ok' => false,
                'message' => 'This score is already saved and cannot be changed.',
                'average' => null,
                'scored' => 0,
                'locked' => true,
            ];
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
            if ($score < 1 || $score > 10) {
                return ['ok' => false, 'message' => 'Score must be from 1 to 10.', 'average' => null, 'scored' => 0];
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
            return ['ok' => false, 'score' => null, 'message' => 'Score must be a number from 1 to 10.'];
        }
        $score = round((float) $raw, 2);
        if ($score < 1 || $score > 10) {
            return ['ok' => false, 'score' => null, 'message' => 'Score must be from 1 to 10.'];
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
                        'member_key' => $memberKey,
                    ],
                ];
            }
            if ($parsed['score'] === null) {
                continue;
            }
            $dedupe = $questionId . ':' . $memberKey;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $existing = twg_get_member_score($conn, $questionId, $choice_id, $memberKey);
            if ($existing !== null) {
                if (abs($existing - $parsed['score']) > 0.001) {
                    return [
                        'ok' => false,
                        'message' => 'Saved scores cannot be changed.',
                        'saved' => 0,
                        'rows' => [],
                        'invalid' => [
                            'question_id' => $questionId,
                            'member_key' => $memberKey,
                        ],
                    ];
                }
                $skippedLocked++;
                continue;
            }
            $pending[] = [
                'question_id' => $questionId,
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
                    $pendingMap[(int) $item['question_id']][(string) $item['member_key']] = true;
                }
                $memberDefs = twg_member_definitions();
                $missingParts = [];
                foreach ($sheet['awards'] ?? [] as $award) {
                    if (!is_array($award)) {
                        continue;
                    }
                    $qid = (int) ($award['question_id'] ?? 0);
                    $scores = is_array($award['scores'] ?? null) ? $award['scores'] : [];
                    $missingLabels = [];
                    foreach ($memberDefs as $member) {
                        $mk = (string) ($member['key'] ?? '');
                        if ($mk === '') {
                            continue;
                        }
                        $have = $scores[$mk] ?? null;
                        if ($have === null && empty($pendingMap[$qid][$mk])) {
                            $missingLabels[] = (string) ($member['short'] ?? $mk);
                        }
                    }
                    if ($missingLabels === []) {
                        continue;
                    }
                    $awardName = trim((string) ($award['category_name'] ?? '') . ' - ' . (string) ($award['question_name'] ?? 'Award'), " -\t\n\r\0\x0B");
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

        foreach ($pending as $item) {
            $questionId = (int) $item['question_id'];
            $memberKey = (string) $item['member_key'];
            if (!twg_choice_linked_to_question($conn, $questionId, $choice_id)) {
                return ['ok' => false, 'message' => 'That business is not linked to this award.', 'saved' => 0, 'rows' => []];
            }
            $result = twg_save_member_score($conn, $questionId, $choice_id, $memberKey, $item['score']);
            if (!$result['ok']) {
                return ['ok' => false, 'message' => $result['message'], 'saved' => 0, 'rows' => []];
            }
            $saved++;
            $outRows[$questionId] = [
                'question_id' => $questionId,
                'choice_id' => $choice_id,
                'average' => $result['average'],
                'scored' => $result['scored'],
                'member_count' => count(twg_member_keys()),
            ];
        }

        if ($saved < 1) {
            return [
                'ok' => false,
                'message' => $skippedLocked > 0
                    ? 'Saved scores cannot be changed.'
                    : 'Enter at least one score from 1 to 10 before saving.',
                'saved' => 0,
                'rows' => [],
            ];
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
        return $ok;
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
        $members = twg_member_definitions();
        $hasOnBallot = false;
        if (is_file(__DIR__ . '/ballot_status.php')) {
            require_once __DIR__ . '/ballot_status.php';
            $hasOnBallot = function_exists('ballot_status_ensure_column') && ballot_status_ensure_column($conn);
        }
        $onBallotSelect = $hasOnBallot ? ', c.on_ballot' : ', 1 AS on_ballot';

        $linked = [];
        $sql = "
            SELECT c.choice_id, c.choice_name{$onBallotSelect}, t.twg_average
            FROM tbl_question_choices qc
            INNER JOIN tbl_choices c ON c.choice_id = qc.choice_id
            LEFT JOIN tbl_twg_scores t
              ON t.choice_id = c.choice_id AND t.question_id = qc.question_id
            WHERE qc.question_id = ? AND c.event_id = ?
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
        $sql = "
            SELECT c.choice_id, c.choice_name{$onBallotSelect},
                   COUNT(DISTINCT CASE WHEN {$catSql} AND {$qSql} THEN q.question_id END) AS award_count
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
        $members = twg_member_definitions();
        if ($event_id <= 0) {
            return ['members' => $members, 'rows' => []];
        }

        $catSql = admin_active_category_sql($conn, 'cat');
        $qSql = admin_active_question_sql($conn, 'q');
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

        $rows = [];
        foreach ($linked as $row) {
            $cid = (int) ($row['choice_id'] ?? 0);
            $qid = (int) ($row['question_id'] ?? 0);
            if ($cid <= 0 || $qid <= 0) {
                continue;
            }
            $scores = [];
            foreach ($members as $member) {
                $scores[$member['key']] = $scoreMap[$qid][$cid][$member['key']] ?? null;
            }
            $filled = array_values(array_filter($scores, static fn($v) => $v !== null));
            $avg = $row['twg_average'] ?? null;
            $rows[] = [
                'choice_id' => $cid,
                'choice_name' => (string) ($row['choice_name'] ?? ''),
                'question_id' => $qid,
                'question_name' => (string) ($row['question_name'] ?? ''),
                'category_id' => (int) ($row['category_id'] ?? 0),
                'category_name' => (string) ($row['category_name'] ?? ''),
                'scores' => $scores,
                'scored' => count($filled),
                'member_count' => count($members),
                'twg_average' => $avg === null || $avg === '' ? null : results_formula_round((float) $avg, 2),
                'twg_entered' => $avg !== null && $avg !== '',
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

        return [
            'members' => $members,
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
        $members = twg_member_definitions();
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

        $linked = [];
        $sql = "
            SELECT q.question_id, q.question_name, cat.category_name, t.twg_average
            FROM tbl_question_choices qc
            INNER JOIN tbl_questions q ON q.question_id = qc.question_id
            INNER JOIN tbl_categories cat ON cat.category_id = q.category_id
            LEFT JOIN tbl_twg_scores t
              ON t.question_id = q.question_id AND t.choice_id = qc.choice_id
            WHERE qc.choice_id = ? AND cat.event_id = ? AND {$catSql} AND {$qSql}
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
        $scoreMap = [];
        if ($qids !== []) {
            $ph = implode(',', array_fill(0, count($qids), '?'));
            $types = 'i' . str_repeat('i', count($qids));
            $sql = "SELECT question_id, member_key, score
                    FROM tbl_twg_member_scores
                    WHERE choice_id = ? AND question_id IN ($ph)";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param($types, $choice_id, ...$qids);
                $st->execute();
                $res = $st->get_result();
                while ($r = $res->fetch_assoc()) {
                    $qid = (int) $r['question_id'];
                    $scoreMap[$qid][(string) $r['member_key']] = results_formula_round((float) $r['score'], 2);
                }
                $st->close();
            }
        }

        $awards = [];
        foreach ($linked as $row) {
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                continue;
            }
            $scores = [];
            foreach ($members as $m) {
                $scores[$m['key']] = $scoreMap[$qid][$m['key']] ?? null;
            }
            $filled = array_values(array_filter($scores, static fn($v) => $v !== null));
            $avg = $row['twg_average'] ?? null;
            $awards[] = [
                'question_id' => $qid,
                'question_name' => (string) ($row['question_name'] ?? ''),
                'category_name' => (string) ($row['category_name'] ?? ''),
                'choice_id' => $choice_id,
                'scores' => $scores,
                'scored' => count($filled),
                'member_count' => count($members),
                'average' => $avg === null ? null : results_formula_round((float) $avg, 2),
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
