<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_page.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_log.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

/** ---------- Helpers ---------- **/
function flash_toast(string $msg, string $type = 'success'): void {
    $_SESSION['flash_toast'] = ['message' => $msg, 'type' => $type]; // type: success | danger | info | warning
}

function redirect_settings(string $hash = '#brandingPane'): void {
    if ($hash !== '' && !str_starts_with($hash, '#')) {
        $hash = '#' . $hash;
    }
    session_write_close();
    header('Location: admin_settings.php' . $hash);
    exit;
}

function file_upload_ok(string $field): bool
{
    return isset($_FILES[$field]['error']) && (int) $_FILES[$field]['error'] === UPLOAD_ERR_OK;
}

// Basic hex color sanitizer (#RRGGBB)
function sanitizeHexColor($input, $fallback = '#ffffff') {
    $input = trim((string)$input);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $input) ? $input : $fallback;
}

function setConfig($key, $value) {
    global $conn;
    $stmt = $conn->prepare("
        INSERT INTO tbl_config (config_key, config_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
    ");
    if (!$stmt) {
        flash_toast('Database error while saving setting.', 'danger');
        redirect_settings();
    }
    $stmt->bind_param("ss", $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getConfig($key, $default = '') {
    global $conn;
    $stmt = $conn->prepare("SELECT config_value FROM tbl_config WHERE config_key = ? LIMIT 1");
    if (!$stmt) return $default;

    $stmt->bind_param("s", $key);
    $stmt->execute();
    $stmt->bind_result($val);

    if ($stmt->fetch()) {
        $stmt->close();
        return $val;
    }

    $stmt->close();
    return $default;
}

/**
 * Uploads an image and returns the relative path (e.g., img/123_name.png)
 */
function uploadImage($inputName) {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    // 8MB max
    if (!empty($_FILES[$inputName]['size']) && $_FILES[$inputName]['size'] > 8 * 1024 * 1024) {
        return null;
    }

    $targetDirAbs = __DIR__ . "/img/";
    $targetDirRel = "img/";
    if (!is_dir($targetDirAbs)) { @mkdir($targetDirAbs, 0775, true); }

    $originalName = basename($_FILES[$inputName]["name"]);
    $cleanName = preg_replace("/[^a-zA-Z0-9\._-]/", "_", $originalName);
    $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));

    $allowedExt = ['jpg','jpeg','png','gif','ico','webp'];
    if (!in_array($ext, $allowedExt, true)) return null;

    $tmp = $_FILES[$inputName]['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_buffer($finfo, file_get_contents($tmp)) : '';
    if ($finfo) finfo_close($finfo);

    $allowedMime = ['image/png','image/jpeg','image/gif','image/webp','image/x-icon','image/vnd.microsoft.icon'];
    if (!in_array($mime, $allowedMime, true) && $ext !== 'ico') return null;

    $uniqueFileName = time() . "_" . $cleanName;
    $targetAbs = $targetDirAbs . $uniqueFileName;
    $targetRel = $targetDirRel . $uniqueFileName;

    if (move_uploaded_file($tmp, $targetAbs)) return $targetRel;
    return null;
}

/** ---------- Handlers ---------- **/

if (file_upload_ok('logo') && !isset($_POST['save_logo_settings'])) {
    $path = uploadImage('logo');
    if ($path && setConfig('logo_path', $path)) {
        audit_log($conn, 'admin_settings', 'update', 'config', 'logo_path', ['path' => $path]);
        flash_toast('Sidebar logo updated!', 'success');
    } else {
        flash_toast('Sidebar logo upload failed.', 'danger');
    }
    redirect_settings('#brandingPane');
}

if (file_upload_ok('mini_logo') && !isset($_POST['save_logo_settings'])) {
    $path = uploadImage('mini_logo');
    if ($path && setConfig('mini_logo_path', $path)) {
        audit_log($conn, 'admin_settings', 'update', 'config', 'mini_logo_path', ['path' => $path]);
        flash_toast('Mini logo updated!', 'success');
    } else {
        flash_toast('Mini logo upload failed.', 'danger');
    }
    redirect_settings('#brandingPane');
}

if (isset($_POST['update_favicon']) || (file_upload_ok('favicon') && !isset($_POST['save_logo_settings']))) {
    if (!file_upload_ok('favicon')) {
        flash_toast('Please choose a favicon file to upload.', 'warning');
        redirect_settings('#brandingPane');
    }
    $path = uploadImage('favicon');
    if ($path && setConfig('favicon_path', $path)) {
        audit_log($conn, 'admin_settings', 'update', 'config', 'favicon_path', ['path' => $path]);
        flash_toast('Favicon updated!', 'success');
    } else {
        flash_toast('Favicon upload failed. Use PNG or ICO.', 'danger');
    }
    redirect_settings('#brandingPane');
}

/**
 * 1) BRANDING & LOGOS
 */
if (isset($_POST['save_logo_settings'])) {
    $anyChange = false;
    $allOk     = true;

    // Sidebar Logo
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $path = uploadImage('logo');
        $anyChange = true;
        if (!$path || !setConfig('logo_path', $path)) {
            $allOk = false;
        }
    }

    // Mini Logo
    if (isset($_FILES['mini_logo']) && $_FILES['mini_logo']['error'] === UPLOAD_ERR_OK) {
        $path = uploadImage('mini_logo');
        $anyChange = true;
        if (!$path || !setConfig('mini_logo_path', $path)) {
            $allOk = false;
        }
    }

    // Favicon
    if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
        $path = uploadImage('favicon');
        $anyChange = true;
        if (!$path || !setConfig('favicon_path', $path)) {
            $allOk = false;
        }
    }

    if (!$anyChange) {
        flash_toast('No logo files selected.', 'info');
    } else {
        if ($allOk) {
            audit_log($conn, 'admin_settings', 'update', 'config', 'branding_logos', []);
            flash_toast('Branding logos updated!', 'success');
        } else {
            flash_toast('Some branding items failed to save.', 'warning');
        }
    }
    redirect_settings('#brandingPane');
}

/**
 * 2) COLOR SCHEME
 */
if (isset($_POST['update_colors'])) {
    $navbar  = sanitizeHexColor($_POST['navbar_bg_color']    ?? '#343a40', '#343a40');
    $sidebar = sanitizeHexColor($_POST['sidebar_bg_color']   ?? '#343a40', '#343a40');
    $text    = sanitizeHexColor($_POST['sidebar_text_color'] ?? '#ffffff', '#ffffff');

    setConfig('navbar_bg_color',    $navbar);
    setConfig('sidebar_bg_color',   $sidebar);
    setConfig('sidebar_text_color', $text);

    audit_log($conn, 'admin_settings', 'update', 'config', 'admin_theme', [
        'navbar_bg_color' => $navbar,
        'sidebar_bg_color' => $sidebar,
        'sidebar_text_color' => $text,
    ]);

    flash_toast('Admin theme colors updated!', 'success');
    redirect_settings('#themePane');
}

// Fallback: nothing recognized
flash_toast('Nothing was submitted or the request was not recognized.', 'info');
redirect_settings('#brandingPane');
