<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_page.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/includes/report_export_helpers.php';

function org_sig_flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash_toast'] = ['message' => $msg, 'type' => $type];
}

$adminRoot = __DIR__;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_reviewer_signature'])) {
    report_export_set_config($conn, 'report_reviewer_signature_path', '');
    org_sig_flash('Reviewer signature removed.');
    header('Location: admin_settings.php#reportsPane');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_approver_signature'])) {
    report_export_set_config($conn, 'report_approver_signature_path', '');
    org_sig_flash('Approver signature removed.');
    header('Location: admin_settings.php#reportsPane');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_settings.php#reportsPane');
    exit;
}

if (isset($_POST['upload_reviewer_signature'])) {
    $path = report_export_upload_signature_image('reviewer_signature', $adminRoot, 'org_reviewer');
    if (!$path || !report_export_set_config($conn, 'report_reviewer_signature_path', $path)) {
        org_sig_flash('Reviewer signature upload failed. Use PNG, JPG, or WEBP under 2 MB.', 'danger');
    } else {
        org_sig_flash('Reviewer signature saved.');
    }
    header('Location: admin_settings.php#reportsPane');
    exit;
}

if (isset($_POST['upload_approver_signature'])) {
    $path = report_export_upload_signature_image('approver_signature', $adminRoot, 'org_approver');
    if (!$path || !report_export_set_config($conn, 'report_approver_signature_path', $path)) {
        org_sig_flash('Approver signature upload failed. Use PNG, JPG, or WEBP under 2 MB.', 'danger');
    } else {
        org_sig_flash('Approver signature saved.');
    }
    header('Location: admin_settings.php#reportsPane');
    exit;
}

header('Location: admin_settings.php#reportsPane');
exit;
