<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/require_admin_page.php';
require_once dirname(__DIR__) . '/db_connection.php';
include dirname(__DIR__) . '/get_logo.php';
require_once dirname(__DIR__) . '/breadcrumb.php';
require_once __DIR__ . '/admin_nav.php';
require_once __DIR__ . '/admin_schema.php';

if (!function_exists('h')) {
    function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

function admin_link_class(string $page, ?string $nested = null): string
{
    global $adminActivePage, $adminActiveNested;
    $isActive = ($adminActivePage === $page) && ($nested === null || $adminActiveNested === $nested);
    return 'nav-link' . ($isActive ? ' active' : '');
}

function admin_collapse_class(string $sectionId, array $nestedPages): string
{
    global $adminActiveNested;
    $open = in_array($adminActiveNested ?? '', $nestedPages, true);
    return 'collapse' . ($open ? ' show' : '');
}

function admin_get_active_event(mysqli $conn): ?array
{
    static $cached = null;
    static $loaded = false;
    if ($loaded) {
        return $cached;
    }
    $loaded = true;

    $sql = "SELECT event_id, event_name, year, description, is_active, is_archived,
                   nomination_start, nomination_end, voting_start, voting_end
            FROM tbl_events
            WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0
            ORDER BY year DESC, event_id DESC
            LIMIT 1";
    $res = $conn->query($sql);
    if ($res instanceof mysqli_result && $res->num_rows > 0) {
        $cached = $res->fetch_assoc() ?: null;
        $res->free();
    }

    return $cached;
}

function admin_active_event_id(mysqli $conn): ?int
{
    $row = admin_get_active_event($conn);
    return $row ? (int) ($row['event_id'] ?? 0) : null;
}

/** Mask mobile for admin display: first 3 + last 2 digits visible. */
function admin_mask_mobile(?string $phone): string
{
    $phone = trim((string) $phone);
    if ($phone === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '' || strlen($digits) < 3) {
        return '•••';
    }
    $first3 = substr($digits, 0, 3);
    $last2  = strlen($digits) >= 5 ? substr($digits, -2) : '';
    $hidden = max(0, strlen($digits) - strlen($first3) - strlen($last2));
    $masked = $first3 . str_repeat('•', $hidden) . $last2;
    return str_starts_with($phone, '+') ? '+' . $masked : $masked;
}

/** @return array<string,mixed>|null */
function admin_fetch_active_event(mysqli $conn): ?array
{
    return admin_get_active_event($conn);
}

function admin_parent_link_class(string $sectionId, array $nestedPages): string
{
    global $adminActiveNested;
    $open = in_array($adminActiveNested ?? '', $nestedPages, true);
    return 'nav-link' . ($open ? '' : ' collapsed');
}

/** Convenience: set nav from script map when using shared sidebar without admin_init. */
function admin_sidebar_bootstrap(): void
{
    admin_apply_nav_from_script();
}
