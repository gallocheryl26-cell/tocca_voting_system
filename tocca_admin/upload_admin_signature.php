<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_page.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/includes/report_export_helpers.php';

function signature_flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash_toast'] = ['message' => $msg, 'type' => $type];
}

$username = trim((string) ($_SESSION['username'] ?? ''));
if ($username === '') {
    signature_flash('Could not identify the signed-in admin account.', 'danger');
    header('Location: admin_settings.php#reportsPane');
    exit;
}

report_export_ensure_schema($conn);
$adminRoot = __DIR__;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_admin_signature'])) {
    $stmt = $conn->prepare('UPDATE tbl_user SET signature_path = NULL WHERE username = ? LIMIT 1');
    if (!$stmt) {
        signature_flash('Database error while removing signature.', 'danger');
        header('Location: admin_settings.php#reportsPane');
        exit;
    }
    $stmt->bind_param('s', $username);
    $ok = $stmt->execute();
    $stmt->close();

    signature_flash($ok ? 'Signature removed.' : 'Could not remove signature.', $ok ? 'success' : 'danger');
    header('Location: admin_settings.php#reportsPane');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['upload_admin_signature'])) {
    header('Location: admin_settings.php#reportsPane');
    exit;
}

$path = report_export_upload_signature_image('admin_signature', $adminRoot);
if (!$path) {
    signature_flash('Signature upload failed. Use a PNG, JPG, or WEBP under 2 MB.', 'danger');
    header('Location: admin_settings.php#reportsPane');
    exit;
}

$stmt = $conn->prepare('UPDATE tbl_user SET signature_path = ? WHERE username = ? LIMIT 1');
if (!$stmt) {
    signature_flash('Database error while saving signature.', 'danger');
    header('Location: admin_settings.php#reportsPane');
    exit;
}
$stmt->bind_param('ss', $path, $username);
$ok = $stmt->execute();
$stmt->close();

signature_flash(
    $ok ? 'Signature uploaded.' : 'Could not save signature path.',
    $ok ? 'success' : 'danger'
);
header('Location: admin_settings.php#reportsPane');
exit;
