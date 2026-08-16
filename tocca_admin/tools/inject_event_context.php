<?php
/**
 * One-time migration: insert the Active Event Context bar after each page's
 * breadcrumb call.
 *
 * Run:
 *   php tocca_admin/tools/inject_event_context.php
 *
 * Idempotent: skips files that already render the context bar.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

// Pages that should display the event context bar (event-dependent surfaces).
$includeFiles = [
    'dashboard.php',
    'events.php',
    'categories.php',
    'questions.php',
    'establishment_types.php',
    'choices.php',
    'nomination_fields.php',
    'nominations.php',
    'nomination_profile.php',
    'award_validation_log.php',
    'communications.php',
    'communications_qr.php',
    'nomination_feedbacks.php',
    'voters_feedbacks.php',
    'nomination_reports.php',
    'voters.php',
    'twg_evaluation.php',
    'results.php',
];

$injection = "\n          <?php echo render_admin_event_context(); ?>";
$pattern   = '/(<\?php\s+echo\s+render_(?:file_maintenance_)?breadcrumb\([\s\S]*?\);\s*\?>)/';

$report = [];

foreach ($includeFiles as $name) {
    $path = $root . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        $report[] = "MISSING : $name";
        continue;
    }
    $content = file_get_contents($path);
    if ($content === false) {
        $report[] = "READ ERR: $name";
        continue;
    }
    if (strpos($content, 'render_admin_event_context()') !== false) {
        $report[] = "SKIP    : $name (already has context bar)";
        continue;
    }
    if (!preg_match($pattern, $content)) {
        $report[] = "NO MATCH: $name (no breadcrumb call found)";
        continue;
    }
    $new = preg_replace($pattern, '$1' . $injection, $content, 1, $count);
    if ($count !== 1 || $new === null) {
        $report[] = "FAIL    : $name (replace returned $count)";
        continue;
    }
    file_put_contents($path, $new);
    $report[] = "UPDATED : $name";
}

echo implode("\n", $report) . "\n";
