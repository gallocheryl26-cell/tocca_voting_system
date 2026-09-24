<?php
require_once __DIR__ . '/require_admin_api.php';
require_once 'qr_frame_config.php';
require_once 'qr_utils.php';

$choice_id = isset($_GET['choice_id']) ? (int)$_GET['choice_id'] : 0;
$force     = isset($_GET['force']) ? (int)$_GET['force'] : 0;

if ($choice_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid choice_id.']);
    exit;
}

// Always compose from saved Admin → QR Settings (frame, name box, color).
// Do not apply File Maintenance GET overrides — those can be stale and
// would misplace the QR or skip the business-name box.
$result = generateAndSaveQR($choice_id, $force, []);
echo json_encode($result);
