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
            'product' => 'product name(s)',
            default => 'name(s)',
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
    /** Max named entries allowed per award title (Feelings products = 3). */
    function award_entry_max_count(string $kind): int
    {
        return $kind === 'product' ? 3 : 1;
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
        return $entry . ' — ' . $business;
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
        $count = 0;
        foreach ($entries as $row) {
            $qid = (int) $row['question_id'];
            if ($allowed !== [] && !isset($allowed[$qid])) {
                continue;
            }
            $kind = (string) $row['entry_kind'];
            $name = (string) $row['entry_name'];
            $ins->bind_param('iissi', $qid, $choiceId, $kind, $name, $nominationId);
            $ins->execute();
            $count += max(0, (int) $ins->affected_rows);
        }
        $ins->close();
        return $count;
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
        $questionIds = array_values(array_filter(array_map('intval', $questionIds)));
        if ($questionIds === []) {
            return [];
        }
        require_once __DIR__ . '/ballot_status.php';
        $ph = implode(',', array_fill(0, count($questionIds), '?'));
        $onBallot = ballot_status_sql_and($conn, 'c');
        $sql = "
            SELECT be.ballot_entry_id, be.question_id, be.choice_id, be.entry_kind, be.entry_name,
                   c.choice_name
            FROM tbl_award_ballot_entries be
            INNER JOIN tbl_choices c ON c.choice_id = be.choice_id
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
        while ($row = $res->fetch_assoc()) {
            $qid = (int) $row['question_id'];
            $business = (string) $row['choice_name'];
            $entry = (string) $row['entry_name'];
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
        return $out;
    }
}
