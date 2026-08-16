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
     *   leader: ?array<string,mixed>
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

        $twgMap = [];
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
            $row['vote_share'] = results_formula_round($share, 2);
            $row['community_score'] = results_formula_round($community, 2);
            $row['twg_average'] = $twg === null ? null : results_formula_round($twg, 2);
            $row['twg_entered'] = $hasTwg;
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

        $leader = $rows[0] ?? null;

        return [
            'results' => $rows,
            'total_votes' => $totalVotes,
            'nominee_count' => count($rows),
            'twg_entered' => $twgEntered,
            'leader' => $leader,
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

if (!function_exists('twg_save_member_score')) {
    /**
     * @return array{ok:bool,message:string,average:?float,scored:int}
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
