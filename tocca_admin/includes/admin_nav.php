<?php
declare(strict_types=1);

/** @var string|null $adminActivePage */
/** @var string|null $adminActiveNested */
$adminActivePage = $adminActivePage ?? null;
$adminActiveNested = $adminActiveNested ?? null;

/**
 * Mark the current admin page (and optional nested sidebar item) as active.
 */
function admin_nav_active(string $page, ?string $nested = null): string
{
    global $adminActivePage, $adminActiveNested;
    $adminActivePage = $page;
    $adminActiveNested = $nested;
    return '';
}

/**
 * Script name => [page file, nested key|null]
 *
 * @return array<string, array{0: string, 1: string|null}>
 */
function admin_nav_script_map(): array
{
    return [
        'dashboard.php'            => ['dashboard.php', null],
        'nominations.php'          => ['nominations.php', null],
        'nomination_profile.php'   => ['nominations.php', null],
        'events.php'               => ['events.php', 'events'],
        'categories.php'           => ['categories.php', 'categories'],
        'questions.php'            => ['questions.php', 'questions'],
        'establishment_types.php'  => ['establishment_types.php', 'establishment_types'],
        'choices.php'              => ['choices.php', 'choices'],
        'nomination_fields.php'    => ['nomination_fields.php', 'nomination_fields'],
        'award_validation_log.php' => ['award_validation_log.php', 'award_validation'],
        'communications.php'     => ['communications.php', 'communications'],
        'communications_qr.php'    => ['communications_qr.php', 'communications_qr'],
        'nomination_feedbacks.php' => ['nomination_feedbacks.php', 'nomination_feedbacks'],
        'voters_feedbacks.php'     => ['voters_feedbacks.php', 'voters_feedbacks'],
        'nomination_reports.php'   => ['nomination_reports.php', 'nomination_reports'],
        'voters.php'               => ['voters.php', 'voters'],
        'results.php'              => ['results.php', 'results'],
        'system_utilities.php'     => ['system_utilities.php', 'system_utilities'],
        'archives.php'             => ['archives.php', 'archives'],
        'audit_logs.php'           => ['audit_logs.php', 'audit_logs'],
        'qr_frame_settings.php'    => ['admin_settings.php', 'admin_settings'],
        'admin_settings.php'       => ['admin_settings.php', 'admin_settings'],
        'voter_settings.php'       => ['voter_portal_copy.php', 'voter_portal'],
        'voter_portal_copy.php'    => ['voter_portal_copy.php', 'voter_portal'],
        'nomination_settings.php'  => ['nomination_settings.php', 'nomination_settings'],
        'voter_mobile_preview.php' => ['voter_mobile_preview.php', 'voter_mobile_preview'],
        'tables.php'               => ['tables.php', null],
    ];
}

/** Apply active nav from the running script when not set manually. */
function admin_apply_nav_from_script(): void
{
    global $adminActivePage;
    if ($adminActivePage !== null && $adminActivePage !== '') {
        return;
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $map = admin_nav_script_map();
    if (!isset($map[$script])) {
        return;
    }
    admin_nav_active($map[$script][0], $map[$script][1]);
}
