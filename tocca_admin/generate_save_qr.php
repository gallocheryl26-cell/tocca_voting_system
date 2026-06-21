<?php
require_once __DIR__ . '/require_admin_api.php';
require_once 'qr_frame_config.php';
require_once 'qr_utils.php';

// Get parameters
$choice_id = isset($_GET['choice_id']) ? (int)$_GET['choice_id'] : 0;
$force     = isset($_GET['force']) ? (int)$_GET['force'] : 0;
$overrides = extract_frame_options_from_request($_GET);

// Validate
if ($choice_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid choice_id.']);
    exit;
}

// Generate QR using reusable function
$result = generateAndSaveQR($choice_id, $force, $overrides);
echo json_encode($result);