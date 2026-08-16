<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_schema.php';

if (!function_exists('nominations_has_media_table')) {
    function nominations_has_media_table(mysqli $conn): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = admin_table_exists($conn, 'tbl_nomination_media');
        return $cached;
    }
}

/** @return array{rows: list<array<string,mixed>>, total: int, page: int, page_size: int} */
if (!function_exists('nominations_fetch_list')) {
    function nominations_fetch_list(
        mysqli $conn,
        int $event_id,
        string $status = 'all',
        int $page = 1,
        int $page_size = 10,
        string $q = ''
    ): array {
        $page = max(1, $page);
        $page_size = max(1, min(100, $page_size));
        $offset = ($page - 1) * $page_size;

        if ($event_id <= 0) {
            return ['rows' => [], 'total' => 0, 'page' => $page, 'page_size' => $page_size];
        }

        $where = ['n.event_id = ?'];
        $types = 'i';
        $params = [$event_id];
        $status = trim($status);
        if ($status !== '' && $status !== 'all') {
            $where[] = 'n.status = ?';
            $types .= 's';
            $params[] = $status;
        }

        $hasBizCol = admin_schema_column_exists($conn, 'tbl_nominations', 'business_name');
        $hasEmailCol = admin_schema_column_exists($conn, 'tbl_nominations', 'email');
        $hasPhoneCol = admin_schema_column_exists($conn, 'tbl_nominations', 'mobile_number')
            || admin_schema_column_exists($conn, 'tbl_nominations', 'phone');
        $hasAddressCol = admin_schema_column_exists($conn, 'tbl_nominations', 'address');
        $phoneCol = admin_schema_column_exists($conn, 'tbl_nominations', 'mobile_number') ? 'mobile_number' : 'phone';

        $answerSub = static function (string $fieldList): string {
            return "(SELECT a2.answer
                FROM tbl_nomination_answers a2
                JOIN tbl_nomination_fields f2 ON f2.id = a2.field_id
               WHERE a2.nomination_id = n.nomination_id
                 AND f2.name IN ($fieldList)
               ORDER BY a2.id ASC
               LIMIT 1)";
        };
        $bizExpr = $hasBizCol
            ? "COALESCE(n.business_name, '')"
            : $answerSub("'business_name','official_business_name','company','company_name','business'");

        $q = trim($q);
        if ($q !== '') {
            $where[] = "$bizExpr LIKE ?";
            $types .= 's';
            $params[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM tbl_nominations n $whereSql");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();

        $mediaJoin = nominations_has_media_table($conn)
            ? 'LEFT JOIN (
                 SELECT nomination_id, COUNT(*) AS media_count
                 FROM tbl_nomination_media
                 GROUP BY nomination_id
               ) nm ON nm.nomination_id = n.nomination_id'
            : '';
        $mediaSelect = nominations_has_media_table($conn)
            ? ', COALESCE(nm.media_count, 0) AS media_count'
            : ', 0 AS media_count';

        $hasMerged = admin_schema_column_exists($conn, 'tbl_nominations', 'merged_choice_id');
        $hasChoiceBallot = admin_schema_column_exists($conn, 'tbl_choices', 'on_ballot');
        $ballotSelect = ($hasMerged && $hasChoiceBallot)
            ? ', COALESCE(ch.on_ballot, 0) AS on_ballot'
            : ', 0 AS on_ballot';
        $ballotJoin = ($hasMerged && $hasChoiceBallot)
            ? 'LEFT JOIN tbl_choices ch ON ch.choice_id = n.merged_choice_id'
            : '';

        if ($hasBizCol && $hasEmailCol && $hasPhoneCol) {
            $addressSelect = $hasAddressCol
                ? ', COALESCE(n.address, \'\') AS address'
                : ', \'\' AS address';
            $sqlRows = "
                SELECT
                  n.nomination_id,
                  n.created_at,
                  n.status,
                  COALESCE(n.business_name, '') AS business_name,
                  COALESCE(n.email, '') AS email,
                  COALESCE(n.$phoneCol, '') AS mobile_number
                  $addressSelect
                  $mediaSelect
                  $ballotSelect
                FROM tbl_nominations n
                $mediaJoin
                $ballotJoin
                $whereSql
                ORDER BY n.created_at DESC
                LIMIT ? OFFSET ?
            ";
        } else {
            $sqlRows = "
                SELECT
                  n.nomination_id,
                  n.created_at,
                  n.status,
                  $bizExpr AS business_name,
                  {$answerSub("'email','contact_email','contact_email_address'")} AS email,
                  {$answerSub("'mobile_number','contact_phone','phone','mobile','contact_number'")} AS mobile_number,
                  COALESCE(
                    NULLIF({$answerSub("'address','full_address','address_line1'")}, ''),
                    TRIM(BOTH ', ' FROM CONCAT_WS(', ',
                      NULLIF({$answerSub("'street','street_building','street_building_line'")}, ''),
                      NULLIF({$answerSub("'barangay','brgy'")}, '')
                    ))
                  ) AS address
                  $mediaSelect
                  $ballotSelect
                FROM tbl_nominations n
                $mediaJoin
                $ballotJoin
                $whereSql
                ORDER BY n.created_at DESC
                LIMIT ? OFFSET ?
            ";
        }

        $stmt = $conn->prepare($sqlRows);
        $types2 = $types . 'ii';
        $params2 = array_merge($params, [$page_size, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$row) {
            $row['on_ballot'] = nominations_row_on_ballot($row) ? 1 : 0;
        }
        unset($row);

        return [
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'page_size' => $page_size,
            'q'         => $q,
        ];
    }
}

if (!function_exists('nominations_suggest_businesses')) {
    /** @return list<string> */
    function nominations_suggest_businesses(mysqli $conn, int $event_id, string $q, int $limit = 8): array
    {
        $q = trim($q);
        if ($event_id <= 0 || $q === '') {
            return [];
        }
        $limit = max(1, min(12, $limit));
        $hasBizCol = admin_schema_column_exists($conn, 'tbl_nominations', 'business_name');
        $bizExpr = $hasBizCol
            ? "COALESCE(n.business_name, '')"
            : "(SELECT a2.answer
                FROM tbl_nomination_answers a2
                JOIN tbl_nomination_fields f2 ON f2.id = a2.field_id
               WHERE a2.nomination_id = n.nomination_id
                 AND f2.name IN ('business_name','official_business_name','company','company_name','business')
               ORDER BY a2.id ASC
               LIMIT 1)";
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $sql = "
            SELECT DISTINCT $bizExpr AS business_name
            FROM tbl_nominations n
            WHERE n.event_id = ?
              AND $bizExpr LIKE ?
              AND TRIM(COALESCE($bizExpr, '')) <> ''
            ORDER BY business_name ASC
            LIMIT $limit
        ";
        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param('is', $event_id, $like);
        $st->execute();
        $res = $st->get_result();
        $out = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $name = trim((string) ($row['business_name'] ?? ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }
        $st->close();
        return $out;
    }
}

if (!function_exists('nominations_status_catalog')) {
    /**
     * Built-in registration statuses (workflow keys). Admins can hide filter options,
     * but cannot add new keys — review actions depend on these.
     *
     * @return array<string, string> key => label
     */
    function nominations_status_catalog(): array
    {
        return [
            'pending'    => 'Pending',
            'in_review'  => 'In Review',
            'needs_info' => 'Needs Info',
            'approved'   => 'Under evaluation',
            'rejected'   => 'Rejected',
            'merged'     => 'Merged',
            'all'        => 'All',
        ];
    }
}

if (!function_exists('nominations_default_filter_status_keys')) {
    /** @return list<string> */
    function nominations_default_filter_status_keys(): array
    {
        return ['pending', 'in_review', 'needs_info', 'approved', 'rejected', 'all'];
    }
}

if (!function_exists('nominations_normalize_filter_status_keys')) {
    /**
     * @param list<mixed> $keys
     * @return list<string>
     */
    function nominations_normalize_filter_status_keys(array $keys): array
    {
        $catalog = nominations_status_catalog();
        $out = [];
        foreach ($keys as $key) {
            $key = strtolower(trim((string) $key));
            if ($key === '' || $key === 'all' || !isset($catalog[$key]) || in_array($key, $out, true)) {
                continue;
            }
            $out[] = $key;
        }
        $ordered = [];
        foreach (array_keys($catalog) as $key) {
            if ($key === 'all') {
                continue;
            }
            if (in_array($key, $out, true)) {
                $ordered[] = $key;
            }
        }
        $ordered[] = 'all';
        return $ordered;
    }
}

if (!function_exists('nominations_filter_status_keys')) {
    /** @return list<string> */
    function nominations_filter_status_keys(?mysqli $conn): array
    {
        $default = nominations_default_filter_status_keys();
        if (!$conn instanceof mysqli) {
            return $default;
        }
        $raw = '';
        if (function_exists('getConfig')) {
            $raw = (string) getConfig('nomination_list_status_filter', '');
        } else {
            $st = $conn->prepare('SELECT config_value FROM tbl_config WHERE config_key = ? LIMIT 1');
            if ($st) {
                $key = 'nomination_list_status_filter';
                $st->bind_param('s', $key);
                $st->execute();
                $st->bind_result($val);
                if ($st->fetch()) {
                    $raw = (string) $val;
                }
                $st->close();
            }
        }
        if (trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $default;
        }
        $keys = nominations_normalize_filter_status_keys($decoded);
        return count($keys) > 1 ? $keys : $default;
    }
}

if (!function_exists('nominations_save_filter_status_keys')) {
    /** @param list<mixed> $keys */
    function nominations_save_filter_status_keys(mysqli $conn, array $keys): bool
    {
        $normalized = nominations_normalize_filter_status_keys($keys);
        if (count($normalized) <= 1) {
            $normalized = nominations_default_filter_status_keys();
        }
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }
        $st = $conn->prepare(
            'INSERT INTO tbl_config (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        if (!$st) {
            return false;
        }
        $key = 'nomination_list_status_filter';
        $st->bind_param('ss', $key, $json);
        $ok = $st->execute();
        $st->close();
        if ($ok && function_exists('config_invalidate_cache')) {
            config_invalidate_cache();
        }
        return $ok;
    }
}

if (!function_exists('nominations_row_on_ballot')) {
    function nominations_row_on_ballot(array $row): bool
    {
        $v = $row['on_ballot'] ?? 0;
        return $v === true || $v === 1 || $v === '1';
    }
}

if (!function_exists('nominations_status_label')) {
    function nominations_status_label(string $key, bool $on_ballot = false): string
    {
        $key = strtolower(trim($key));
        if ($on_ballot && in_array($key, ['approved', 'merged'], true)) {
            return 'On ballot';
        }
        $map = [
            'pending'    => 'Pending',
            'in_review'  => 'In Review',
            'needs_info' => 'Needs Information',
            'approved'   => 'Under evaluation',
            'rejected'   => 'Rejected',
            'merged'     => 'Merged',
        ];
        if (isset($map[$key])) {
            return $map[$key];
        }
        return ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('nominations_status_tone')) {
    function nominations_status_tone(string $key, bool $on_ballot = false): string
    {
        $key = strtolower(trim($key));
        if ($on_ballot && in_array($key, ['approved', 'merged'], true)) {
            return 'primary';
        }
        $map = [
            'in_review'  => 'primary',
            'pending'    => 'warning',
            'needs_info' => 'secondary',
            'approved'   => 'success',
            'rejected'   => 'danger',
            'merged'     => 'info',
        ];
        return $map[$key] ?? 'secondary';
    }
}

if (!function_exists('nominations_render_table_rows')) {
    function nominations_render_table_rows(array $rows): void
    {
        if ($rows === []) {
            echo '<tr><td colspan="3" class="text-center text-muted py-4">No registrations found.</td></tr>';
            return;
        }

        foreach ($rows as $r) {
            $id = (int) ($r['nomination_id'] ?? 0);
            $name = h((string) ($r['business_name'] ?? '—'));
            $address = h((string) ($r['address'] ?? ''));
            $email = h((string) ($r['email'] ?? ''));
            $phone = h((string) ($r['mobile_number'] ?? ''));
            $contactParts = array_filter([$email, $phone], static fn($v) => $v !== '');
            $contact = $contactParts !== [] ? implode(' • ', $contactParts) : '—';
            $status = strtolower((string) ($r['status'] ?? 'pending'));
            $onBallot = nominations_row_on_ballot($r);
            $tone = nominations_status_tone($status, $onBallot);
            $label = h(nominations_status_label($status, $onBallot));
            $mediaCount = (int) ($r['media_count'] ?? 0);
            $mediaBadge = $mediaCount > 0
                ? '<span class="badge bg-info-subtle text-info border border-info-subtle ms-2" title="' . $mediaCount . ' photo/video file(s) submitted"><i class="bi bi-images"></i> ' . $mediaCount . '</span>'
                : '';
            ?>
            <tr class="nom-row" data-id="<?= $id ?>" style="cursor:pointer;">
              <td>
                <div class="fw-semibold"><?= $name ?><?= $mediaBadge ?></div>
                <?php if ($address !== ''): ?><div class="small text-muted"><?= $address ?></div><?php endif; ?>
              </td>
              <td><?= $contact ?></td>
              <td><span class="badge text-bg-<?= h($tone) ?>"><?= $label ?></span></td>
            </tr>
            <?php
        }
    }
}

if (!function_exists('nominations_pagination_label')) {
    function nominations_pagination_label(int $total, int $page, int $page_size): string
    {
        if ($total <= 0) {
            return '0–0 of 0';
        }
        $start = (($page - 1) * $page_size) + 1;
        $end = min($page * $page_size, $total);
        return $start . '–' . $end . ' of ' . $total;
    }
}

if (!function_exists('nominations_load_unarchived_events')) {
    /** @return list<array<string,mixed>> */
    function nominations_load_unarchived_events(mysqli $conn, DateTimeZone $tz, DateTime $now): array
    {
        $unarch = admin_unarchived_events_where($conn, 'e');
        $events = [];
        $sql = "SELECT e.event_id, e.event_name, e.year, e.is_active, e.voting_start, e.voting_end
                FROM tbl_events e
                WHERE $unarch
                ORDER BY e.year DESC, e.event_id DESC";
        $er = $conn->query($sql);
        if (!$er instanceof mysqli_result) {
            return [];
        }

        while ($row = $er->fetch_assoc()) {
            $startDt = !empty($row['voting_start']) ? new DateTime((string) $row['voting_start'], $tz) : null;
            $endDt = !empty($row['voting_end']) ? new DateTime((string) $row['voting_end'], $tz) : null;
            $row['voting_start_iso'] = $startDt ? $startDt->format(DATE_ATOM) : '';
            $row['voting_end_iso'] = $endDt ? $endDt->format(DATE_ATOM) : '';
            $row['voting_start_label'] = $startDt ? $startDt->format('F j, Y g:i A') : '';
            $row['voting_end_label'] = $endDt ? $endDt->format('F j, Y g:i A') : '';
            $row['voting_locked'] = nominations_is_voting_window_active(
                $row['voting_start'] ?? null,
                $row['voting_end'] ?? null,
                $now
            ) ? 1 : 0;
            $events[] = $row;
        }
        $er->free();
        return $events;
    }
}

if (!function_exists('nominations_is_voting_window_active')) {
    function nominations_is_voting_window_active(?string $start, ?string $end, DateTime $now): bool
    {
        if (!$start) {
            return false;
        }
        $startTs = strtotime($start);
        if (!$startTs) {
            return false;
        }
        $nowTs = $now->getTimestamp();
        if ($nowTs < $startTs) {
            return false;
        }
        if ($end) {
            $endTs = strtotime($end);
            if ($endTs && $nowTs > $endTs) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('nominations_default_event_id')) {
    function nominations_default_event_id(mysqli $conn, array $events): ?int
    {
        unset($conn);
        foreach ($events as $ev) {
            if ((int) ($ev['is_active'] ?? 0) === 1) {
                return (int) ($ev['event_id'] ?? 0) ?: null;
            }
        }
        if ($events !== []) {
            return (int) ($events[0]['event_id'] ?? 0) ?: null;
        }
        return null;
    }
}
