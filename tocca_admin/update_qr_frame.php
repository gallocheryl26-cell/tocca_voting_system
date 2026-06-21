<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_page.php';
require_once __DIR__ . '/db_connection.php';

function flash_toast(string $msg, string $type = 'success'): void {
    $_SESSION['flash_toast'] = ['message' => $msg, 'type' => $type];
}

function redirect_settings(): void {
    header('Location: admin_settings.php#qr-frame-settings');
    exit;
}

function setConfigValue(string $key, string $value): bool {
    global $conn;
    if (!$conn) return false;
    $stmt = $conn->prepare(
        "INSERT INTO tbl_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function uploadFrameImage(string $inputName): ?string {
    if (empty($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    if (!empty($_FILES[$inputName]['size']) && $_FILES[$inputName]['size'] > 8 * 1024 * 1024) {
        return null;
    }

    $targetDirAbs = __DIR__ . '/img/';
    $targetDirRel = 'img/';
    if (!is_dir($targetDirAbs)) {
        @mkdir($targetDirAbs, 0775, true);
    }

    $originalName = basename($_FILES[$inputName]['name'] ?? 'frame.png');
    $cleanName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $originalName);
    $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));

    $allowedExt = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }

    $tmpPath = $_FILES[$inputName]['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $tmpPath) : '';
    if ($finfo) finfo_close($finfo);

    $allowedMime = ['image/png','image/jpeg','image/gif','image/webp'];
    if (!in_array($mime, $allowedMime, true)) {
        return null;
    }

    $uniqueName = time() . '_' . $cleanName;
    $targetAbs  = $targetDirAbs . $uniqueName;
    $targetRel  = $targetDirRel . $uniqueName;

    if (move_uploaded_file($tmpPath, $targetAbs)) {
        return $targetRel;
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_toast('No changes submitted.', 'info');
    redirect_settings();
}

$frameDefaults = [
    'box_x' => 500,
    'box_y' => 1000,
    'box_w' => 660,
    'box_h' => 660,
    'card_side_pad' => 24,
    'card_top_pad' => 18,
    'label_strip_h_min' => 72,
    'label_strip_h_max' => 140,
    'gap_qr_to_label' => 10,
    'qr_side_min' => 360,
    'label_side_pad' => 10,
    'label_top_pad' => 8,
    'label_bottom_pad' => 10,
    'label_line_spacing' => 6,
    'label_font_size' => 40,
];

$minimums = [
    'box_x' => 0,
    'box_y' => 0,
    'box_w' => 1,
    'box_h' => 1,
    'card_side_pad' => 0,
    'card_top_pad' => 0,
    'label_strip_h_min' => 0,
    'label_strip_h_max' => 0,
    'gap_qr_to_label' => 0,
    'qr_side_min' => 1,
    'label_side_pad' => 0,
    'label_top_pad' => 0,
    'label_bottom_pad' => 0,
    'label_line_spacing' => 0,
    'label_font_size' => 1,
];

$sanitized = [];
foreach ($frameDefaults as $key => $fallback) {
    $raw = $_POST[$key] ?? $fallback;
    if ($raw === '' || $raw === null) {
        $raw = $fallback;
    }
    $value = is_numeric($raw) ? (int)$raw : (int)$fallback;
    $min = $minimums[$key] ?? 0;
    if ($value < $min) {
        $value = $min;
    }
    $sanitized[$key] = $value;
}

if ($sanitized['label_strip_h_max'] < $sanitized['label_strip_h_min']) {
    $sanitized['label_strip_h_max'] = $sanitized['label_strip_h_min'];
}

$useFrame = isset($_POST['use_frame']) ? '1' : '0';

$currentPath = trim($_POST['current_frame_path'] ?? '');
$manualPath  = trim($_POST['frame_path'] ?? '');
if ($manualPath !== '' && strpos($manualPath, '..') !== false) {
    $manualPath = '';
}
$uploadedPath = uploadFrameImage('frame_file');

$framePathToSave = null;
if ($uploadedPath) {
    $framePathToSave = $uploadedPath;
} elseif ($manualPath !== '') {
    $framePathToSave = $manualPath;
} elseif ($currentPath !== '') {
    $framePathToSave = $currentPath;
}

$configPayload = $sanitized;
$configPayload['use_frame'] = $useFrame === '1';
if ($framePathToSave) {
    $configPayload['frame_path'] = $framePathToSave;
}

$configJson = json_encode($configPayload, JSON_UNESCAPED_SLASHES);
if ($configJson === false) {
    flash_toast('Failed to encode frame settings.', 'danger');
    redirect_settings();
}

$errors = [];
if (!setConfigValue('qr_frame_use', $useFrame)) {
    $errors[] = 'frame toggle';
}
if (!setConfigValue('qr_frame_options', $configJson)) {
    $errors[] = 'layout settings';
}
if ($framePathToSave && !setConfigValue('qr_frame_path', $framePathToSave)) {
    $errors[] = 'frame image path';
}

if ($errors) {
    flash_toast('Failed to update ' . implode(', ', $errors) . '.', 'danger');
} else {
    flash_toast('QR frame settings updated!', 'success');
}

redirect_settings();