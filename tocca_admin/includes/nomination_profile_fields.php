<?php
declare(strict_types=1);

if (!function_exists('nomination_profile_field_norm')) {
    function nomination_profile_field_norm(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', '_', $s) ?? ''));
    }
}

if (!function_exists('nomination_profile_field_aliases')) {
    /** @return array<string, list<string>> */
    function nomination_profile_field_aliases(): array
    {
        return [
            'business_name' => ['business_name', 'official_business_name', 'company', 'company_name', 'business'],
            'owner_name'    => ['owner_name', 'owner', 'proprietor', 'owner_president_gm', 'owner_president_general_manager'],
            'email'         => ['email', 'contact_email'],
            'mobile'        => ['mobile_number', 'mobile', 'contact_phone', 'phone', 'telephone', 'contact_number'],
            'address'       => ['address', 'full_address', 'street', 'barangay'],
            'website'       => ['website', 'facebook', 'site', 'url'],
            'mayor'         => [
                'mayors_permit_no',
                'mayors_permit_number',
                'mayors_permit',
                'mayor_permit_no',
                'mayor_s_permit',
                'mayor_s_permit_number',
                'mayor_permit',
            ],
        ];
    }
}

if (!function_exists('nomination_profile_field_matches_alias')) {
    function nomination_profile_field_matches_alias(array $item, string $aliasKey): bool
    {
        $aliases = nomination_profile_field_aliases();
        if (!isset($aliases[$aliasKey])) {
            return false;
        }
        $set = array_map('nomination_profile_field_norm', $aliases[$aliasKey]);
        $name  = nomination_profile_field_norm((string) ($item['name'] ?? ''));
        $label = nomination_profile_field_norm((string) ($item['label'] ?? ''));
        return in_array($name, $set, true) || in_array($label, $set, true);
    }
}

if (!function_exists('nomination_profile_profile_role_section')) {
    function nomination_profile_profile_role_section(string $role): ?string
    {
        static $map = [
            'business_name' => 'business',
            'owner_name'    => 'contact',
            'email'         => 'contact',
            'mobile'        => 'contact',
            'website'       => 'contact',
            'mayor_permit'  => 'permits',
            'address'       => 'location',
            'logo'          => 'other',
        ];
        return $map[$role] ?? null;
    }
}

if (!function_exists('nomination_profile_classify_field')) {
    function nomination_profile_classify_field(array $item): string
    {
        $role = trim((string) ($item['profile_role'] ?? ''));
        if ($role !== '' && $role !== 'custom') {
            $mapped = nomination_profile_profile_role_section($role);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        $text = nomination_profile_field_norm((string) ($item['label'] ?? ''))
            . ' ' . nomination_profile_field_norm((string) ($item['name'] ?? ''));

        if (nomination_profile_field_matches_alias($item, 'business_name')
            || preg_match('/designation|type_of_ownership|proprietor|business_type|company_type|establishment_type/', $text)) {
            return 'business';
        }
        if (nomination_profile_field_matches_alias($item, 'owner_name')
            || nomination_profile_field_matches_alias($item, 'email')
            || nomination_profile_field_matches_alias($item, 'mobile')
            || nomination_profile_field_matches_alias($item, 'website')
            || preg_match('/owner|president|general_manager|contact|phone|telephone|email|mobile|website|facebook/', $text)) {
            return 'contact';
        }
        if (nomination_profile_field_matches_alias($item, 'mayor')
            || preg_match('/permit|registration|license|dti|sec|bir/', $text)) {
            return 'permits';
        }
        if (nomination_profile_field_matches_alias($item, 'address')
            || preg_match('/address|barangay|street|location|city|province/', $text)) {
            return 'location';
        }
        if (strtolower((string) ($item['name'] ?? '')) === 'establishment_type_id') {
            return 'other';
        }
        return 'other';
    }
}

if (!function_exists('nomination_profile_is_logo_field')) {
    function nomination_profile_is_logo_field(array $item): bool
    {
        if (($item['profile_role'] ?? '') === 'logo') {
            return true;
        }
        $hay = ((string) ($item['name'] ?? '')) . ' ' . ((string) ($item['label'] ?? ''));
        return (bool) preg_match('/logo/i', $hay)
            && strtolower((string) ($item['type'] ?? '')) === 'file';
    }
}

if (!function_exists('nomination_profile_load_answers')) {
    /** @return list<array<string,mixed>> */
    function nomination_profile_load_answers(mysqli $conn, int $nominationId): array
    {
        $roleCol = '';
        if (!function_exists('nf_column_exists')) {
            $helper = dirname(__DIR__, 2) . '/nomination/nomination_field_helpers.php';
            if (is_file($helper)) {
                require_once $helper;
            }
        }
        if (function_exists('nf_column_exists') && nf_column_exists($conn, 'profile_role')) {
            $roleCol = ', f.profile_role AS profile_role';
        }
        $eventId = 0;
        if ($ev = $conn->prepare('SELECT event_id FROM tbl_nominations WHERE nomination_id = ? LIMIT 1')) {
            $ev->bind_param('i', $nominationId);
            $ev->execute();
            $ev->bind_result($eventId);
            $ev->fetch();
            $ev->close();
            $eventId = (int) $eventId;
        }

        $hasEventCol = function_exists('nf_column_exists') && nf_column_exists($conn, 'event_id');
        $sql = 'SELECT f.id AS field_id, f.name AS name, f.label AS label, f.type AS type'
            . $roleCol . ', a.answer AS answer
                FROM tbl_nomination_fields f
                LEFT JOIN tbl_nomination_answers a ON a.field_id = f.id AND a.nomination_id = ?
                WHERE f.is_active = 1';
        if ($hasEventCol && $eventId > 0) {
            $sql .= ' AND (f.event_id IS NULL OR f.event_id = ?)';
        }
        $sql .= ' ORDER BY f.sort_order ASC, f.id ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($hasEventCol && $eventId > 0) {
            $stmt->bind_param('ii', $nominationId, $eventId);
        } else {
            $stmt->bind_param('i', $nominationId);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!is_array($rows)) {
            return [];
        }
        foreach ($rows as &$row) {
            if (function_exists('nf_public_field_label')) {
                $row['label'] = nf_public_field_label($row);
            }
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('nomination_profile_strip_label')) {
    function nomination_profile_strip_label(string $label): string
    {
        return trim(preg_replace('/\s*\*+$/', '', $label) ?? '');
    }
}

if (!function_exists('nomination_profile_format_value')) {
    function nomination_profile_format_value(array $item): string
    {
        $type = strtolower((string) ($item['type'] ?? ''));
        $val  = $item['answer'] ?? null;
        if ($val === null || trim((string) $val) === '') {
            return '&ndash;';
        }
        $raw = (string) $val;
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        // Smart normalization: some dynamic fields may be misconfigured as "file"
        // even though they store contact text such as website/email/mobile.
        $isWebsiteField = nomination_profile_field_matches_alias($item, 'website');
        $isEmailField   = nomination_profile_field_matches_alias($item, 'email');
        $isMobileField  = nomination_profile_field_matches_alias($item, 'mobile');

        if ($isWebsiteField) {
            $url = trim($raw);
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                $url = 'https://' . ltrim($url, '/');
            }
            $safeHref = $esc($url);
            $safeText = $esc($raw);
            return '<a href="' . $safeHref . '" target="_blank" rel="noopener">' . $safeText . '</a>';
        }
        if ($isEmailField || preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $raw)) {
            $safe = $esc($raw);
            return '<a href="mailto:' . $safe . '">' . $safe . '</a>';
        }
        if ($isMobileField || preg_match('/^(?:\+?63|0)9\d{9}$/', preg_replace('/\s+/', '', $raw))) {
            $digits = preg_replace('/\s+/', '', $raw);
            $safe   = $esc($digits);
            return '<a href="tel:' . $safe . '">' . $esc($raw) . '</a>';
        }

        if ($type === 'email') {
            $safe = $esc($raw);
            return '<a href="mailto:' . $safe . '">' . $safe . '</a>';
        }
        if ($type === 'url') {
            $safe = $esc($raw);
            return '<a href="' . $safe . '" target="_blank" rel="noopener">' . $safe . '</a>';
        }
        if ($type === 'tel') {
            $safe = $esc($raw);
            return '<a href="tel:' . $safe . '">' . $safe . '</a>';
        }
        if ($type === 'file') {
            $role = (string) ($item['profile_role'] ?? '');
            $isMayor = ($role === 'mayor_permit')
                || (bool) preg_match('/mayor.*permit/i', (string) ($item['label'] ?? '') . ' ' . (string) ($item['name'] ?? ''));

            // Image path → thumbnail preview (expected for Mayor's Permit).
            if (preg_match('/\.(png|jpe?g|webp|gif|svg)$/i', $raw)
                || preg_match('#uploads?/nominations/.+\.(png|jpe?g|webp|gif)$#i', $raw)
            ) {
                $safe = $esc($raw);
                $alt  = $esc(nomination_profile_strip_label((string) ($item['label'] ?? 'File')));
                return '<button type="button" class="nom-inline-file-btn p-0 border-0 bg-transparent text-start"'
                    . ' data-preview-url="' . $safe . '" data-preview-label="' . $alt . '"'
                    . ' aria-label="View full size: ' . $alt . '">'
                    . '<img src="' . $safe . '" alt="' . $alt . '" class="nom-inline-file">'
                    . '<span class="nom-inline-file-zoom" aria-hidden="true"><i class="bi bi-zoom-in"></i></span>'
                    . '<span class="nom-inline-file-missing text-muted d-none">Image unavailable</span>'
                    . '</button>';
            }

            // Legacy text answers (old permit-number era) — do not pretend they are downloads.
            $looksLikeFile = (bool) preg_match(
                '#^(https?://|/).+|\\\\|uploads?/|\.(pdf|doc|docx)$#i',
                $raw
            );
            if (!$looksLikeFile) {
                if ($isMayor) {
                    return '<span class="text-muted">' . $esc($raw)
                        . '</span> <span class="badge text-bg-light border ms-1">Legacy text</span>';
                }
                return $esc($raw);
            }
            $safe = $esc($raw);
            return '<a href="' . $safe . '" target="_blank" rel="noopener">Download</a>';
        }
        return $esc($raw);
    }
}

if (!function_exists('nomination_profile_is_mayor_field')) {
    function nomination_profile_is_mayor_field(array $item): bool
    {
        if (($item['profile_role'] ?? '') === 'mayor_permit') {
            return true;
        }
        if (nomination_profile_field_matches_alias($item, 'mayor')) {
            return true;
        }
        $hay = strtolower((string) ($item['label'] ?? '') . ' ' . (string) ($item['name'] ?? ''));
        return (bool) preg_match('/mayor.*permit|permit[_\s-]*number|permit[_\s-]*no\b/', $hay);
    }
}

if (!function_exists('nomination_profile_mayor_value_rank')) {
    /** Prefer an uploaded image over legacy text over an empty duplicate. */
    function nomination_profile_mayor_value_rank(array $item): int
    {
        $val = trim((string) ($item['answer'] ?? ''));
        if ($val === '') {
            return 0;
        }
        if (preg_match('/\.(png|jpe?g|webp|gif|svg)$/i', $val) || preg_match('#uploads?/#i', $val)) {
            return 2;
        }
        return 1;
    }
}

if (!function_exists('nomination_profile_group_answers')) {
    /**
     * @param list<array<string,mixed>> $answers
     * @return array<string, list<array<string,mixed>>>
     */
    function nomination_profile_group_answers(array $answers, bool $skipBusinessName = true): array
    {
        $buckets = [
            'business' => [],
            'contact'  => [],
            'permits'  => [],
            'location' => [],
            'other'    => [],
        ];
        $seen = [];
        $mayorBest = null;
        $mayorBestRank = -1;

        foreach ($answers as $item) {
            if (nomination_profile_is_logo_field($item)) {
                continue;
            }
            if ($skipBusinessName && nomination_profile_field_matches_alias($item, 'business_name')) {
                continue;
            }
            $key = nomination_profile_field_norm((string) ($item['name'] ?? ''))
                . '::' . nomination_profile_field_norm((string) ($item['label'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (nomination_profile_is_mayor_field($item)) {
                $rank = nomination_profile_mayor_value_rank($item);
                if ($rank > $mayorBestRank) {
                    $mayorBest = $item;
                    $mayorBestRank = $rank;
                }
                continue;
            }
            $section = nomination_profile_classify_field($item);
            $buckets[$section][] = $item;
        }

        if ($mayorBest !== null) {
            array_unshift($buckets['permits'], $mayorBest);
        }

        return $buckets;
    }
}

if (!function_exists('nomination_profile_section_meta')) {
    /** @return array<string, array{title:string,icon:string}> */
    function nomination_profile_section_meta(): array
    {
        return [
            'business' => ['title' => 'Business Information', 'icon' => 'bi-building'],
            'contact'  => ['title' => 'Contact Details', 'icon' => 'bi-person-lines-fill'],
            'permits'  => ['title' => 'Permits & Registration', 'icon' => 'bi-file-earmark-check'],
            'location' => ['title' => 'Location', 'icon' => 'bi-geo-alt'],
            'other'    => ['title' => 'Additional Details', 'icon' => 'bi-card-list'],
        ];
    }
}

if (!function_exists('nomination_profile_render_details')) {
    function nomination_profile_render_details(array $answers, bool $skipBusinessName = true): string
    {
        $buckets = nomination_profile_group_answers($answers, $skipBusinessName);
        $meta    = nomination_profile_section_meta();
        $order   = ['business', 'contact', 'permits', 'location', 'other'];
        $sections = [];

        foreach ($order as $id) {
            if (empty($buckets[$id])) {
                continue;
            }
            $rows = '';
            foreach ($buckets[$id] as $item) {
                $label = nomination_profile_strip_label((string) ($item['label'] ?? $item['name'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $fieldName = htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $rows .= '<div class="nom-dl-row" data-field-name="' . $fieldName . '">'
                    . '<dt class="nom-dl-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</dt>'
                    . '<dd class="nom-dl-value">' . nomination_profile_format_value($item) . '</dd>'
                    . '</div>';
            }
            if ($rows === '') {
                continue;
            }
            $sections[] = '<section class="nom-profile-section">'
                . '<div class="nom-section-head"><i class="bi ' . $meta[$id]['icon'] . ' me-2"></i>'
                . htmlspecialchars($meta[$id]['title'], ENT_QUOTES, 'UTF-8') . '</div>'
                . '<dl class="nom-dl mb-0">' . $rows . '</dl>'
                . '</section>';
        }

        if ($sections === []) {
            return '<div class="text-muted">No details available.</div>';
        }

        return '<div class="nom-profile-sections">' . implode('', $sections) . '</div>';
    }
}

if (!function_exists('nomination_profile_business_name')) {
    function nomination_profile_business_name(?array $nomRow, array $answers): string
    {
        $fromRow = trim((string) ($nomRow['business_name'] ?? ''));
        if ($fromRow !== '') {
            return $fromRow;
        }
        foreach ($answers as $item) {
            if (nomination_profile_field_matches_alias($item, 'business_name')) {
                $answer = trim((string) ($item['answer'] ?? ''));
                if ($answer !== '') {
                    return $answer;
                }
            }
        }
        return 'Business';
    }
}

if (!function_exists('nomination_profile_logo_path')) {
    function nomination_profile_logo_path(?array $nomRow, array $answers): string
    {
        $fromRow = trim((string) ($nomRow['logo_path'] ?? ''));
        if ($fromRow !== '') {
            return $fromRow;
        }
        foreach ($answers as $item) {
            if (nomination_profile_is_logo_field($item)) {
                $answer = trim((string) ($item['answer'] ?? ''));
                if ($answer !== '') {
                    return $answer;
                }
            }
        }
        return '';
    }
}

if (!function_exists('nomination_profile_status_badge')) {
    function nomination_profile_status_badge(string $status, bool $on_ballot = false): string
    {
        $key = strtolower($status);
        if ($on_ballot && in_array($key, ['approved', 'merged'], true)) {
            return '<span class="badge text-bg-primary">On ballot</span>';
        }
        $labels = [
            'pending'    => 'Pending',
            'in_review'  => 'In Review',
            'needs_info' => 'Needs Info',
            'approved'   => 'Under evaluation',
            'rejected'   => 'Rejected',
            'merged'     => 'Merged',
        ];
        $tones = [
            'pending'    => 'warning',
            'in_review'  => 'info',
            'needs_info' => 'secondary',
            'approved'   => 'success',
            'rejected'   => 'danger',
            'merged'     => 'info',
        ];
        $label = $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
        $tone  = $tones[$key] ?? 'secondary';
        return '<span class="badge text-bg-' . $tone . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('nomination_profile_format_datetime')) {
    function nomination_profile_format_datetime(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '&mdash;';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        return htmlspecialchars(date('n/j/Y, g:i:s A', $ts), ENT_QUOTES, 'UTF-8');
    }
}
