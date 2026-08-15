<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function wrapTitleBlock(string $title, string $crumbCall): string
{
    return '          <div class="admin-page-header mt-4 mb-4">' . "\n"
        . '            <div class="min-w-0">' . "\n"
        . '              <h1 class="admin-page-title mb-2">' . $title . '</h1>' . "\n"
        . '              <?php echo ' . $crumbCall . '; ?>' . "\n"
        . '            </div>' . "\n"
        . '          </div>';
}

$pages = [
    ['establishment_types.php', 'Business Categories', "render_file_maintenance_breadcrumb([['label' => 'Business Categories']])"],
    ['choices.php', 'Businesses', "render_file_maintenance_breadcrumb([['label' => 'Businesses']])"],
    ['nomination_fields.php', 'Nomination Form', "render_file_maintenance_breadcrumb([['label' => 'Nomination Form']])"],
    ['award_validation_log.php', 'Awards Validation Log', "render_nominations_breadcrumb([['label' => 'Awards Validation']])"],
    ['communications.php', 'Nomination Emails', "render_transactions_breadcrumb([['label' => 'Nomination Emails']])"],
    ['communications_qr.php', 'QR Emails', "render_transactions_breadcrumb([['label' => 'QR Emails']])"],
    ['nomination_feedbacks.php', 'Nomination Feedback', "render_feedbacks_breadcrumb([['label' => 'Nomination']])"],
    ['voters_feedbacks.php', 'Voters Feedback', "render_feedbacks_breadcrumb([['label' => 'Voting']])"],
    ['voters.php', 'Voters', "render_reports_breadcrumb([['label' => 'Voters']])"],
    ['results.php', 'Results', "render_reports_breadcrumb([['label' => 'Results']])"],
    ['system_utilities.php', 'System Utilities', "render_utilities_breadcrumb([['label' => 'System Utilities']])"],
    ['archives.php', 'Archives', "render_utilities_breadcrumb([['label' => 'Archives']])"],
    ['audit_logs.php', 'Admin History Log', "render_utilities_breadcrumb([['label' => 'Audit Logs']])"],
    ['admin_settings.php', 'Admin Settings', "render_customizations_breadcrumb([['label' => 'Admin Settings']])"],
    ['voter_portal_copy.php', 'Voter Portal', "render_customizations_breadcrumb([['label' => 'Voter Portal']])"],
    ['nomination_settings.php', 'Nomination Settings', "render_customizations_breadcrumb([['label' => 'Nomination Settings']])"],
    ['tables.php', 'Tables', "render_breadcrumb([['label' => 'Tables']])"],
];

foreach ($pages as [$file, $title, $crumb]) {
    $path = $root . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing: $file\n");
        continue;
    }
    $content = file_get_contents($path);
    $patterns = [
        '<h1 class="mt-4">' . $title . '</h1>',
        '<h1 class="mt-4">' . $title . "</h1>\r\n",
    ];
    $new = wrapTitleBlock($title, $crumb);
    $found = false;
    foreach ($patterns as $old) {
        if (str_contains($content, $old)) {
            $content = str_replace($old, $new, $content);
            $found = true;
            break;
        }
    }
    if (!$found) {
        fwrite(STDERR, "Pattern not found in $file\n");
        continue;
    }
    file_put_contents($path, $content);
    echo "Updated: $file\n";
}

$profile = $root . '/nomination_profile.php';
$p = file_get_contents($profile);
$p = str_replace('<h1 class="mb-2">Nomination Profile</h1>', '<h1 class="admin-page-title mb-2">Nomination Profile</h1>', $p);
file_put_contents($profile, $p);
echo "Updated: nomination_profile.php\n";

$reports = $root . '/nomination_reports.php';
$r = file_get_contents($reports);
$r = str_replace(
    '<div class="d-flex flex-wrap justify-content-between align-items-end mt-4 mb-3 gap-2">' . "\n" .
    '            <div>' . "\n" .
    '              <h1 class="mb-1">Nomination</h1>',
    ('<' . 'div class="d-flex flex-wrap justify-content-between align-items-end admin-page-header mt-4 mb-4 gap-2">') . "\n" .
    '            <motion class="min-w-0">' . "\n" .
    '              <h1 class="admin-page-title mb-2">Nomination</h1>',
    $r
);
$r = str_replace('motion class="d-flex', 'motion class="d-flex', $r);
$r = str_replace('motion class="min-w-0"', 'div class="min-w-0"', $r);
file_put_contents($reports, $r);
echo "Updated: nomination_reports.php\n";

$partial = $root . '/partials/feedback_page_body.php';
$f = file_get_contents($partial);
$f = str_replace(
    '<h1 class="mb-1"><?php echo h($pageTitle ?? \'Feedbacks\'); ?></h1>' . "\n" .
    '    <?php echo render_feedbacks_breadcrumb([[\'label\' => $pageTitle ?? \'Feedbacks\']]); ?>',
    '    <div class="admin-page-header mt-4 mb-4">' . "\n" .
    '      <div class="min-w-0">' . "\n" .
    '        <h1 class="admin-page-title mb-2"><?php echo h($pageTitle ?? \'Feedbacks\'); ?></h1>' . "\n" .
    '        <?php echo render_feedbacks_breadcrumb([[\'label\' => $pageTitle ?? \'Feedbacks\']]); ?>' . "\n" .
    '      </div>' . "\n" .
    '    </div>',
    $f
);
file_put_contents($partial, $f);
echo "Updated: partials/feedback_page_body.php\n";
