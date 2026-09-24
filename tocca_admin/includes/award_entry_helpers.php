<?php
declare(strict_types=1);

/**
 * Named award entries collected at registration (product / artist / stylist)
 * and promoted to the public ballot after admin approval.
 */

require_once __DIR__ . '/award_answer_fields.php';

if (!function_exists('award_entry_kind_for_question')) {
    /**
     * @return 'product'|'artist'|'stylist'|null  null = no named entry (business-only / song)
     */
    function award_entry_kind_for_question(string $awardName, ?string $answerFields = null): ?string
    {
        $name = trim($awardName);
        if ($name === '') {
            return null;
        }
        $fields = award_answer_fields_normalize($answerFields);
        if ($fields === 'song_singer') {
            return null;
        }
        if (award_answer_fields_is_place_award($name)) {
            return null; // business dropdown only
        }
        if (award_answer_fields_is_artist_award($name)) {
            return 'artist';
        }
        if (function_exists('award_answer_fields_is_stylist_award')
            ? award_answer_fields_is_stylist_award($name)
            : (str_contains(mb_strtolower($name), 'event stylist') || str_contains(mb_strtolower($name), 'stylist'))
        ) {
            return 'stylist';
        }
        if ($fields === 'product_business') {
            return 'product';
        }
        return null;
    }
}

if (!function_exists('award_entry_kind_label')) {
    function award_entry_kind_label(string $kind): string
    {
        return match ($kind) {
            'artist' => 'Make-up artist name',
            'stylist' => 'Stylist name',
            'product' => 'Product name',
            default => 'Entry name',
        };
    }
}

if (!function_exists('award_entry_kind_label_plural')) {
    function award_entry_kind_label_plural(string $kind): string
    {
        return match ($kind) {
            'artist' => 'artist name',
            'stylist' => 'stylist name',
            'product' => 'product name',
            default => 'name',
        };
    }
}

if (!function_exists('award_entry_allows_multiple')) {
    function award_entry_allows_multiple(string $kind): bool
    {
        return award_entry_max_count($kind) > 1;
    }
}

if (!function_exists('award_entry_max_count')) {
    /** Max named entries allowed per award title (Feelings: one final product). */
    function award_entry_max_count(string $kind): int
    {
        return 1;
    }
}

if (!function_exists('award_entry_display_label')) {
    function award_entry_display_label(string $businessName, string $entryName): string
    {
        $business = trim($businessName);
        $entry = trim($entryName);
        if ($business === '') {
            return $entry;
        }
        if ($entry === '') {
            return $business;
        }
        return $business . ' - ' . $entry;
    }
}

if (!function_exists('award_entry_ensure_schema')) {
    function award_entry_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query("
            CREATE TABLE IF NOT EXISTS `tbl_nomination_award_entries` (
              `entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `nomination_id` INT NOT NULL,
              `question_id` INT NOT NULL,
              `entry_kind` VARCHAR(20) NOT NULL DEFAULT 'product',
              `entry_name` VARCHAR(255) NOT NULL,
              `sort_order` INT NOT NULL DEFAULT 0,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`entry_id`),
              KEY `idx_nae_nom` (`nomination_id`),
              KEY `idx_nae_q` (`question_id`),
              KEY `idx_nae_nom_q` (`nomination_id`, `question_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS `tbl_award_ballot_entries` (
              `ballot_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `question_id` INT NOT NULL,
              `choice_id` INT NOT NULL,
              `entry_kind` VARCHAR(20) NOT NULL DEFAULT 'product',
              `entry_name` VARCHAR(255) NOT NULL,
              `nomination_id` INT NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`ballot_entry_id`),
              UNIQUE KEY `uq_abe_q_c_name` (`question_id`, `choice_id`, `entry_name`),
              KEY `idx_abe_q` (`question_id`),
              KEY `idx_abe_choice` (`choice_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('award_entry_parse_payload')) {
    /**
     * Parse JSON map: { "questionId": ["Name1", "Name2"], ... }
     *
     * @return array<int, list<string>>
     */
    function award_entry_parse_payload($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $qid => $names) {
            $qid = (int) $qid;
            if ($qid <= 0) {
                continue;
            }
            if (!is_array($names)) {
                $names = [$names];
            }
            $clean = [];
            $seen = [];
            foreach ($names as $n) {
                $n = trim(preg_replace('/\s+/u', ' ', (string) $n) ?? '');
                if ($n === '') {
                    continue;
                }
                if (function_exists('mb_strlen') ? mb_strlen($n) > 180 : strlen($n) > 180) {
                    $n = function_exists('mb_substr') ? mb_substr($n, 0, 180) : substr($n, 0, 180);
                }
                $key = function_exists('mb_strtolower') ? mb_strtolower($n) : strtolower($n);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $clean[] = $n;
            }
            if ($clean !== []) {
                $out[$qid] = $clean;
            }
        }
        return $out;
    }
}

if (!function_exists('award_entry_set_for_nomination')) {
    /**
     * @param array<int, list<string>> $byQuestion
     * @param array<int, string> $kindByQuestion question_id => kind
     */
    function award_entry_set_for_nomination(
        mysqli $conn,
        int $nominationId,
        array $byQuestion,
        array $kindByQuestion
    ): void {
        award_entry_ensure_schema($conn);
        if ($nominationId <= 0) {
            return;
        }
        $del = $conn->prepare('DELETE FROM tbl_nomination_award_entries WHERE nomination_id = ?');
        $del->bind_param('i', $nominationId);
        $del->execute();
        $del->close();

        if ($byQuestion === []) {
            return;
        }
        $ins = $conn->prepare(
            'INSERT INTO tbl_nomination_award_entries
             (nomination_id, question_id, entry_kind, entry_name, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($byQuestion as $qid => $names) {
            $qid = (int) $qid;
            $kind = (string) ($kindByQuestion[$qid] ?? 'product');
            if (!in_array($kind, ['product', 'artist', 'stylist'], true)) {
                $kind = 'product';
            }
            $names = array_slice($names, 0, award_entry_max_count($kind));
            $sort = 0;
            foreach ($names as $name) {
                $ins->bind_param('iissi', $nominationId, $qid, $kind, $name, $sort);
                $ins->execute();
                $sort++;
            }
        }
        $ins->close();
    }
}

if (!function_exists('award_entry_get_for_nomination')) {
    /**
     * @return list<array{entry_id:int,question_id:int,entry_kind:string,entry_name:string,sort_order:int}>
     */
    function award_entry_get_for_nomination(mysqli $conn, int $nominationId): array
    {
        award_entry_ensure_schema($conn);
        if ($nominationId <= 0) {
            return [];
        }
        $st = $conn->prepare(
            'SELECT entry_id, question_id, entry_kind, entry_name, sort_order
             FROM tbl_nomination_award_entries
             WHERE nomination_id = ?
             ORDER BY question_id ASC, sort_order ASC, entry_id ASC'
        );
        $st->bind_param('i', $nominationId);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'entry_id' => (int) $row['entry_id'],
                'question_id' => (int) $row['question_id'],
                'entry_kind' => (string) $row['entry_kind'],
                'entry_name' => (string) $row['entry_name'],
                'sort_order' => (int) $row['sort_order'],
            ];
        }
        $st->close();
        return $out;
    }
}

if (!function_exists('award_entry_grouped_for_nomination')) {
    /**
     * @return array<int, list<array{entry_id:int,entry_kind:string,entry_name:string}>>
     */
    function award_entry_grouped_for_nomination(mysqli $conn, int $nominationId): array
    {
        $grouped = [];
        foreach (award_entry_get_for_nomination($conn, $nominationId) as $row) {
            $qid = (int) $row['question_id'];
            $grouped[$qid][] = [
                'entry_id' => (int) $row['entry_id'],
                'entry_kind' => (string) $row['entry_kind'],
                'entry_name' => (string) $row['entry_name'],
            ];
        }
        return $grouped;
    }
}

if (!function_exists('award_entry_validate_for_awards')) {
    /**
     * @param list<int> $questionIds
     * @param array<int, list<string>> $entriesByQuestion
     * @return list<string> error messages
     */
    function award_entry_validate_for_awards(
        mysqli $conn,
        array $questionIds,
        array $entriesByQuestion
    ): array {
        $questionIds = array_values(array_filter(array_map('intval', $questionIds)));
        if ($questionIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($questionIds), '?'));
        $st = $conn->prepare(
            "SELECT question_id, question_name, answer_fields FROM tbl_questions WHERE question_id IN ($ph)"
        );
        $types = str_repeat('i', count($questionIds));
        $st->bind_param($types, ...$questionIds);
        $st->execute();
        $res = $st->get_result();
        $errors = [];
        while ($row = $res->fetch_assoc()) {
            $qid = (int) $row['question_id'];
            $name = (string) $row['question_name'];
            $kind = award_entry_kind_for_question($name, (string) ($row['answer_fields'] ?? ''));
            if ($kind === null) {
                continue;
            }
            $names = $entriesByQuestion[$qid] ?? [];
            if ($names === []) {
                $errors[] = 'Please enter at least one ' . award_entry_kind_label_plural($kind)
                    . ' for "' . $name . '".';
                continue;
            }
            $max = award_entry_max_count($kind);
            if (count($names) > $max) {
                $errors[] = $max === 1
                    ? ('Enter only one ' . award_entry_kind_label_plural($kind) . ' for "' . $name . '".')
                    : ('You can enter up to ' . $max . ' ' . award_entry_kind_label_plural($kind)
                        . ' for "' . $name . '".');
            }
        }
        $st->close();
        return $errors;
    }
}

if (!function_exists('award_entry_promote_to_ballot')) {
    function award_entry_promote_to_ballot(
        mysqli $conn,
        int $nominationId,
        int $choiceId,
        array $questionIds
    ): int {
        award_entry_ensure_schema($conn);
        if ($nominationId <= 0 || $choiceId <= 0) {
            return 0;
        }
        $entries = award_entry_get_for_nomination($conn, $nominationId);
        if ($entries === []) {
            return 0;
        }
        $allowed = array_fill_keys(array_map('intval', $questionIds), true);
        $ins = $conn->prepare(
            'INSERT INTO tbl_award_ballot_entries
             (question_id, choice_id, entry_kind, entry_name, nomination_id, is_active)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
               entry_kind = VALUES(entry_kind),
               nomination_id = VALUES(nomination_id),
               is_active = 1'
        );
        $byQ = [];
        foreach ($entries as $row) {
            $qid = (int) $row['question_id'];
            if ($allowed !== [] && !isset($allowed[$qid])) {
                continue;
            }
            $byQ[$qid][] = $row;
        }
        $count = 0;
        foreach ($byQ as $qid => $rows) {
            $kind = (string) ($rows[0]['entry_kind'] ?? 'product');
            $rows = array_slice($rows, 0, award_entry_max_count($kind));
            $keep = [];
            foreach ($rows as $row) {
                $kind = (string) $row['entry_kind'];
                $name = award_entry_clean_name((string) $row['entry_name']);
                if ($name === '') {
                    continue;
                }
                $ins->bind_param('iissi', $qid, $choiceId, $kind, $name, $nominationId);
                $ins->execute();
                $count += max(0, (int) $ins->affected_rows);
                $keep[] = $name;
            }
            award_entry_deactivate_choice_names_except($conn, $choiceId, (int) $qid, $keep);
        }
        $ins->close();
        return $count;
    }
}

if (!function_exists('award_entry_clean_name')) {
    function award_entry_clean_name(string $name): string
    {
        $n = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($n === '') {
            return '';
        }
        if (function_exists('mb_strlen') ? mb_strlen($n) > 180 : strlen($n) > 180) {
            $n = function_exists('mb_substr') ? mb_substr($n, 0, 180) : substr($n, 0, 180);
        }
        return $n;
    }
}

if (!function_exists('award_entry_kind_from_award')) {
    function award_entry_kind_from_award(string $questionName, ?string $answerFields = null, ?string $categoryName = null): ?string
    {
        $kind = award_entry_kind_for_question($questionName, $answerFields);
        if ($kind !== null) {
            return $kind;
        }
        if (function_exists('award_answer_fields_is_place_award') && award_answer_fields_is_place_award($questionName)) {
            return null;
        }
        $fields = function_exists('award_answer_fields_normalize')
            ? award_answer_fields_normalize($answerFields)
            : '';
        if ($fields === 'song_singer' || $fields === 'business_photo') {
            return null;
        }
        $cat = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string) $categoryName))
            : strtolower(trim((string) $categoryName));
        if (str_contains($cat, 'feeling')) {
            return 'product';
        }
        return null;
    }
}

if (!function_exists('award_entry_names_for_choice')) {
    /**
     * @return array<int, string> question_id => active entry name
     */
    function award_entry_names_for_choice(mysqli $conn, int $choiceId): array
    {
        award_entry_ensure_schema($conn);
        if ($choiceId <= 0) {
            return [];
        }
        $st = $conn->prepare(
            'SELECT question_id, entry_name
             FROM tbl_award_ballot_entries
             WHERE choice_id = ? AND is_active = 1
             ORDER BY question_id ASC, ballot_entry_id ASC'
        );
        if (!$st) {
            return [];
        }
        $st->bind_param('i', $choiceId);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $qid = (int) ($row['question_id'] ?? 0);
            $name = trim((string) ($row['entry_name'] ?? ''));
            if ($qid > 0 && $name !== '' && !isset($out[$qid])) {
                $out[$qid] = $name;
            }
        }
        $st->close();
        $reasons = __DIR__ . '/award_removal_reasons.php';
        if (is_file($reasons)) {
            require_once $reasons;
        }
        if (!function_exists('award_nomination_ids_for_choice')) {
            return $out;
        }
        foreach (award_nomination_ids_for_choice($conn, $choiceId) as $nominationId) {
            foreach (award_entry_get_for_nomination($conn, $nominationId) as $row) {
                $qid = (int) ($row['question_id'] ?? 0);
                $name = trim((string) ($row['entry_name'] ?? ''));
                if ($qid > 0 && $name !== '' && !isset($out[$qid])) {
                    $out[$qid] = $name;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('award_entry_replace_choice_name')) {
    function award_entry_replace_choice_name(
        mysqli $conn,
        int $choiceId,
        int $questionId,
        string $kind,
        string $name,
        ?int $nominationId = null
    ): void {
        award_entry_ensure_schema($conn);
        if ($choiceId <= 0 || $questionId <= 0) {
            return;
        }
        $name = award_entry_clean_name($name);
        if ($name === '') {
            return;
        }
        if (!in_array($kind, ['product', 'artist', 'stylist'], true)) {
            $kind = 'product';
        }
        $off = $conn->prepare(
            'UPDATE tbl_award_ballot_entries
             SET is_active = 0
             WHERE choice_id = ? AND question_id = ?'
        );
        if ($off) {
            $off->bind_param('ii', $choiceId, $questionId);
            $off->execute();
            $off->close();
        }
        $nid = $nominationId !== null && $nominationId > 0 ? $nominationId : null;
        $ins = $conn->prepare(
            'INSERT INTO tbl_award_ballot_entries
             (question_id, choice_id, entry_kind, entry_name, nomination_id, is_active)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
               entry_kind = VALUES(entry_kind),
               nomination_id = VALUES(nomination_id),
               is_active = 1'
        );
        if (!$ins) {
            return;
        }
        $ins->bind_param('iissi', $questionId, $choiceId, $kind, $name, $nid);
        $ins->execute();
        $ins->close();
    }
}

if (!function_exists('award_entry_replace_nomination_name')) {
    function award_entry_replace_nomination_name(
        mysqli $conn,
        int $nominationId,
        int $questionId,
        string $kind,
        string $name
    ): void {
        award_entry_ensure_schema($conn);
        $name = award_entry_clean_name($name);
        if ($nominationId <= 0 || $questionId <= 0 || $name === '') {
            return;
        }
        if (!in_array($kind, ['product', 'artist', 'stylist'], true)) {
            $kind = 'product';
        }
        award_entry_delete_for_question($conn, $nominationId, $questionId);
        $sort = 0;
        $ins = $conn->prepare(
            'INSERT INTO tbl_nomination_award_entries
             (nomination_id, question_id, entry_kind, entry_name, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        if ($ins) {
            $ins->bind_param('iissi', $nominationId, $questionId, $kind, $name, $sort);
            $ins->execute();
            $ins->close();
        }
        $choiceId = function_exists('award_choice_id_for_nomination')
            ? award_choice_id_for_nomination($conn, $nominationId)
            : 0;
        if ($choiceId > 0) {
            award_entry_deactivate_ballot($conn, $nominationId, $questionId, null);
            award_entry_replace_choice_name($conn, $choiceId, $questionId, $kind, $name, $nominationId);
        }
    }
}

if (!function_exists('award_entry_admin_named_plan')) {
    /**
     * @param array<int|string, mixed> $byQuestion
     * @param list<int> $linkedQuestionIds
     * @return array{ok:bool,message:string,items:list<array{qid:int,kind:string,name:string}>}
     */
    function award_entry_admin_named_plan(
        mysqli $conn,
        array $byQuestion,
        array $linkedQuestionIds
    ): array {
        $empty = ['ok' => true, 'message' => '', 'items' => []];
        $linked = [];
        foreach ($linkedQuestionIds as $qid) {
            $qid = (int) $qid;
            if ($qid > 0) {
                $linked[$qid] = true;
            }
        }
        if ($linked === []) {
            return $empty;
        }
        $ph = implode(',', array_fill(0, count($linked), '?'));
        $ids = array_keys($linked);
        $st = $conn->prepare(
            "SELECT q.question_id, q.question_name, q.answer_fields, COALESCE(c.category_name, '') AS category_name
             FROM tbl_questions q
             LEFT JOIN tbl_categories c ON c.category_id = q.category_id
             WHERE q.question_id IN ($ph)"
        );
        if (!$st) {
            return ['ok' => false, 'message' => 'Could not load award titles.', 'items' => []];
        }
        $types = str_repeat('i', count($ids));
        $st->bind_param($types, ...$ids);
        $st->execute();
        $res = $st->get_result();
        $meta = [];
        while ($row = $res->fetch_assoc()) {
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                continue;
            }
            $kind = award_entry_kind_from_award(
                (string) ($row['question_name'] ?? ''),
                isset($row['answer_fields']) ? (string) $row['answer_fields'] : null,
                (string) ($row['category_name'] ?? '')
            );
            $meta[$qid] = [
                'kind' => $kind,
                'name' => (string) ($row['question_name'] ?? 'Award'),
            ];
        }
        $st->close();

        $names = [];
        foreach ($byQuestion as $qid => $raw) {
            $qid = (int) $qid;
            if ($qid <= 0 || !isset($linked[$qid])) {
                continue;
            }
            if (is_array($raw)) {
                $raw = $raw[0] ?? '';
            }
            $names[$qid] = award_entry_clean_name((string) $raw);
        }

        $items = [];
        foreach ($meta as $qid => $info) {
            $kind = $info['kind'];
            if ($kind === null) {
                continue;
            }
            $name = $names[$qid] ?? '';
            if ($name === '') {
                return [
                    'ok' => false,
                    'message' => 'Enter a ' . award_entry_kind_label_plural($kind) . ' for "' . $info['name'] . '".',
                    'items' => [],
                ];
            }
            $items[] = ['qid' => $qid, 'kind' => $kind, 'name' => $name];
        }
        return ['ok' => true, 'message' => '', 'items' => $items];
    }
}

if (!function_exists('award_entry_admin_set_for_choice')) {
    /**
     * @param array<int|string, mixed> $byQuestion question_id => product name
     * @param list<int> $linkedQuestionIds
     * @return array{ok:bool,message:string}
     */
    function award_entry_admin_set_for_choice(
        mysqli $conn,
        int $choiceId,
        array $byQuestion,
        array $linkedQuestionIds
    ): array {
        award_entry_ensure_schema($conn);
        if ($choiceId <= 0) {
            return ['ok' => false, 'message' => 'Business is missing.'];
        }
        $plan = award_entry_admin_named_plan($conn, $byQuestion, $linkedQuestionIds);
        if (empty($plan['ok'])) {
            return ['ok' => false, 'message' => (string) ($plan['message'] ?? 'Enter the product name.')];
        }

        $reasons = __DIR__ . '/award_removal_reasons.php';
        if (is_file($reasons)) {
            require_once $reasons;
        }
        $nomIds = function_exists('award_nomination_ids_for_choice')
            ? award_nomination_ids_for_choice($conn, $choiceId)
            : [];

        foreach ($plan['items'] as $item) {
            $qid = (int) $item['qid'];
            $kind = (string) $item['kind'];
            $name = (string) $item['name'];
            award_entry_replace_choice_name($conn, $choiceId, $qid, $kind, $name, $nomIds[0] ?? null);
            foreach ($nomIds as $nominationId) {
                $chk = $conn->prepare(
                    'SELECT 1 FROM tbl_nomination_questions WHERE nomination_id = ? AND question_id = ? LIMIT 1'
                );
                if (!$chk) {
                    continue;
                }
                $chk->bind_param('ii', $nominationId, $qid);
                $chk->execute();
                $linkedNom = (bool) $chk->get_result()->fetch_assoc();
                $chk->close();
                if ($linkedNom) {
                    award_entry_replace_nomination_name($conn, $nominationId, $qid, $kind, $name);
                }
            }
        }

        return ['ok' => true, 'message' => ''];
    }
}

if (!function_exists('award_entry_ensure_ballot_for_choice')) {
    /**
     * Copy remaining registration products onto the business so TWG / Results can show them.
     */
    function award_entry_ensure_ballot_for_choice(mysqli $conn, int $choiceId): int
    {
        award_entry_ensure_schema($conn);
        if ($choiceId <= 0) {
            return 0;
        }
        $reasons = __DIR__ . '/award_removal_reasons.php';
        if (is_file($reasons)) {
            require_once $reasons;
        }
        if (!function_exists('award_nomination_ids_for_choice')) {
            return 0;
        }
        $nomIds = award_nomination_ids_for_choice($conn, $choiceId);
        if ($nomIds === []) {
            return 0;
        }
        $remaining = function_exists('twg_remaining_question_ids_for_choice')
            ? twg_remaining_question_ids_for_choice($conn, $choiceId)
            : null;
        if ($remaining === []) {
            return 0;
        }
        $count = 0;
        foreach ($nomIds as $nominationId) {
            $qids = $remaining;
            if ($qids === null) {
                $qids = [];
                foreach (award_entry_get_for_nomination($conn, $nominationId) as $row) {
                    $qid = (int) ($row['question_id'] ?? 0);
                    if ($qid > 0) {
                        $qids[] = $qid;
                    }
                }
            }
            if ($qids === []) {
                continue;
            }
            $count += award_entry_promote_to_ballot($conn, $nominationId, $choiceId, $qids);
        }
        return $count;
    }
}

if (!function_exists('award_entry_ensure_ballot_for_questions')) {
    /**
     * @param list<int> $questionIds
     */
    function award_entry_ensure_ballot_for_questions(mysqli $conn, array $questionIds): void
    {
        award_entry_ensure_schema($conn);
        $questionIds = array_values(array_unique(array_filter(array_map('intval', $questionIds))));
        if ($questionIds === []) {
            return;
        }
        $reasons = __DIR__ . '/award_removal_reasons.php';
        if (is_file($reasons)) {
            require_once $reasons;
        }
        if (!function_exists('award_has_merged_choice_id') || !award_has_merged_choice_id($conn)) {
            return;
        }
        $ph = implode(',', array_fill(0, count($questionIds), '?'));
        $sql = "INSERT INTO tbl_award_ballot_entries
                    (question_id, choice_id, entry_kind, entry_name, nomination_id, is_active)
                SELECT e.question_id, n.merged_choice_id, e.entry_kind, e.entry_name, e.nomination_id, 1
                FROM tbl_nomination_award_entries e
                INNER JOIN tbl_nominations n ON n.nomination_id = e.nomination_id
                INNER JOIN tbl_nomination_questions nq
                    ON nq.nomination_id = e.nomination_id AND nq.question_id = e.question_id
                WHERE e.question_id IN ($ph)
                  AND n.merged_choice_id IS NOT NULL
                  AND n.merged_choice_id > 0
                ON DUPLICATE KEY UPDATE
                    entry_kind = VALUES(entry_kind),
                    nomination_id = VALUES(nomination_id),
                    is_active = 1";
        $st = $conn->prepare($sql);
        if (!$st) {
            return;
        }
        $types = str_repeat('i', count($questionIds));
        $st->bind_param($types, ...$questionIds);
        $st->execute();
        $st->close();
    }
}

if (!function_exists('award_entry_delete_for_question')) {
    function award_entry_delete_for_question(mysqli $conn, int $nominationId, int $questionId): int
    {
        award_entry_ensure_schema($conn);
        if ($nominationId <= 0 || $questionId <= 0) {
            return 0;
        }
        $st = $conn->prepare(
            'DELETE FROM tbl_nomination_award_entries WHERE nomination_id = ? AND question_id = ?'
        );
        $st->bind_param('ii', $nominationId, $questionId);
        $st->execute();
        $n = (int) $st->affected_rows;
        $st->close();
        return $n;
    }
}

if (!function_exists('award_entry_delete_ids')) {
    /**
     * @param list<int> $entryIds
     * @return list<array{entry_id:int,entry_name:string,entry_kind:string}>
     */
    function award_entry_delete_ids(
        mysqli $conn,
        int $nominationId,
        int $questionId,
        array $entryIds
    ): array {
        award_entry_ensure_schema($conn);
        $ids = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn ($id) => $id > 0)));
        if ($nominationId <= 0 || $questionId <= 0 || $ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $types = 'ii' . str_repeat('i', count($ids));
        $params = array_merge([$nominationId, $questionId], $ids);
        $st = $conn->prepare(
            "SELECT entry_id, entry_name, entry_kind
             FROM tbl_nomination_award_entries
             WHERE nomination_id = ? AND question_id = ? AND entry_id IN ($ph)"
        );
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        $found = [];
        while ($row = $res->fetch_assoc()) {
            $found[] = [
                'entry_id' => (int) $row['entry_id'],
                'entry_name' => (string) $row['entry_name'],
                'entry_kind' => (string) $row['entry_kind'],
            ];
        }
        $st->close();
        if ($found === []) {
            return [];
        }
        $foundIds = array_column($found, 'entry_id');
        $phDel = implode(',', array_fill(0, count($foundIds), '?'));
        $delTypes = 'ii' . str_repeat('i', count($foundIds));
        $delParams = array_merge([$nominationId, $questionId], $foundIds);
        $del = $conn->prepare(
            "DELETE FROM tbl_nomination_award_entries
             WHERE nomination_id = ? AND question_id = ? AND entry_id IN ($phDel)"
        );
        $del->bind_param($delTypes, ...$delParams);
        $del->execute();
        $del->close();
        return $found;
    }
}

if (!function_exists('award_entry_count_for_question')) {
    function award_entry_count_for_question(mysqli $conn, int $nominationId, int $questionId): int
    {
        award_entry_ensure_schema($conn);
        if ($nominationId <= 0 || $questionId <= 0) {
            return 0;
        }
        $st = $conn->prepare(
            'SELECT COUNT(*) AS n FROM tbl_nomination_award_entries
             WHERE nomination_id = ? AND question_id = ?'
        );
        $st->bind_param('ii', $nominationId, $questionId);
        $st->execute();
        $n = (int) ($st->get_result()->fetch_assoc()['n'] ?? 0);
        $st->close();
        return $n;
    }
}

if (!function_exists('award_entry_deactivate_choice_names_except')) {
    /**
     * @param list<string> $keepNames
     */
    function award_entry_deactivate_choice_names_except(
        mysqli $conn,
        int $choiceId,
        int $questionId,
        array $keepNames
    ): void {
        if ($choiceId <= 0 || $questionId <= 0) {
            return;
        }
        $keep = [];
        foreach ($keepNames as $name) {
            $name = award_entry_clean_name((string) $name);
            if ($name !== '') {
                $keep[$name] = $name;
            }
        }
        if ($keep === []) {
            $st = $conn->prepare(
                'UPDATE tbl_award_ballot_entries
                 SET is_active = 0
                 WHERE choice_id = ? AND question_id = ?'
            );
            if ($st) {
                $st->bind_param('ii', $choiceId, $questionId);
                $st->execute();
                $st->close();
            }
            return;
        }
        $ph = implode(',', array_fill(0, count($keep), '?'));
        $sql = "UPDATE tbl_award_ballot_entries
                SET is_active = 0
                WHERE choice_id = ? AND question_id = ? AND entry_name NOT IN ($ph)";
        $st = $conn->prepare($sql);
        if (!$st) {
            return;
        }
        $types = 'ii' . str_repeat('s', count($keep));
        $params = array_merge([$choiceId, $questionId], array_values($keep));
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();
    }
}

if (!function_exists('award_entry_deactivate_ballot')) {
    /**
     * @param list<string>|null $entryNames  null = all entries for this nomination + award
     */
    function award_entry_deactivate_ballot(
        mysqli $conn,
        int $nominationId,
        int $questionId,
        ?array $entryNames = null
    ): void {
        award_entry_ensure_schema($conn);
        if ($nominationId <= 0 || $questionId <= 0) {
            return;
        }
        if ($entryNames === null) {
            $st = $conn->prepare(
                'UPDATE tbl_award_ballot_entries
                 SET is_active = 0
                 WHERE nomination_id = ? AND question_id = ?'
            );
            $st->bind_param('ii', $nominationId, $questionId);
            $st->execute();
            $st->close();
            return;
        }
        $names = [];
        foreach ($entryNames as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $names[$name] = $name;
            }
        }
        $names = array_values($names);
        if ($names === []) {
            return;
        }
        $ph = implode(',', array_fill(0, count($names), '?'));
        $types = 'ii' . str_repeat('s', count($names));
        $params = array_merge([$nominationId, $questionId], $names);
        $st = $conn->prepare(
            "UPDATE tbl_award_ballot_entries
             SET is_active = 0
             WHERE nomination_id = ? AND question_id = ? AND entry_name IN ($ph)"
        );
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();
    }
}

if (!function_exists('award_entry_audit_ensure_schema')) {
    function award_entry_audit_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $conn->query("
            CREATE TABLE IF NOT EXISTS `tbl_nomination_award_entry_audit` (
              `audit_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `nomination_id` INT NOT NULL,
              `question_id` INT NOT NULL,
              `entry_name` VARCHAR(255) NOT NULL,
              `entry_kind` VARCHAR(20) NOT NULL DEFAULT 'product',
              `reason` VARCHAR(64) NULL,
              `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`audit_id`),
              KEY `idx_naea_nom` (`nomination_id`),
              KEY `idx_naea_nom_q` (`nomination_id`, `question_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('award_entry_audit_log')) {
    /**
     * @param list<array{entry_name?:string,entry_kind?:string}> $entries
     */
    function award_entry_audit_log(
        mysqli $conn,
        int $nominationId,
        int $questionId,
        array $entries,
        ?string $reason
    ): void {
        award_entry_audit_ensure_schema($conn);
        if ($nominationId <= 0 || $questionId <= 0 || $entries === []) {
            return;
        }
        $reasonKey = function_exists('award_removal_reason_normalize')
            ? award_removal_reason_normalize($reason)
            : (is_string($reason) ? strtolower(trim($reason)) : null);
        $ins = $conn->prepare(
            'INSERT INTO tbl_nomination_award_entry_audit
             (nomination_id, question_id, entry_name, entry_kind, reason, changed_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        foreach ($entries as $row) {
            $name = trim((string) ($row['entry_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $kind = (string) ($row['entry_kind'] ?? 'product');
            if (!in_array($kind, ['product', 'artist', 'stylist'], true)) {
                $kind = 'product';
            }
            $ins->bind_param('iisss', $nominationId, $questionId, $name, $kind, $reasonKey);
            $ins->execute();
        }
        $ins->close();
    }
}

if (!function_exists('award_entry_audit_backfill_from_admin_log')) {
    /**
     * Recover product names already deleted before tbl_nomination_award_entry_audit existed.
     */
    function award_entry_audit_backfill_from_admin_log(mysqli $conn, int $nominationId): void
    {
        static $done = [];
        if ($nominationId <= 0 || isset($done[$nominationId])) {
            return;
        }
        $done[$nominationId] = true;
        award_entry_audit_ensure_schema($conn);
        $tbl = @$conn->query("SHOW TABLES LIKE 'tbl_admin_audit_log'");
        if (!$tbl || $tbl->num_rows === 0) {
            if ($tbl) {
                $tbl->free();
            }
            return;
        }
        $tbl->free();
        $st = $conn->prepare(
            "SELECT details_json FROM tbl_admin_audit_log
             WHERE module = 'registrations'
               AND entity_type = 'nomination'
               AND entity_id = ?
               AND action IN ('remove_award_entry', 'remove_award')"
        );
        if (!$st) {
            return;
        }
        $st->bind_param('i', $nominationId);
        $st->execute();
        $res = $st->get_result();
        $pending = [];
        while ($row = $res->fetch_assoc()) {
            $details = json_decode((string) ($row['details_json'] ?? ''), true);
            if (!is_array($details)) {
                continue;
            }
            $qid = (int) ($details['question_id'] ?? 0);
            $names = $details['entry_names'] ?? [];
            if ($qid <= 0 || !is_array($names) || $names === []) {
                continue;
            }
            $reason = isset($details['reason']) ? (string) $details['reason'] : null;
            $kind = 'product';
            foreach ($names as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }
                $pending[] = [
                    'question_id' => $qid,
                    'entry_name' => $name,
                    'entry_kind' => $kind,
                    'reason' => $reason,
                ];
            }
        }
        $st->close();
        if ($pending === []) {
            return;
        }
        foreach ($pending as $item) {
            $chk = $conn->prepare(
                'SELECT audit_id FROM tbl_nomination_award_entry_audit
                 WHERE nomination_id = ? AND question_id = ? AND entry_name = ?
                 LIMIT 1'
            );
            if (!$chk) {
                continue;
            }
            $chk->bind_param('iis', $nominationId, $item['question_id'], $item['entry_name']);
            $chk->execute();
            $exists = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($exists) {
                continue;
            }
            award_entry_audit_log(
                $conn,
                $nominationId,
                (int) $item['question_id'],
                [['entry_name' => $item['entry_name'], 'entry_kind' => $item['entry_kind']]],
                $item['reason']
            );
        }
    }
}

if (!function_exists('award_entry_audit_fetch_for_nomination')) {
    /**
     * @return array<int, list<array{entry_name:string,entry_kind:string,reason:string,reason_label:string}>>
     */
    function award_entry_audit_fetch_for_nomination(mysqli $conn, int $nominationId): array
    {
        award_entry_audit_ensure_schema($conn);
        if ($nominationId <= 0) {
            return [];
        }
        award_entry_audit_backfill_from_admin_log($conn, $nominationId);
        $st = $conn->prepare(
            'SELECT question_id, entry_name, entry_kind, reason
             FROM tbl_nomination_award_entry_audit
             WHERE nomination_id = ?
             ORDER BY question_id ASC, audit_id ASC'
        );
        $st->bind_param('i', $nominationId);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        $seen = [];
        while ($row = $res->fetch_assoc()) {
            $qid = (int) ($row['question_id'] ?? 0);
            $name = trim((string) ($row['entry_name'] ?? ''));
            if ($qid <= 0 || $name === '') {
                continue;
            }
            $dupKey = $qid . "\0" . mb_strtolower($name);
            if (isset($seen[$dupKey])) {
                continue;
            }
            $seen[$dupKey] = true;
            $reasonKey = (string) ($row['reason'] ?? '');
            $out[$qid][] = [
                'entry_name' => $name,
                'entry_kind' => (string) ($row['entry_kind'] ?? 'product'),
                'reason' => $reasonKey,
                'reason_label' => function_exists('award_removal_reason_label')
                    ? award_removal_reason_label($reasonKey)
                    : $reasonKey,
            ];
        }
        $st->close();
        return $out;
    }
}

if (!function_exists('award_entry_names_by_question_for_choice')) {
    /**
     * Remaining named products/artists for a business on the TWG sheet / ballot.
     *
     * @return array<int, array{entry_kind:string,entry_names:list<string>}>
     */
    function award_entry_names_by_question_for_choice(mysqli $conn, int $choiceId): array
    {
        award_entry_ensure_schema($conn);
        if ($choiceId <= 0) {
            return [];
        }
        $out = [];
        $st = $conn->prepare(
            'SELECT question_id, entry_kind, entry_name
             FROM tbl_award_ballot_entries
             WHERE choice_id = ? AND is_active = 1
             ORDER BY question_id ASC, entry_name ASC'
        );
        if ($st) {
            $st->bind_param('i', $choiceId);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $qid = (int) ($row['question_id'] ?? 0);
                $name = trim((string) ($row['entry_name'] ?? ''));
                if ($qid <= 0 || $name === '') {
                    continue;
                }
                if (!isset($out[$qid])) {
                    $out[$qid] = [
                        'entry_kind' => (string) ($row['entry_kind'] ?? 'product'),
                        'entry_names' => [],
                    ];
                }
                $out[$qid]['entry_names'][] = $name;
            }
            $st->close();
        }
        if (!function_exists('award_nomination_ids_for_choice')) {
            return $out;
        }
        foreach (award_nomination_ids_for_choice($conn, $choiceId) as $nominationId) {
            foreach (award_entry_get_for_nomination($conn, $nominationId) as $row) {
                $qid = (int) $row['question_id'];
                $name = trim((string) $row['entry_name']);
                if ($qid <= 0 || $name === '') {
                    continue;
                }
                if (!isset($out[$qid])) {
                    $out[$qid] = [
                        'entry_kind' => (string) ($row['entry_kind'] ?? 'product'),
                        'entry_names' => [],
                    ];
                }
                if (!in_array($name, $out[$qid]['entry_names'], true)) {
                    $out[$qid]['entry_names'][] = $name;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('award_entry_score_rows_for_choice')) {
    /**
     * Active named entries for TWG product scoring.
     *
     * @return array<int, list<array{ballot_entry_id:int,entry_kind:string,entry_name:string}>>
     */
    function award_entry_score_rows_for_choice(mysqli $conn, int $choiceId): array
    {
        award_entry_ensure_schema($conn);
        if ($choiceId <= 0) {
            return [];
        }
        award_entry_ensure_ballot_for_choice($conn, $choiceId);
        $out = [];
        $st = $conn->prepare(
            'SELECT ballot_entry_id, question_id, entry_kind, entry_name
             FROM tbl_award_ballot_entries
             WHERE choice_id = ? AND is_active = 1
             ORDER BY question_id ASC, entry_name ASC'
        );
        if (!$st) {
            return [];
        }
        $st->bind_param('i', $choiceId);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $qid = (int) ($row['question_id'] ?? 0);
            $eid = (int) ($row['ballot_entry_id'] ?? 0);
            $name = trim((string) ($row['entry_name'] ?? ''));
            if ($qid <= 0 || $eid <= 0 || $name === '') {
                continue;
            }
            $out[$qid][] = [
                'ballot_entry_id' => $eid,
                'entry_kind' => (string) ($row['entry_kind'] ?? 'product'),
                'entry_name' => $name,
            ];
        }
        $st->close();
        return $out;
    }
}

if (!function_exists('award_entry_score_rows_for_questions')) {
    /**
     * Active named entries for many awards, grouped by question then business.
     *
     * @param list<int> $questionIds
     * @return array<int, array<int, list<array{ballot_entry_id:int,entry_kind:string,entry_name:string}>>>
     */
    function award_entry_score_rows_for_questions(mysqli $conn, array $questionIds): array
    {
        award_entry_ensure_schema($conn);
        $questionIds = array_values(array_unique(array_filter(array_map('intval', $questionIds))));
        if ($questionIds === []) {
            return [];
        }
        award_entry_ensure_ballot_for_questions($conn, $questionIds);
        $ph = implode(',', array_fill(0, count($questionIds), '?'));
        $st = $conn->prepare(
            "SELECT ballot_entry_id, question_id, choice_id, entry_kind, entry_name
             FROM tbl_award_ballot_entries
             WHERE is_active = 1 AND question_id IN ($ph)
             ORDER BY question_id ASC, choice_id ASC, entry_name ASC"
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
            $name = trim((string) ($row['entry_name'] ?? ''));
            if ($qid <= 0 || $cid <= 0 || $eid <= 0 || $name === '') {
                continue;
            }
            $out[$qid][$cid][] = [
                'ballot_entry_id' => $eid,
                'entry_kind' => (string) ($row['entry_kind'] ?? 'product'),
                'entry_name' => $name,
            ];
        }
        $st->close();
        return $out;
    }
}

if (!function_exists('award_entry_sea_fresh_product_for_award')) {
    /**
     * @param list<string> $existingNames
     */
    function award_entry_sea_fresh_product_for_award(string $awardName, array $existingNames): string
    {
        $award = function_exists('mb_strtolower')
            ? mb_strtolower($awardName)
            : strtolower($awardName);
        if (str_contains($award, 'hangover')) {
            foreach ($existingNames as $name) {
                $n = function_exists('mb_strtolower')
                    ? mb_strtolower(trim((string) $name))
                    : strtolower(trim((string) $name));
                if ($n === 'mixed seafoods tinola' || $n === 'mixed seafood tinola') {
                    return trim((string) $name);
                }
            }
            return 'Mixed seafoods tinola';
        }
        foreach ($existingNames as $name) {
            if (preg_match('/bucket\s*shrimp/i', (string) $name)) {
                return trim((string) $name);
            }
        }
        return 'Bucket Shrimps';
    }
}

if (!function_exists('award_entry_collapse_named_choices')) {
    /**
     * One named product per business on a Feelings dropdown.
     *
     * @param list<array{ballot_entry_id:int,choice_id:int,choice_name:string,entry_name:string,entry_kind:string,display_name:string}> $entries
     * @return list<array{ballot_entry_id:int,choice_id:int,choice_name:string,entry_name:string,entry_kind:string,display_name:string}>
     */
    function award_entry_collapse_named_choices(array $entries, string $awardName): array
    {
        $byChoice = [];
        $order = [];
        foreach ($entries as $entry) {
            $cid = (int) ($entry['choice_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            if (!isset($byChoice[$cid])) {
                $order[] = $cid;
                $byChoice[$cid] = [];
            }
            $byChoice[$cid][] = $entry;
        }
        $out = [];
        foreach ($order as $cid) {
            $list = $byChoice[$cid];
            if ($list === []) {
                continue;
            }
            if (count($list) === 1) {
                $out[] = $list[0];
                continue;
            }
            $business = (string) ($list[0]['choice_name'] ?? '');
            $names = [];
            foreach ($list as $item) {
                $names[] = (string) ($item['entry_name'] ?? '');
            }
            $want = null;
            $biz = function_exists('mb_strtolower') ? mb_strtolower($business) : strtolower($business);
            if (str_contains($biz, 'sea fresh')) {
                $want = award_entry_sea_fresh_product_for_award($awardName, $names);
            }
            $picked = $list[0];
            if ($want !== null && $want !== '') {
                foreach ($list as $item) {
                    if (strcasecmp(trim((string) ($item['entry_name'] ?? '')), $want) === 0) {
                        $picked = $item;
                        break;
                    }
                }
            }
            $out[] = $picked;
        }
        return $out;
    }
}

if (!function_exists('award_entry_apply_known_ballot_overrides')) {
    /**
     * Sea Fresh Feelings: one product per title (Bucket Shrimps), except Hangover = Mixed seafoods tinola.
     */
    function award_entry_apply_known_ballot_overrides(mysqli $conn, bool $force = false): void
    {
        static $done = false;
        if ($done && !$force) {
            return;
        }
        $done = true;
        $flagKey = 'sea_fresh_feelings_products_v1';

        try {
            if (!$force) {
                $flag = $conn->prepare('SELECT config_value FROM tbl_config WHERE config_key = ? LIMIT 1');
                if ($flag) {
                    $flag->bind_param('s', $flagKey);
                    $flag->execute();
                    $flagRow = $flag->get_result()->fetch_assoc();
                    $flag->close();
                    if (($flagRow['config_value'] ?? '') === '1') {
                        return;
                    }
                }
            }

            $res = $conn->query(
                "SELECT choice_id, choice_name
                 FROM tbl_choices
                 WHERE choice_name LIKE '%Sea Fresh%'"
            );
            if (!$res) {
                return;
            }
            $choices = [];
            while ($row = $res->fetch_assoc()) {
                $cid = (int) ($row['choice_id'] ?? 0);
                if ($cid > 0) {
                    $choices[] = [
                        'choice_id' => $cid,
                        'choice_name' => (string) ($row['choice_name'] ?? ''),
                    ];
                }
            }
            $res->close();

            $reasons = __DIR__ . '/award_removal_reasons.php';
            if (is_file($reasons)) {
                require_once $reasons;
            }

            foreach ($choices as $choice) {
                $choiceId = (int) $choice['choice_id'];
                $st = $conn->prepare(
                    "SELECT q.question_id, q.question_name, q.answer_fields,
                            COALESCE(cat.category_name, '') AS category_name
                     FROM tbl_question_choices qc
                     INNER JOIN tbl_questions q ON q.question_id = qc.question_id
                     LEFT JOIN tbl_categories cat ON cat.category_id = q.category_id
                     WHERE qc.choice_id = ?"
                );
                if (!$st) {
                    continue;
                }
                $st->bind_param('i', $choiceId);
                $st->execute();
                $qres = $st->get_result();
                $awards = [];
                while ($row = $qres->fetch_assoc()) {
                    $awards[] = $row;
                }
                $st->close();

                $extra = $conn->prepare(
                    "SELECT DISTINCT q.question_id, q.question_name, q.answer_fields,
                            COALESCE(cat.category_name, '') AS category_name
                     FROM tbl_award_ballot_entries be
                     INNER JOIN tbl_questions q ON q.question_id = be.question_id
                     LEFT JOIN tbl_categories cat ON cat.category_id = q.category_id
                     WHERE be.choice_id = ?"
                );
                if ($extra) {
                    $extra->bind_param('i', $choiceId);
                    $extra->execute();
                    $eres = $extra->get_result();
                    $seen = [];
                    foreach ($awards as $row) {
                        $seen[(int) $row['question_id']] = true;
                    }
                    while ($row = $eres->fetch_assoc()) {
                        $qid = (int) ($row['question_id'] ?? 0);
                        if ($qid > 0 && !isset($seen[$qid])) {
                            $awards[] = $row;
                        }
                    }
                    $extra->close();
                }

                $nomIds = function_exists('award_nomination_ids_for_choice')
                    ? award_nomination_ids_for_choice($conn, $choiceId)
                    : [];

                foreach ($awards as $row) {
                    $qid = (int) ($row['question_id'] ?? 0);
                    $awardName = (string) ($row['question_name'] ?? '');
                    $kind = award_entry_kind_from_award(
                        $awardName,
                        isset($row['answer_fields']) ? (string) $row['answer_fields'] : null,
                        (string) ($row['category_name'] ?? '')
                    );
                    if ($qid <= 0 || $kind === null) {
                        continue;
                    }
                    $existing = [];
                    $en = $conn->prepare(
                        'SELECT entry_name FROM tbl_award_ballot_entries
                         WHERE choice_id = ? AND question_id = ? AND is_active = 1
                         ORDER BY ballot_entry_id ASC'
                    );
                    if ($en) {
                        $en->bind_param('ii', $choiceId, $qid);
                        $en->execute();
                        $nres = $en->get_result();
                        while ($nr = $nres->fetch_assoc()) {
                            $nm = trim((string) ($nr['entry_name'] ?? ''));
                            if ($nm !== '') {
                                $existing[] = $nm;
                            }
                        }
                        $en->close();
                    }
                    $want = award_entry_sea_fresh_product_for_award($awardName, $existing);
                    $want = award_entry_clean_name($want);
                    if ($want === '') {
                        continue;
                    }
                    $already = count($existing) === 1
                        && strcasecmp($existing[0], $want) === 0;
                    if ($already) {
                        continue;
                    }
                    award_entry_replace_choice_name($conn, $choiceId, $qid, $kind, $want, $nomIds[0] ?? null);
                    foreach ($nomIds as $nominationId) {
                        $chk = $conn->prepare(
                            'SELECT 1 FROM tbl_nomination_questions
                             WHERE nomination_id = ? AND question_id = ? LIMIT 1'
                        );
                        if (!$chk) {
                            continue;
                        }
                        $chk->bind_param('ii', $nominationId, $qid);
                        $chk->execute();
                        $linkedNom = (bool) $chk->get_result()->fetch_assoc();
                        $chk->close();
                        if ($linkedNom) {
                            award_entry_replace_nomination_name($conn, $nominationId, $qid, $kind, $want);
                        }
                    }
                }
            }
            $mark = $conn->prepare(
                'INSERT INTO tbl_config (config_key, config_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
            );
            if ($mark) {
                $one = '1';
                $mark->bind_param('ss', $flagKey, $one);
                $mark->execute();
                $mark->close();
            }
        } catch (Throwable $e) {
            error_log('award_entry_apply_known_ballot_overrides: ' . $e->getMessage());
        }
    }
}

if (!function_exists('award_entry_fetch_ballot_for_questions')) {
    /**
     * @param list<int> $questionIds
     * @return array<int, list<array{ballot_entry_id:int,choice_id:int,choice_name:string,entry_name:string,entry_kind:string,display_name:string}>>
     */
    function award_entry_fetch_ballot_for_questions(mysqli $conn, array $questionIds): array
    {
        award_entry_ensure_schema($conn);
        if (function_exists('award_entry_apply_known_ballot_overrides')) {
            award_entry_apply_known_ballot_overrides($conn);
        }
        $questionIds = array_values(array_filter(array_map('intval', $questionIds)));
        if ($questionIds === []) {
            return [];
        }
        require_once __DIR__ . '/ballot_status.php';
        $ph = implode(',', array_fill(0, count($questionIds), '?'));
        $onBallot = ballot_status_sql_and($conn, 'c');
        $sql = "
            SELECT be.ballot_entry_id, be.question_id, be.choice_id, be.entry_kind, be.entry_name,
                   c.choice_name, q.question_name
            FROM tbl_award_ballot_entries be
            INNER JOIN tbl_choices c ON c.choice_id = be.choice_id
            INNER JOIN tbl_questions q ON q.question_id = be.question_id
            WHERE be.question_id IN ($ph)
              AND be.is_active = 1
              AND c.status = 1
              {$onBallot}
            ORDER BY c.choice_name ASC, be.entry_name ASC
        ";
        $st = $conn->prepare($sql);
        $types = str_repeat('i', count($questionIds));
        $st->bind_param($types, ...$questionIds);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        $awardNameByQ = [];
        while ($row = $res->fetch_assoc()) {
            $qid = (int) $row['question_id'];
            $business = (string) $row['choice_name'];
            $entry = (string) $row['entry_name'];
            $awardNameByQ[$qid] = (string) ($row['question_name'] ?? '');
            $out[$qid][] = [
                'ballot_entry_id' => (int) $row['ballot_entry_id'],
                'choice_id' => (int) $row['choice_id'],
                'choice_name' => $business,
                'entry_name' => $entry,
                'entry_kind' => (string) $row['entry_kind'],
                'display_name' => award_entry_display_label($business, $entry),
            ];
        }
        $st->close();
        foreach ($out as $qid => $entries) {
            $out[$qid] = award_entry_collapse_named_choices($entries, (string) ($awardNameByQ[$qid] ?? ''));
        }
        return $out;
    }
}
