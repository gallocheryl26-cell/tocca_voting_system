<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$replacements = [
    "render_breadcrumb([\n            ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n            ['label' => 'Transactions', 'sidebarTarget' => 'collapseTransactions'],\n            ['label' => 'Awards Validation Log']\n          ])" =>
    "render_transactions_breadcrumb([['label' => 'Awards Validation']])",

    "render_breadcrumb([\n                  ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n                  ['label' => 'Transactions', 'sidebarTarget' => 'collapseTransactions'],\n                  ['label' => 'Voter Reminders']\n                ])" =>
    "render_transactions_breadcrumb([['label' => 'Voter Reminders']])",

    "render_breadcrumb([\n              ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n              ['label' => 'Transactions'],\n              ['label' => 'Nomination Emails']\n            ])" =>
    "render_transactions_breadcrumb([['label' => 'Nomination Emails']])",

    "render_breadcrumb([\n                      ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n                      ['label' => 'Transactions'],\n                      ['label' => 'QR Emails']\n                    ])" =>
    "render_transactions_breadcrumb([['label' => 'QR Emails']])",

    "render_breadcrumb([\n            ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n            ['label' => 'Transactions', 'url' => 'nominations.php'],\n            ['label' => 'Nominations', 'url' => \$backToNominationsUrl],\n            ['label' => 'Establishment Profile'],\n        ])" =>
    "render_transactions_breadcrumb([\n            ['label' => 'Nominations', 'url' => \$backToNominationsUrl],\n            ['label' => 'Establishment Profile'],\n        ])",

    "render_breadcrumb([\n              ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n              ['label' => 'Utilities', 'sidebarTarget' => 'collapseUtilities'],\n              ['label' => 'System Utilities']\n            ])" =>
    "render_utilities_breadcrumb([['label' => 'System Utilities']])",

    "render_breadcrumb([\n              ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n              ['label' => 'Utilities', 'sidebarTarget' => 'collapseUtilities'],\n              ['label' => 'Admin History Log']\n            ])" =>
    "render_utilities_breadcrumb([['label' => 'Audit Logs']])",

    "render_breadcrumb([\n                ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n                ['label' => 'Utilities'],\n                ['label' => 'Archives']\n              ])" =>
    "render_utilities_breadcrumb([['label' => 'Archives']])",

    "render_breadcrumb([\n              ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n              ['label' => 'Utilities'],\n              ['label' => 'QR Frame Settings'],\n            ])" =>
    "render_utilities_breadcrumb([['label' => 'QR Frame']])",

    "render_breadcrumb([\n                    ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n                    ['label' => 'Customizations', 'sidebarTarget' => 'collapseCustomizations'],\n                    ['label' => 'Voter Settings']\n                  ])" =>
    "render_customizations_breadcrumb([['label' => 'Voter Settings']])",

    "render_breadcrumb([\n                  ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n                  ['label' => 'Customizations', 'sidebarTarget' => 'collapseCustomizations'],\n                  ['label' => 'Admin Settings']\n                ])" =>
    "render_customizations_breadcrumb([['label' => 'Admin Settings']])",

    "render_breadcrumb([\n            ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n            ['label' => 'Feedbacks'],\n            ['label' => 'Voters Feedback']\n          ])" =>
    "render_feedbacks_breadcrumb([['label' => 'Voting']])",

    "render_breadcrumb([\n            ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n            ['label' => 'Feedbacks'],\n            ['label' => 'Nomination Feedback']\n          ])" =>
    "render_feedbacks_breadcrumb([['label' => 'Nomination']])",

    "render_breadcrumb([\n                        ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n                        ['label' => 'Reports'],\n                        ['label' => 'Results']\n                      ])" =>
    "render_reports_breadcrumb([['label' => 'Results']])",

    "render_breadcrumb([\n                        ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n                        ['label' => 'Reports'],\n                        ['label' => 'Voters']\n                      ])" =>
    "render_reports_breadcrumb([['label' => 'Voters']])",

    "render_breadcrumb([\n              ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n              ['label' => 'Reports'],\n              ['label' => 'Nominations'],\n          ])" =>
    "render_reports_breadcrumb([['label' => 'Nominees Report']])",

    "render_breadcrumb([\n            ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n            ['label' => 'File Maintenance', 'url' => 'events.php'],\n            ['label' => 'Establishments'],\n          ])" =>
    "render_file_maintenance_breadcrumb([['label' => 'Establishments']])",

    "render_breadcrumb([\n      ['label' => 'Dashboard', 'url' => 'dashboard.php'],\n      ['label' => 'Feedbacks', 'sidebarTarget' => 'collapseFeedbacks'],\n      ['label' => \$pageTitle ?? 'Feedbacks'],\n    ])" =>
    "render_feedbacks_breadcrumb([['label' => \$pageTitle ?? 'Feedbacks']])",
];

foreach ($replacements as $old => $new) {
    $oldNorm = str_replace(["\r\n", "\r"], "\n", $old);
    foreach (glob($root . '/*.php') as $file) {
        $content = file_get_contents($file);
        if ($content === false) {
            continue;
        }
        $norm = str_replace(["\r\n", "\r"], "\n", $content);
        if (strpos($norm, $oldNorm) === false) {
            continue;
        }
        $updated = str_replace($oldNorm, $new, $norm);
        file_put_contents($file, $updated);
        echo 'Updated: ' . basename($file) . "\n";
    }
    $partial = $root . '/partials/feedback_page_body.php';
    if (is_file($partial)) {
        $content = file_get_contents($partial);
        $norm = str_replace(["\r\n", "\r"], "\n", $content);
        if (strpos($norm, $oldNorm) !== false) {
            file_put_contents($partial, str_replace($oldNorm, $new, $norm));
            echo "Updated: partials/feedback_page_body.php\n";
        }
    }
}

echo "Done.\n";
