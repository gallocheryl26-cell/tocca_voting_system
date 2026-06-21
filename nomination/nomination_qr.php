<?php
// nomination_qr.php
// Generates a QR code PNG for the given "data" URL.

// Adjust path to your QR library (PhpQRCode or similar)
require_once __DIR__ . '/../vendor/phpqrcode/qrlib.php'; // <-- adjust to your actual path

$data = isset($_GET['data']) ? $_GET['data'] : '';
if (!$data) {
    http_response_code(400);
    echo 'Missing data';
    exit;
}

// Optional: download as file if ?download=1 is passed
$download = isset($_GET['download']) && $_GET['download'] == '1';

if ($download) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="nomination_qr.png"');
} else {
    header('Content-Type: image/png');
}

// Generate QR directly to output
QRcode::png($data, null, QR_ECLEVEL_L, 6, 2);
exit;
