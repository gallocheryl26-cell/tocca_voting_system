<?php
declare(strict_types=1);

/**
 * Per-judge scoring items (Taste 50 / Innovation 20 / Value 30).
 * Judge columns stay in tbl_twg_criteria. These items add up to 100.
 * Judge total (Taste + Innovation + Value, max 100) is stored as-is in
 * tbl_twg_member_scores so By-business encoding and Results use 0–100.
 */

require_once __DIR__ . '/twg_criteria.php';

if (!function_exists('twg_rubric_defaults')) {
    /** @return list<array{key:string,label:string,short:string,max:float}> */
    function twg_rubric_defaults(): array
    {
        return [
            ['key' => 'taste', 'label' => 'Taste & Quality', 'short' => 'Taste', 'max' => 50.0],
            ['key' => 'innovation', 'label' => 'Innovation', 'short' => 'Innovation', 'max' => 20.0],
            ['key' => 'value', 'label' => 'Portion Size & Value for Money', 'short' => 'Value', 'max' => 30.0],
        ];
    }
}

if (!function_exists('twg_rubric_ensure_schema')) {
    function twg_rubric_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $conn->query(
            "CREATE TABLE IF NOT EXISTS tbl_twg_rubric_items (
                item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id INT NOT NULL,
                item_key VARCHAR(32) NOT NULL,
                label VARCHAR(80) NOT NULL,
                short_label VARCHAR(32) NOT NULL,
                max_points DECIMAL(8,3) NOT NULL DEFAULT 10.000,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (item_id),
                UNIQUE KEY uq_twg_rubric_event_key (event_id, item_key),
                KEY idx_twg_rubric_event (event_id, is_active, display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->query(
            "CREATE TABLE IF NOT EXISTS tbl_twg_rubric_scores (
                question_id INT NOT NULL,
                choice_id INT NOT NULL,
                ballot_entry_id INT NOT NULL DEFAULT 0,
                judge_key VARCHAR(32) NOT NULL,
                item_key VARCHAR(32) NOT NULL,
                score DECIMAL(8,2) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (question_id, choice_id, ballot_entry_id, judge_key, item_key),
                KEY idx_twg_rubric_q (question_id),
                KEY idx_twg_rubric_c (choice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    }
}

if (!function_exists('twg_rubric_seed_defaults')) {
    function twg_rubric_seed_defaults(mysqli $conn, int $eventId): void
    {
        if ($eventId <= 0) {
            return;
        }
        twg_rubric_ensure_schema($conn);
        $st = $conn->prepare('SELECT COUNT(*) AS n FROM tbl_twg_rubric_items WHERE event_id = ?');
        if (!$st) {
            return;
        }
        $st->bind_param('i', $eventId);
        $st->execute();
        $n = (int) ($st->get_result()->fetch_assoc()['n'] ?? 0);
        $st->close();
        if ($n > 0) {
            return;
        }
        $ins = $conn->prepare(
            'INSERT INTO tbl_twg_rubric_items (event_id, item_key, label, short_label, max_points, display_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        if (!$ins) {
            return;
        }
        foreach (twg_rubric_defaults() as $i => $row) {
            $key = (string) $row['key'];
            $label = (string) $row['label'];
            $short = (string) $row['short'];
            $max = (float) $row['max'];
            $order = $i + 1;
            $ins->bind_param('isssdi', $eventId, $key, $label, $short, $max, $order);
            $ins->execute();
        }
        $ins->close();
    }
}

if (!function_exists('twg_rubric_for_event')) {
    /** @return list<array{key:string,label:string,short:string,max:float,item_id:int}> */
    function twg_rubric_for_event(mysqli $conn, ?int $eventId = null): array
    {
        $eventId = twg_criteria_resolve_event_id($conn, $eventId);
        if ($eventId <= 0) {
            return twg_rubric_defaults();
        }
        twg_rubric_ensure_schema($conn);
        twg_rubric_seed_defaults($conn, $eventId);
        $st = $conn->prepare(
            'SELECT item_id, item_key, label, short_label, max_points
             FROM tbl_twg_rubric_items
             WHERE event_id = ? AND is_active = 1
             ORDER BY display_order ASC, item_id ASC'
        );
        if (!$st) {
            return twg_rubric_defaults();
        }
        $st->bind_param('i', $eventId);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'item_id' => (int) ($row['item_id'] ?? 0),
                'key' => (string) ($row['item_key'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'short' => (string) ($row['short_label'] ?? ''),
                'max' => (float) ($row['max_points'] ?? 0),
            ];
        }
        $st->close();
        return $out !== [] ? $out : twg_rubric_defaults();
    }
}

if (!function_exists('twg_rubric_save_items')) {
    /**
     * @param list<array<string,mixed>> $items
     * @return array{ok:bool,message:string,rubric:list<array<string,mixed>>}
     */
    function twg_rubric_save_items(mysqli $conn, int $eventId, array $items): array
    {
        if ($eventId <= 0) {
            return ['ok' => false, 'message' => 'Missing event.', 'rubric' => []];
        }
        twg_rubric_ensure_schema($conn);
        $clean = [];
        $used = [];
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $short = trim((string) ($item['short'] ?? $item['short_label'] ?? ''));
            if ($short === '') {
                $short = $label;
            }
            $max = (float) ($item['max'] ?? $item['max_points'] ?? 0);
            if ($max <= 0) {
                $max = 10;
            }
            $key = strtolower(trim((string) ($item['key'] ?? '')));
            if ($key === '' || isset($used[$key])) {
                $key = function_exists('twg_criteria_slug')
                    ? twg_criteria_slug($label, array_keys($used))
                    : ('item_' . ($i + 1));
            }
            $used[$key] = true;
            $clean[] = [
                'key' => $key,
                'label' => $label,
                'short' => substr($short, 0, 32),
                'max' => $max,
            ];
        }
        if ($clean === []) {
            return ['ok' => false, 'message' => 'Add at least one scoring item.', 'rubric' => []];
        }
        $conn->begin_transaction();
        try {
            $del = $conn->prepare('DELETE FROM tbl_twg_rubric_items WHERE event_id = ?');
            $del->bind_param('i', $eventId);
            $del->execute();
            $del->close();
            $ins = $conn->prepare(
                'INSERT INTO tbl_twg_rubric_items (event_id, item_key, label, short_label, max_points, display_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)'
            );
            foreach ($clean as $i => $row) {
                $order = $i + 1;
                $ins->bind_param('isssdi', $eventId, $row['key'], $row['label'], $row['short'], $row['max'], $order);
                $ins->execute();
            }
            $ins->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Could not save scoring items.', 'rubric' => []];
        }
        return [
            'ok' => true,
            'message' => 'Scoring items saved.',
            'rubric' => twg_rubric_for_event($conn, $eventId),
        ];
    }
}

if (!function_exists('twg_rubric_fetch_score_map')) {
    /**
     * @param list<int> $questionIds
     * @return array<int, array<int, array<int, array<string, array<string, float>>>>>
     */
    function twg_rubric_fetch_score_map(mysqli $conn, array $questionIds): array
    {
        twg_rubric_ensure_schema($conn);
        $questionIds = array_values(array_unique(array_filter(array_map('intval', $questionIds))));
        if ($questionIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($questionIds), '?'));
        $st = $conn->prepare(
            "SELECT question_id, choice_id, ballot_entry_id, judge_key, item_key, score
             FROM tbl_twg_rubric_scores
             WHERE question_id IN ($ph)"
        );
        if (!$st) {
            return [];
        }
        $types = str_repeat('i', count($questionIds));
        $st->bind_param($types, ...$questionIds);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $qid = (int) ($row['question_id'] ?? 0);
            $cid = (int) ($row['choice_id'] ?? 0);
            $eid = (int) ($row['ballot_entry_id'] ?? 0);
            $judge = strtolower(trim((string) ($row['judge_key'] ?? '')));
            $item = strtolower(trim((string) ($row['item_key'] ?? '')));
            if ($qid <= 0 || $cid <= 0 || $judge === '' || $item === '') {
                continue;
            }
            $out[$qid][$cid][$eid][$judge][$item] = function_exists('results_formula_round')
                ? results_formula_round((float) $row['score'], 2)
                : round((float) $row['score'], 2);
        }
        $st->close();
        return $out;
    }
}

if (!function_exists('twg_rubric_judge_total')) {
    /**
     * @param list<array{key?:string,max?:float}> $items
     * @param array<string, float|null> $scores
     */
    function twg_rubric_judge_total(array $items, array $scores): ?float
    {
        if ($items === []) {
            return null;
        }
        $sum = 0.0;
        foreach ($items as $item) {
            $key = strtolower(trim((string) ($item['key'] ?? '')));
            if ($key === '') {
                continue;
            }
            $val = $scores[$key] ?? null;
            if ($val === null || $val === '') {
                return null;
            }
            $sum += (float) $val;
        }
        return function_exists('results_formula_round')
            ? results_formula_round($sum, 2)
            : round($sum, 2);
    }
}

if (!function_exists('twg_rubric_save_cells')) {
    /**
     * @param list<array<string,mixed>> $cells
     * @return array{ok:bool,message:string,saved:int,invalid?:array<string,mixed>}
     */
    function twg_rubric_save_cells(mysqli $conn, int $eventId, array $cells): array
    {
        if ($eventId <= 0) {
            return ['ok' => false, 'message' => 'Missing event.', 'saved' => 0];
        }
        twg_rubric_ensure_schema($conn);
        $items = twg_rubric_for_event($conn, $eventId);
        $itemByKey = [];
        foreach ($items as $item) {
            $itemByKey[strtolower((string) $item['key'])] = $item;
        }
        $judgeKeys = twg_member_keys($conn, $eventId);
        $saved = 0;
        $touched = [];
        foreach ($cells as $cell) {
            if (!is_array($cell)) {
                continue;
            }
            $qid = (int) ($cell['question_id'] ?? 0);
            $cid = (int) ($cell['choice_id'] ?? 0);
            $eid = (int) ($cell['ballot_entry_id'] ?? 0);
            $judge = strtolower(trim((string) ($cell['judge_key'] ?? $cell['member_key'] ?? '')));
            $itemKey = strtolower(trim((string) ($cell['item_key'] ?? '')));
            if ($qid <= 0 || $cid <= 0 || $judge === '' || $itemKey === '') {
                continue;
            }
            if (function_exists('twg_choice_scores_locked') && twg_choice_scores_locked($conn, $cid)) {
                continue;
            }
            if (!in_array($judge, $judgeKeys, true) || !isset($itemByKey[$itemKey])) {
                continue;
            }
            $raw = $cell['score'] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            if (!is_numeric($raw)) {
                return [
                    'ok' => false,
                    'message' => 'Each score must be a number.',
                    'saved' => 0,
                    'invalid' => ['question_id' => $qid, 'choice_id' => $cid, 'judge_key' => $judge, 'item_key' => $itemKey],
                ];
            }
            $score = round((float) $raw, 2);
            $max = (float) $itemByKey[$itemKey]['max'];
            if ($score < 0 || $score > $max) {
                return [
                    'ok' => false,
                    'message' => 'Score must be from 0 to ' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . '.',
                    'saved' => 0,
                    'invalid' => ['question_id' => $qid, 'choice_id' => $cid, 'judge_key' => $judge, 'item_key' => $itemKey],
                ];
            }
            $existing = $conn->prepare(
                'SELECT score FROM tbl_twg_rubric_scores
                 WHERE question_id = ? AND choice_id = ? AND ballot_entry_id = ? AND judge_key = ? AND item_key = ?
                 LIMIT 1'
            );
            $existing->bind_param('iiiss', $qid, $cid, $eid, $judge, $itemKey);
            $existing->execute();
            $have = $existing->get_result()->fetch_assoc();
            $existing->close();
            if ($have) {
                if (function_exists('twg_choice_scores_locked') && twg_choice_scores_locked($conn, $cid)) {
                    continue;
                }
                $upd = $conn->prepare(
                    'UPDATE tbl_twg_rubric_scores
                     SET score = ?
                     WHERE question_id = ? AND choice_id = ? AND ballot_entry_id = ? AND judge_key = ? AND item_key = ?'
                );
                if (!$upd) {
                    return ['ok' => false, 'message' => 'Could not save score.', 'saved' => 0];
                }
                $upd->bind_param('diiiss', $score, $qid, $cid, $eid, $judge, $itemKey);
                if (!$upd->execute()) {
                    $upd->close();
                    return ['ok' => false, 'message' => 'Could not save score.', 'saved' => 0];
                }
                $upd->close();
                $saved++;
                $touched[$qid . ':' . $cid . ':' . $eid . ':' . $judge] = [$qid, $cid, $eid, $judge];
                continue;
            }
            $ins = $conn->prepare(
                'INSERT INTO tbl_twg_rubric_scores (question_id, choice_id, ballot_entry_id, judge_key, item_key, score)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->bind_param('iiissd', $qid, $cid, $eid, $judge, $itemKey, $score);
            if (!$ins->execute()) {
                $ins->close();
                return ['ok' => false, 'message' => 'Could not save score.', 'saved' => 0];
            }
            $ins->close();
            $saved++;
            $touched[$qid . ':' . $cid . ':' . $eid . ':' . $judge] = [$qid, $cid, $eid, $judge];
        }
        foreach ($touched as $tuple) {
            twg_rubric_rollup_judge($conn, $eventId, $tuple[0], $tuple[1], $tuple[2], $tuple[3]);
            if (function_exists('twg_recompute_average')) {
                twg_recompute_average($conn, $tuple[0], $tuple[1]);
            }
        }
        return [
            'ok' => true,
            'message' => $saved === 1 ? 'Saved 1 score.' : ('Saved ' . $saved . ' scores.'),
            'saved' => $saved,
        ];
    }
}

if (!function_exists('twg_rubric_upsert_scaled_score')) {
    /**
     * Write the 0–100 judge total into the member tables used by Results.
     * Import and By-award saves are the source of truth, so this updates
     * even when a leftover cell was already locked.
     */
    function twg_rubric_upsert_scaled_score(
        mysqli $conn,
        int $questionId,
        int $choiceId,
        int $entryId,
        string $judgeKey,
        float $scaled
    ): void {
        if (function_exists('twg_member_scores_ensure_schema')) {
            twg_member_scores_ensure_schema($conn);
        }
        if ($scaled < 0) {
            $scaled = 0.0;
        }
        if ($scaled > (function_exists('twg_score_max') ? twg_score_max() : 100.0)) {
            $scaled = function_exists('twg_score_max') ? twg_score_max() : 100.0;
        }
        if ($entryId > 0) {
            $ins = $conn->prepare(
                'INSERT INTO tbl_twg_entry_member_scores (question_id, choice_id, ballot_entry_id, member_key, score)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()'
            );
            if ($ins) {
                $ins->bind_param('iiisd', $questionId, $choiceId, $entryId, $judgeKey, $scaled);
                $ins->execute();
                $ins->close();
            }
            return;
        }
        $ins = $conn->prepare(
            'INSERT INTO tbl_twg_member_scores (question_id, choice_id, member_key, score)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()'
        );
        if ($ins) {
            $ins->bind_param('iisd', $questionId, $choiceId, $judgeKey, $scaled);
            $ins->execute();
            $ins->close();
        }
    }
}

if (!function_exists('twg_rubric_event_id_for_question')) {
    function twg_rubric_event_id_for_question(mysqli $conn, int $questionId): int
    {
        if ($questionId <= 0) {
            return 0;
        }
        $st = $conn->prepare(
            'SELECT cat.event_id
             FROM tbl_questions q
             INNER JOIN tbl_categories cat ON cat.category_id = q.category_id
             WHERE q.question_id = ?
             LIMIT 1'
        );
        if (!$st) {
            return 0;
        }
        $st->bind_param('i', $questionId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return (int) ($row['event_id'] ?? 0);
    }
}

if (!function_exists('twg_rubric_choice_average_100')) {
    /**
     * Mean of judges who completed every rubric item for this business.
     * Blank judge tabs are omitted; leftover 1–10 scores are not mixed in.
     */
    function twg_rubric_choice_average_100(
        mysqli $conn,
        int $eventId,
        int $questionId,
        int $choiceId,
        int $entryId = 0
    ): ?float {
        $items = twg_rubric_for_event($conn, $eventId);
        if ($items === [] || $questionId <= 0 || $choiceId <= 0) {
            return null;
        }
        $map = twg_rubric_fetch_score_map($conn, [$questionId]);
        $members = function_exists('twg_member_definitions')
            ? twg_member_definitions($conn, $eventId)
            : [];
        $totals = [];
        foreach ($members as $judge) {
            $jkey = (string) ($judge['key'] ?? '');
            if ($jkey === '') {
                continue;
            }
            $scores = $map[$questionId][$choiceId][$entryId][$jkey] ?? [];
            $total = twg_rubric_judge_total($items, $scores);
            if ($total !== null) {
                $totals[] = $total;
            }
        }
        if ($totals === []) {
            return null;
        }
        return function_exists('results_formula_round')
            ? results_formula_round(array_sum($totals) / count($totals), 2)
            : round(array_sum($totals) / count($totals), 2);
    }
}

if (!function_exists('twg_rubric_rollup_judge')) {
    function twg_rubric_rollup_judge(mysqli $conn, int $eventId, int $questionId, int $choiceId, int $entryId, string $judgeKey): void
    {
        $items = twg_rubric_for_event($conn, $eventId);
        $map = twg_rubric_fetch_score_map($conn, [$questionId]);
        $scores = $map[$questionId][$choiceId][$entryId][$judgeKey] ?? [];
        $total = twg_rubric_judge_total($items, $scores);
        if ($total === null) {
            return;
        }
        $scaled = function_exists('results_formula_round')
            ? results_formula_round($total, 2)
            : round($total, 2);
        twg_rubric_upsert_scaled_score($conn, $questionId, $choiceId, $entryId, $judgeKey, $scaled);
    }
}

if (!function_exists('twg_rubric_award_sheet')) {
    /**
     * Excel-style sheet: one award, all businesses, per-judge rubric + overall rank.
     *
     * @return array<string,mixed>
     */
    function twg_rubric_award_sheet(mysqli $conn, int $eventId, int $questionId): array
    {
        $members = twg_member_definitions($conn, $eventId);
        $rubric = twg_rubric_for_event($conn, $eventId);
        $empty = [
            'members' => $members,
            'rubric' => $rubric,
            'award' => null,
            'rows' => [],
        ];
        if ($eventId <= 0 || $questionId <= 0) {
            return $empty;
        }
        $catSql = function_exists('admin_active_category_sql') ? admin_active_category_sql($conn, 'cat') : '1=1';
        $qSql = function_exists('admin_active_question_sql') ? admin_active_question_sql($conn, 'q') : '1=1';
        $st = $conn->prepare(
            "SELECT q.question_id, q.question_name, cat.category_id, cat.category_name
             FROM tbl_questions q
             INNER JOIN tbl_categories cat ON cat.category_id = q.category_id
             WHERE q.question_id = ? AND cat.event_id = ? AND {$catSql} AND {$qSql}
             LIMIT 1"
        );
        if (!$st) {
            return $empty;
        }
        $st->bind_param('ii', $questionId, $eventId);
        $st->execute();
        $award = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
        if (!$award) {
            return $empty;
        }

        $sheet = function_exists('twg_fetch_sheet')
            ? twg_fetch_sheet($conn, $eventId, $questionId)
            : ['nominees' => []];
        $nominees = $sheet['nominees'] ?? [];
        $rubricMap = twg_rubric_fetch_score_map($conn, [$questionId]);
        $helpers = __DIR__ . '/award_entry_helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
        $entryRows = function_exists('award_entry_score_rows_for_questions')
            ? (award_entry_score_rows_for_questions($conn, [$questionId])[$questionId] ?? [])
            : [];

        $rows = [];
        foreach ($nominees as $nom) {
            $cid = (int) ($nom['choice_id'] ?? 0);
            $entries = is_array($entryRows[$cid] ?? null) ? $entryRows[$cid] : [];
            $lineTargets = $entries !== []
                ? $entries
                : [['ballot_entry_id' => 0, 'entry_name' => '', 'entry_kind' => '']];
            foreach ($lineTargets as $entry) {
                $eid = (int) ($entry['ballot_entry_id'] ?? 0);
                $judgeBlocks = [];
                $totals = [];
                foreach ($members as $judge) {
                    $jkey = (string) ($judge['key'] ?? '');
                    $itemScores = $rubricMap[$questionId][$cid][$eid][$jkey] ?? [];
                    $total = twg_rubric_judge_total($rubric, $itemScores);
                    $judgeBlocks[$jkey] = [
                        'items' => $itemScores,
                        'total' => $total,
                    ];
                    if ($total !== null) {
                        $totals[] = $total;
                    }
                }
                $avg = $totals !== []
                    ? (function_exists('results_formula_round')
                        ? results_formula_round(array_sum($totals) / count($totals), 2)
                        : round(array_sum($totals) / count($totals), 2))
                    : null;
                $rows[] = [
                    'choice_id' => $cid,
                    'choice_name' => (string) ($nom['choice_name'] ?? ''),
                    'ballot_entry_id' => $eid,
                    'entry_name' => (string) ($entry['entry_name'] ?? ''),
                    'entry_kind' => (string) ($entry['entry_kind'] ?? ''),
                    'judges' => $judgeBlocks,
                    'average' => $avg,
                    'on_ballot' => !empty($nom['on_ballot']),
                ];
            }
        }

        $ranked = $rows;
        usort($ranked, static function ($a, $b) {
            $aHas = $a['average'] !== null;
            $bHas = $b['average'] !== null;
            if ($aHas !== $bHas) {
                return $aHas ? -1 : 1;
            }
            if ($aHas) {
                $cmp = ((float) $b['average']) <=> ((float) $a['average']);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcasecmp((string) $a['choice_name'], (string) $b['choice_name']);
        });
        $rank = 0;
        $previous = null;
        $rankBy = [];
        foreach ($ranked as $item) {
            $key = $item['choice_id'] . ':' . $item['ballot_entry_id'];
            if ($item['average'] === null) {
                $rankBy[$key] = null;
                continue;
            }
            $avg = (float) $item['average'];
            if ($previous === null || abs($avg - $previous) > 0.0001) {
                $rank++;
            }
            $rankBy[$key] = $rank;
            $previous = $avg;
        }
        foreach ($rows as &$row) {
            $row['rank'] = $rankBy[$row['choice_id'] . ':' . $row['ballot_entry_id']] ?? null;
            $row['in_top5'] = $row['rank'] !== null && (int) $row['rank'] <= 5;
        }
        unset($row);

        return [
            'members' => $members,
            'rubric' => $rubric,
            'award' => [
                'question_id' => (int) $award['question_id'],
                'question_name' => (string) $award['question_name'],
                'category_id' => (int) $award['category_id'],
                'category_name' => (string) $award['category_name'],
            ],
            'rows' => $rows,
        ];
    }
}
