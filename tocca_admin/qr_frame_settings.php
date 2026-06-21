<?php
/**
 * Legacy URL — QR poster design lives in Admin Settings.
 */
require_once __DIR__ . '/session_bootstrap.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

header('Location: admin_settings.php#qr-frame-settings', true, 302);
exit;
