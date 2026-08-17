<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_page.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/includes/report_export_helpers.php';
require_once __DIR__ . '/audit_log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['save_report_export_settings'])) {
    header('Location: admin_settings.php');
    exit;
}

function report_settings_flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash_toast'] = ['message' => $msg, 'type' => $type];
}

$orgLine = trim((string) ($_POST['report_org_line'] ?? ''));
if ($orgLine === '') {
    report_settings_flash('Organization line cannot be empty.', 'danger');
    header('Location: admin_settings.php#reportsPane');
    exit;
}

$reviewer = trim((string) ($_POST['report_reviewer_label'] ?? 'Awards Secretariat'));
$approver = trim((string) ($_POST['report_approver_label'] ?? 'Awards Committee Chair'));

$pdfEnabled   = isset($_POST['report_pdf_password_enabled']) ? '1' : '0';
$excelEnabled = isset($_POST['report_excel_protect_enabled']) ? '1' : '0';

$ok = report_export_set_config($conn, 'report_org_line', $orgLine)
   && report_export_set_config($conn, 'report_reviewer_label', $reviewer !== '' ? $reviewer : 'Awards Secretariat')
   && report_export_set_config($conn, 'report_approver_label', $approver !== '' ? $approver : 'Awards Committee Chair')
   && report_export_set_config($conn, 'report_pdf_password_enabled', $pdfEnabled)
   && report_export_set_config($conn, 'report_excel_protect_enabled', $excelEnabled);

$newPdfPassword   = trim((string) ($_POST['report_pdf_password'] ?? ''));
$newExcelPassword = trim((string) ($_POST['report_excel_password'] ?? ''));

if ($newPdfPassword !== '') {
    $ok = $ok && report_export_set_config($conn, 'report_pdf_password', $newPdfPassword);
} elseif ($pdfEnabled === '0') {
    $ok = $ok && report_export_set_config($conn, 'report_pdf_password', '');
}

if ($newExcelPassword !== '') {
    $ok = $ok && report_export_set_config($conn, 'report_excel_password', $newExcelPassword);
} elseif ($excelEnabled === '0') {
    $ok = $ok && report_export_set_config($conn, 'report_excel_password', '');
}

if ($pdfEnabled === '1') {
    $currentPdf = report_export_get_config($conn, 'report_pdf_password', '');
    if ($currentPdf === '') {
        report_settings_flash('PDF password protection is enabled but no password is set. Enter a password and save again.', 'warning');
        header('Location: admin_settings.php#reportsPane');
        exit;
    }
}

if ($excelEnabled === '1') {
    $currentExcel = report_export_get_config($conn, 'report_excel_password', '');
    if ($currentExcel === '') {
        report_settings_flash('Excel edit protection is enabled but no password is set. Enter a password and save again.', 'warning');
        header('Location: admin_settings.php#reportsPane');
        exit;
    }
}

if ($ok) {
    audit_log($conn, 'admin_settings', 'update', 'config', 'report_export', [
        'pdf_password_enabled' => $pdfEnabled === '1',
        'excel_protect_enabled' => $excelEnabled === '1',
        'password_changed' => ($newPdfPassword !== '' || $newExcelPassword !== ''),
    ]);
}

report_settings_flash(
    $ok ? 'Report export settings saved.' : 'Some report settings could not be saved.',
    $ok ? 'success' : 'danger'
);

header('Location: admin_settings.php#reportsPane');
exit;
