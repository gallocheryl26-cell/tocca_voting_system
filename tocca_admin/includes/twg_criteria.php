<?php
declare(strict_types=1);

/**
 * Event-scoped TWG scoring criteria (names + relative weights).
 * Scores are 0–100. Keys are stored in tbl_twg_member_scores.member_key.
 */

if (!function_exists('twg_criteria_defaults')) {
    /** @return list<array{key:string,label:string,short:string,weight:float}> */
    function twg_criteria_defaults(): array
    {
        return [
            ['key' => 'lgu_1', 'label' => 'LGU Head 1', 'short' => 'LGU 1', 'weight' => 1.0],
            ['key' => 'lgu_2', 'label' => 'LGU Head 2', 'short' => 'LGU 2', 'weight' => 1.0],
            ['key' => 'bplo', 'label' => 'BPLO', 'short' => 'BPLO', 'weight' => 1.0],
            ['key' => 'ledipo', 'label' => 'LEDIPO', 'short' => 'LEDIPO', 'weight' => 1.0],
            ['key' => 'orcham', 'label' => 'ORCHAM', 'short' => 'ORCHAM', 'weight' => 1.0],
            ['key' => 'judge_6', 'label' => 'Judge 6', 'short' => 'Judge 6', 'weight' => 1.0],
            ['key' => 'judge_7', 'label' => 'Judge 7', 'short' => 'Judge 7', 'weight' => 1.0],
        ];
    }
}

if (!function_exists('twg_criteria_ensure_schema')) {
    function twg_criteria_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $conn->query(
            "CREATE TABLE IF NOT EXISTS tbl_twg_criteria (
                criterion_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id INT NOT NULL,
                criterion_key VARCHAR(32) NOT NULL,
                label VARCHAR(80) NOT NULL,
                short_label VARCHAR(32) NOT NULL,
                weight DECIMAL(8,3) NOT NULL DEFAULT 1.000,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (criterion_id),
                UNIQUE KEY uq_twg_criteria_event_key (event_id, criterion_key),
                KEY idx_twg_criteria_event (event_id, is_active, display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    }
}

if (!function_exists('twg_criteria_cache')) {
    /**
     * @param list<array<string,mixed>>|null $rows
     * @return array<int, list<array<string,mixed>>>|list<array<string,mixed>>|null
     */
    function twg_criteria_cache(?int $eventId = null, ?array $rows = null, bool $clear = false)
    {
        static $cache = [];
        if ($clear) {
            if ($eventId === null || $eventId <= 0) {
                $cache = [];
            } else {
                unset($cache[$eventId]);
            }
            return $cache;
        }
        if ($eventId !== null && $eventId > 0 && $rows !== null) {
            $cache[$eventId] = $rows;
        }
        if ($eventId !== null && $eventId > 0 && $rows === null && !$clear) {
            return $cache[$eventId] ?? null;
        }
        return $cache;
    }
}

if (!function_exists('twg_criteria_resolve_event_id')) {
    function twg_criteria_resolve_event_id(mysqli $conn, ?int $eventId = null): int
    {
        if ($eventId !== null && $eventId > 0) {
            return $eventId;
        }
        if (function_exists('admin_get_active_event_id')) {
            return (int) (admin_get_active_event_id($conn) ?? 0);
        }
        if (function_exists('admin_active_event_id')) {
            return (int) (admin_active_event_id($conn) ?? 0);
        }
        return 0;
    }
}

if (!function_exists('twg_criteria_slug')) {
    function twg_criteria_slug(string $label, array $used): string
    {
        $base = strtolower(trim($label));
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? $base;
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'criterion';
        }
        $base = substr($base, 0, 24);
        $slug = $base;
        $n = 2;
        while (in_array($slug, $used, true)) {
            $slug = substr($base . '_' . $n, 0, 32);
            $n++;
        }
        return $slug;
    }
}

if (!function_exists('twg_criteria_target_count')) {
    function twg_criteria_target_count(): int
    {
        return 7;
    }
}

if (!function_exists('twg_criteria_seed_defaults')) {
    function twg_criteria_seed_defaults(mysqli $conn, int $eventId): void
    {
        if ($eventId <= 0) {
            return;
        }
        twg_criteria_ensure_schema($conn);
        $st = $conn->prepare('SELECT COUNT(*) AS n FROM tbl_twg_criteria WHERE event_id = ?');
        if (!$st) {
            return;
        }
        $st->bind_param('i', $eventId);
        $st->execute();
        $all = (int) ($st->get_result()->fetch_assoc()['n'] ?? 0);
        $st->close();
        $st = $conn->prepare('SELECT COUNT(*) AS n FROM tbl_twg_criteria WHERE event_id = ? AND is_active = 1');
        if (!$st) {
            return;
        }
        $st->bind_param('i', $eventId);
        $st->execute();
        $n = (int) ($st->get_result()->fetch_assoc()['n'] ?? 0);
        $st->close();
        $ins = $conn->prepare(
            'INSERT INTO tbl_twg_criteria (event_id, criterion_key, label, short_label, weight, display_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        if (!$ins) {
            return;
        }
        if ($all === 0) {
            foreach (twg_criteria_defaults() as $i => $row) {
                $key = $row['key'];
                $label = $row['label'];
                $short = $row['short'];
                $weight = (float) $row['weight'];
                $order = $i + 1;
                $ins->bind_param('isssdi', $eventId, $key, $label, $short, $weight, $order);
                $ins->execute();
            }
            $ins->close();
            return;
        }
        $want = twg_criteria_target_count();
        if ($n >= $want) {
            $ins->close();
            return;
        }
        $used = [];
        $maxOrder = 0;
        $weight = 1.0;
        $list = $conn->prepare(
            'SELECT criterion_key, weight, display_order FROM tbl_twg_criteria WHERE event_id = ? ORDER BY display_order ASC, criterion_id ASC'
        );
        if ($list) {
            $list->bind_param('i', $eventId);
            $list->execute();
            $res = $list->get_result();
            while ($row = $res->fetch_assoc()) {
                $used[] = strtolower(trim((string) ($row['criterion_key'] ?? '')));
                $maxOrder = max($maxOrder, (int) ($row['display_order'] ?? 0));
                $w = (float) ($row['weight'] ?? 1);
                if ($w > 0) {
                    $weight = $w;
                }
            }
            $list->close();
        }
        $slot = 6;
        while ($n < $want) {
            $key = 'judge_' . $slot;
            while (in_array($key, $used, true)) {
                $slot++;
                $key = 'judge_' . $slot;
            }
            $label = 'Judge ' . $slot;
            $short = 'Judge ' . $slot;
            $maxOrder = $slot;
            $ins->bind_param('isssdi', $eventId, $key, $label, $short, $weight, $maxOrder);
            if ($ins->execute()) {
                $used[] = $key;
                $n++;
            } else {
                break;
            }
            $slot++;
        }
        $ins->close();
    }
}

if (!function_exists('twg_criteria_normalize_row')) {
    /**
     * @param array<string,mixed> $row
     * @return array{criterion_id:int,key:string,label:string,short:string,weight:float,display_order:int}
     */
    function twg_criteria_normalize_row(array $row): array
    {
        $label = trim((string) ($row['label'] ?? ''));
        $short = trim((string) ($row['short_label'] ?? $row['short'] ?? ''));
        if ($short === '') {
            $short = $label;
        }
        $weight = (float) ($row['weight'] ?? 1);
        if ($weight <= 0) {
            $weight = 1.0;
        }
        return [
            'criterion_id' => (int) ($row['criterion_id'] ?? 0),
            'key' => strtolower(trim((string) ($row['criterion_key'] ?? $row['key'] ?? ''))),
            'label' => $label,
            'short' => $short,
            'weight' => round($weight, 3),
            'display_order' => (int) ($row['display_order'] ?? 0),
        ];
    }
}

if (!function_exists('twg_criteria_for_event')) {
    /**
     * @return list<array{criterion_id:int,key:string,label:string,short:string,weight:float,display_order:int}>
     */
    function twg_criteria_for_event(mysqli $conn, ?int $eventId = null): array
    {
        $eventId = twg_criteria_resolve_event_id($conn, $eventId);
        if ($eventId <= 0) {
            return array_map(static function (array $row): array {
                return twg_criteria_normalize_row($row);
            }, twg_criteria_defaults());
        }

        $cached = twg_criteria_cache($eventId);
        if (is_array($cached) && $cached !== [] && array_is_list($cached)) {
            return $cached;
        }

        twg_criteria_ensure_schema($conn);
        twg_criteria_seed_defaults($conn, $eventId);

        $out = [];
        $st = $conn->prepare(
            'SELECT criterion_id, criterion_key, label, short_label, weight, display_order
             FROM tbl_twg_criteria
             WHERE event_id = ? AND is_active = 1
             ORDER BY display_order ASC, criterion_id ASC'
        );
        if ($st) {
            $st->bind_param('i', $eventId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $norm = twg_criteria_normalize_row($row);
                if ($norm['key'] !== '' && $norm['label'] !== '') {
                    $out[] = $norm;
                }
            }
            $st->close();
        }
        if ($out === []) {
            $out = array_map(static function (array $row): array {
                return twg_criteria_normalize_row($row);
            }, twg_criteria_defaults());
        }

        twg_criteria_cache($eventId, $out);
        return $out;
    }
}

if (!function_exists('twg_criteria_clip')) {
    function twg_criteria_clip(string $s, int $max): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($s, 0, $max);
        }
        return substr($s, 0, $max);
    }
}

if (!function_exists('twg_criteria_key_has_scores')) {
    function twg_criteria_key_has_scores(mysqli $conn, string $key): bool
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return false;
        }
        foreach (['tbl_twg_member_scores', 'tbl_twg_entry_member_scores'] as $table) {
            $st = $conn->prepare("SELECT 1 FROM {$table} WHERE member_key = ? LIMIT 1");
            if (!$st) {
                continue;
            }
            $st->bind_param('s', $key);
            $st->execute();
            $hit = (bool) $st->get_result()->fetch_row();
            $st->close();
            if ($hit) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('twg_criteria_save_for_event')) {
    /**
     * Replace the active criteria list for an event.
     *
     * @param list<array<string,mixed>> $items
     * @return array{ok:bool,message:string,criteria:list<array<string,mixed>>}
     */
    function twg_criteria_save_for_event(mysqli $conn, int $eventId, array $items): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            return ['ok' => false, 'message' => 'No active event.', 'criteria' => []];
        }
        twg_criteria_ensure_schema($conn);
        twg_criteria_seed_defaults($conn, $eventId);

        $clean = [];
        $usedKeys = [];
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
            $short = twg_criteria_clip($short, 32);
            $label = twg_criteria_clip($label, 80);
            $weight = (float) ($item['weight'] ?? 1);
            if ($weight <= 0) {
                $weight = 1.0;
            }
            $id = (int) ($item['criterion_id'] ?? $item['id'] ?? 0);
            $key = strtolower(trim((string) ($item['key'] ?? $item['criterion_key'] ?? '')));
            if ($key === '' || !preg_match('/^[a-z0-9_]{1,32}$/', $key)) {
                $key = twg_criteria_slug($label, $usedKeys);
            }
            if (in_array($key, $usedKeys, true)) {
                $key = twg_criteria_slug($label, $usedKeys);
            }
            $usedKeys[] = $key;
            $clean[] = [
                'criterion_id' => $id,
                'key' => $key,
                'label' => $label,
                'short' => $short,
                'weight' => round($weight, 3),
                'display_order' => $i + 1,
            ];
        }

        if ($clean === []) {
            return ['ok' => false, 'message' => 'Add at least one criterion.', 'criteria' => []];
        }
        if (count($clean) > 12) {
            return ['ok' => false, 'message' => 'Use at most 12 criteria so the score sheet stays usable.', 'criteria' => []];
        }

        $existingById = [];
        $existingByKey = [];
        $st = $conn->prepare(
            'SELECT criterion_id, criterion_key FROM tbl_twg_criteria WHERE event_id = ?'
        );
        if ($st) {
            $st->bind_param('i', $eventId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $id = (int) $row['criterion_id'];
                $key = strtolower((string) $row['criterion_key']);
                $existingById[$id] = $key;
                $existingByKey[$key] = $id;
            }
            $st->close();
        }

        $keepIds = [];
        $conn->begin_transaction();
        try {
            $upd = $conn->prepare(
                'UPDATE tbl_twg_criteria
                 SET label = ?, short_label = ?, weight = ?, display_order = ?, is_active = 1, criterion_key = ?
                 WHERE criterion_id = ? AND event_id = ?'
            );
            $ins = $conn->prepare(
                'INSERT INTO tbl_twg_criteria (event_id, criterion_key, label, short_label, weight, display_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)'
            );
            if (!$upd || !$ins) {
                throw new RuntimeException('Could not prepare criteria save.');
            }
            foreach ($clean as $row) {
                $id = (int) $row['criterion_id'];
                if ($id <= 0 && isset($existingByKey[$row['key']])) {
                    $id = (int) $existingByKey[$row['key']];
                }
                if ($id > 0 && isset($existingById[$id])) {
                    $oldKey = $existingById[$id];
                    $newKey = $row['key'];
                    if ($oldKey !== $newKey && twg_criteria_key_has_scores($conn, $oldKey)) {
                        $newKey = $oldKey;
                    }
                    $upd->bind_param(
                        'ssdisii',
                        $row['label'],
                        $row['short'],
                        $row['weight'],
                        $row['display_order'],
                        $newKey,
                        $id,
                        $eventId
                    );
                    $upd->execute();
                    $keepIds[] = $id;
                } else {
                    $ins->bind_param(
                        'isssdi',
                        $eventId,
                        $row['key'],
                        $row['label'],
                        $row['short'],
                        $row['weight'],
                        $row['display_order']
                    );
                    $ins->execute();
                    $keepIds[] = (int) $conn->insert_id;
                }
            }
            $upd->close();
            $ins->close();

            if ($keepIds !== []) {
                $ph = implode(',', array_fill(0, count($keepIds), '?'));
                $types = 'i' . str_repeat('i', count($keepIds));
                $sql = "UPDATE tbl_twg_criteria SET is_active = 0
                        WHERE event_id = ? AND criterion_id NOT IN ({$ph})";
                $hide = $conn->prepare($sql);
                if ($hide) {
                    $hide->bind_param($types, $eventId, ...$keepIds);
                    $hide->execute();
                    $hide->close();
                }
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Could not save criteria.', 'criteria' => []];
        }

        twg_criteria_cache($eventId, null, true);
        $saved = twg_criteria_for_event($conn, $eventId);
        return ['ok' => true, 'message' => 'Criteria saved.', 'criteria' => $saved];
    }
}

if (!function_exists('twg_criteria_restore_defaults')) {
    /** @return array{ok:bool,message:string,criteria:list<array<string,mixed>>} */
    function twg_criteria_restore_defaults(mysqli $conn, int $eventId): array
    {
        $items = [];
        foreach (twg_criteria_defaults() as $i => $row) {
            $items[] = $row + ['criterion_id' => 0, 'display_order' => $i + 1];
        }
        $result = twg_criteria_save_for_event($conn, $eventId, $items);
        if ($result['ok']) {
            $result['message'] = 'Restored the default TWG members (equal weight).';
        }
        return $result;
    }
}

if (!function_exists('twg_criteria_weighted_mean')) {
    /**
     * @param array<string, float|null> $scoresByKey
     * @param list<array{key?:string,weight?:float|int}> $members
     */
    function twg_criteria_weighted_mean(array $scoresByKey, array $members): ?float
    {
        $vsum = 0.0;
        $wsum = 0.0;
        foreach ($members as $member) {
            $key = (string) ($member['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $weight = (float) ($member['weight'] ?? 1);
            if ($weight <= 0) {
                continue;
            }
            $val = $scoresByKey[$key] ?? null;
            if ($val === null) {
                continue;
            }
            $vsum += (float) $val * $weight;
            $wsum += $weight;
        }
        if ($wsum <= 0) {
            return null;
        }
        return function_exists('results_formula_round')
            ? results_formula_round($vsum / $wsum, 2)
            : round($vsum / $wsum, 2);
    }
}
