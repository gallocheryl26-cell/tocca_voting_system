<?php
if (!function_exists('render_breadcrumb')) {
    function render_breadcrumb(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        $html = '<nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">';
        $lastIndex = count($items) - 1;

        foreach ($items as $index => $item) {
            $label = htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $url = $item['url'] ?? null;
            $sidebarTarget = isset($item['sidebarTarget']) ? trim((string) $item['sidebarTarget']) : '';
            $isLast = ($index === $lastIndex);

            if ($isLast) {
                $html .= '<li class="breadcrumb-item active" aria-current="page">' . $label . '</li>';
            } elseif ($sidebarTarget !== '') {
                $targetAttr = htmlspecialchars($sidebarTarget, ENT_QUOTES, 'UTF-8');
                $html .= '<li class="breadcrumb-item"><a href="#" class="breadcrumb-sidebar-link" role="button" data-sidebar-target="' . $targetAttr . '" aria-controls="' . $targetAttr . '">' . $label . '</a></li>';
            } elseif (is_string($url) && $url !== '') {
                $html .= '<li class="breadcrumb-item"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a></li>';
            } else {
                $html .= '<li class="breadcrumb-item">' . $label . '</li>';
            }
        }

        $html .= '</ol></nav>';
        return $html;
    }

    /**
     * Standard trail for File Maintenance pages (section hub → current page).
     *
     * @param array<int, array{label: string, url?: string, sidebarTarget?: string}> $tail
     */
    function render_file_maintenance_breadcrumb(array $tail): string
    {
        return render_breadcrumb(array_merge([
            ['label' => 'File Maintenance', 'url' => 'events.php'],
        ], $tail));
    }

    /**
     * @param array<int, array{label: string, url?: string, sidebarTarget?: string}> $tail
     */
    function render_section_breadcrumb(string $sectionLabel, string $hubUrl, array $tail): string
    {
        return render_breadcrumb(array_merge([
            ['label' => $sectionLabel, 'url' => $hubUrl],
        ], $tail));
    }

    /**
     * Top-level Nominations module (peer to Dashboard, not under it).
     *
     * @param array<int, array{label: string, url?: string, sidebarTarget?: string}> $tail
     */
    function render_nominations_breadcrumb(array $tail, ?string $listUrl = null): string
    {
        return render_breadcrumb(array_merge([
            ['label' => 'Nominations', 'url' => $listUrl ?? 'nominations.php'],
        ], $tail));
    }

    /** Transactions hub = Awards Validation (first Transactions sidebar item). */
    function render_transactions_breadcrumb(array $tail): string
    {
        return render_section_breadcrumb('Transactions', 'award_validation_log.php', $tail);
    }

    /** Utilities hub = System Utilities. */
    function render_utilities_breadcrumb(array $tail): string
    {
        return render_section_breadcrumb('Utilities', 'system_utilities.php', $tail);
    }

    /** Customizations hub = Admin Settings. */
    function render_customizations_breadcrumb(array $tail): string
    {
        return render_section_breadcrumb('Customizations', 'admin_settings.php', $tail);
    }

    /** Feedbacks hub = Nomination feedbacks. */
    function render_feedbacks_breadcrumb(array $tail): string
    {
        return render_section_breadcrumb('Feedbacks', 'nomination_feedbacks.php', $tail);
    }

    /** Reports hub = Nomination report. */
    function render_reports_breadcrumb(array $tail): string
    {
        return render_section_breadcrumb('Reports', 'nomination_reports.php', $tail);
    }

    /** User Portal hub = Mobile Live Preview. */
    function render_user_portal_breadcrumb(array $tail): string
    {
        return render_section_breadcrumb('User Portal', 'voter_mobile_preview.php', $tail);
    }
}

if (!function_exists('render_admin_event_context')) {
    /**
     * Render the persistent "Active Event" context bar.
     *
     * Safe to call from any admin page: if $conn is missing or the events
     * tables are unavailable the partial renders nothing.
     */
    function render_admin_event_context(): string
    {
        global $conn;
        $partial = __DIR__ . '/partials/admin_event_context.php';
        if (!is_file($partial)) {
            return '';
        }
        ob_start();
        include $partial;
        return (string) ob_get_clean();
    }
}

if (!function_exists('admin_nominations_list_url')) {
    /**
     * Build a safe return URL for the nominations list (prevents open redirects).
     */
    function admin_nominations_list_url(?string $return = null): string
    {
        $fallback = 'nominations.php';
        $return = trim((string) $return);
        if ($return === '') {
            return $fallback;
        }
        if (!preg_match('#^nominations\.php(?:\?(.*))?$#', $return, $m)) {
            return $fallback;
        }
        if (!isset($m[1]) || $m[1] === '') {
            return $fallback;
        }
        parse_str($m[1], $params);
        if (!is_array($params)) {
            return $fallback;
        }
        $allowedStatus = ['pending', 'in_review', 'needs_info', 'approved', 'rejected', 'merged', 'all'];
        $safe = [];
        if (isset($params['event_id']) && (int) $params['event_id'] > 0) {
            $safe['event_id'] = (string) (int) $params['event_id'];
        }
        if (isset($params['page']) && (int) $params['page'] > 0) {
            $safe['page'] = (string) (int) $params['page'];
        }
        if (isset($params['status'])) {
            $status = strtolower(preg_replace('/[^a-z_]/', '', (string) $params['status']));
            if (in_array($status, $allowedStatus, true)) {
                $safe['status'] = $status;
            }
        }
        $qs = http_build_query($safe);
        return 'nominations.php' . ($qs !== '' ? '?' . $qs : '');
    }
}