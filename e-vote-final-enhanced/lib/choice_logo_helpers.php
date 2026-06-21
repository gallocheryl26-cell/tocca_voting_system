<?php
declare(strict_types=1);

/**
 * Resolve business/establishment logos for voter choice dropdowns.
 */
if (!function_exists('voter_choice_logo_public_url')) {
    function voter_choice_logo_public_url(?string $storedPath): string
    {
        if ($storedPath === null || trim($storedPath) === '') {
            return '';
        }

        $path = trim(str_replace('\\', '/', $storedPath));

        if (preg_match('#^https?://#i', $path) || str_starts_with($path, '//')) {
            return $path;
        }

        if (str_starts_with($path, '../')) {
            return $path;
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'e-vote-final-enhanced/')) {
            return substr($path, strlen('e-vote-final-enhanced/'));
        }
        if (str_starts_with($path, 'nomination/')) {
            return '../' . $path;
        }
        if (str_starts_with($path, 'tocca_admin/')) {
            return '../' . $path;
        }

        return '../tocca_admin/' . $path;
    }
}

if (!function_exists('choice_logo_column_on_choices_table')) {
    function choice_logo_column_on_choices_table(mysqli $conn): ?string
    {
        static $col = null;
        static $done = false;
        if ($done) {
            return $col;
        }
        $done = true;
        foreach (['logo_path', 'logo', 'logo_url', 'image_path'] as $candidate) {
            $stmt = $conn->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = \'tbl_choices\'
                   AND column_name = ?
                 LIMIT 1'
            );
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('s', $candidate);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();
                return $col = $candidate;
            }
            $stmt->close();
        }

        return $col = null;
    }
}

if (!function_exists('choice_logo_from_nomination_batch')) {
    /**
     * @param list<int> $choiceIds
     * @return array<int, string> choice_id => raw path
     */
    function choice_logo_from_nomination_batch(mysqli $conn, array $choiceIds): array
    {
        $choiceIds = array_values(array_unique(array_filter(array_map('intval', $choiceIds), static fn ($id) => $id > 0)));
        if ($choiceIds === []) {
            return [];
        }

        $out = [];
        $ph = implode(',', array_fill(0, count($choiceIds), '?'));
        $types = str_repeat('i', count($choiceIds));

        $sql = "
            SELECT n.merged_choice_id AS choice_id, a.answer
            FROM tbl_nominations n
            INNER JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id
            INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
            WHERE n.merged_choice_id IN ($ph)
              AND (
                LOWER(f.name) IN ('logo', 'logo_path', 'business_logo', 'company_logo')
                OR LOWER(f.label) LIKE '%logo%'
              )
              AND TRIM(a.answer) <> ''
            ORDER BY n.merged_choice_id ASC, n.updated_at DESC, a.created_at DESC, a.id DESC
        ";

        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param($types, ...$choiceIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $cid = (int) ($row['choice_id'] ?? 0);
                if ($cid > 0 && !isset($out[$cid])) {
                    $out[$cid] = (string) ($row['answer'] ?? '');
                }
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('choice_logo_from_nomination_batch: ' . $e->getMessage());
        }

        return $out;
    }
}

if (!function_exists('choice_logo_urls_for_ids')) {
    /**
     * @param list<int> $choiceIds
     * @return array<int, string> choice_id => public URL for voter pages
     */
    function choice_logo_urls_for_ids(mysqli $conn, array $choiceIds): array
    {
        $choiceIds = array_values(array_unique(array_filter(array_map('intval', $choiceIds), static fn ($id) => $id > 0)));
        if ($choiceIds === []) {
            return [];
        }

        $raw = [];
        $logoCol = choice_logo_column_on_choices_table($conn);
        if ($logoCol !== null) {
            $ph = implode(',', array_fill(0, count($choiceIds), '?'));
            $types = str_repeat('i', count($choiceIds));
            $sql = "SELECT choice_id, `$logoCol` AS logo_raw FROM tbl_choices WHERE choice_id IN ($ph)";
            try {
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param($types, ...$choiceIds);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $cid = (int) ($row['choice_id'] ?? 0);
                        $val = trim((string) ($row['logo_raw'] ?? ''));
                        if ($cid > 0 && $val !== '') {
                            $raw[$cid] = $val;
                        }
                    }
                    $stmt->close();
                }
            } catch (Throwable $e) {
                error_log('choice_logo_urls_for_ids choices table: ' . $e->getMessage());
            }
        }

        $missing = array_values(array_diff($choiceIds, array_keys($raw)));
        if ($missing !== []) {
            foreach (choice_logo_from_nomination_batch($conn, $missing) as $cid => $path) {
                if (!isset($raw[$cid])) {
                    $raw[$cid] = $path;
                }
            }
        }

        $urls = [];
        foreach ($raw as $cid => $path) {
            $url = voter_choice_logo_public_url($path);
            if ($url !== '') {
                $urls[(int) $cid] = $url;
            }
        }

        return $urls;
    }
}
