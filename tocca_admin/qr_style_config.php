<?php
declare(strict_types=1);

require_once __DIR__ . '/qr_frame_config.php';

function qr_style_default_values(): array
{
    return [
        'use_center_logo' => true,
        'logo_path'       => '',
        'fg_color'        => '#000000',
        'bg_color'        => '#ffffff',
        'logo_size_pct'   => 18,
        'label_color'     => '#000000',
    ];
}

function qr_style_hex_to_rgb(string $hex): array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return [0, 0, 0];
    }
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function qr_style_normalize_hex(?string $hex, string $fallback): string
{
    $hex = strtoupper(ltrim(trim((string)$hex), '#'));
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (preg_match('/^[0-9A-F]{6}$/', $hex)) {
        return '#' . $hex;
    }
    return $fallback;
}

function qr_style_absolute_logo_path(string $logoPath): string
{
    if ($logoPath === '') {
        return '';
    }
    return qr_frame_absolute_path($logoPath);
}

function qr_style_load_config($conn): array
{
    $config = qr_style_default_values();

    if ($conn instanceof mysqli) {
        if ($stmt = $conn->prepare("SELECT config_value FROM tbl_config WHERE config_key = 'qr_style_config' LIMIT 1")) {
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $data = json_decode($row['config_value'], true);
                    if (is_array($data)) {
                        if (array_key_exists('use_center_logo', $data)) {
                            $config['use_center_logo'] = (bool)$data['use_center_logo'];
                        }
                        if (!empty($data['logo_path']) && is_string($data['logo_path'])) {
                            $config['logo_path'] = trim($data['logo_path']);
                        }
                        $config['fg_color'] = qr_style_normalize_hex($data['fg_color'] ?? null, $config['fg_color']);
                        $config['bg_color'] = qr_style_normalize_hex($data['bg_color'] ?? null, $config['bg_color']);
                        $config['label_color'] = qr_style_normalize_hex($data['label_color'] ?? null, $config['label_color']);
                        if (isset($data['logo_size_pct']) && is_numeric($data['logo_size_pct'])) {
                            $config['logo_size_pct'] = max(8, min(30, (int)$data['logo_size_pct']));
                        }
                    }
                }
            }
            $stmt->close();
        }
    }

    $config['logo_path_absolute'] = qr_style_absolute_logo_path($config['logo_path']);
    return $config;
}

function qr_style_save_config($conn, array $config): bool
{
    $defaults = qr_style_default_values();
    $payload = [
        'use_center_logo' => (bool)($config['use_center_logo'] ?? $defaults['use_center_logo']),
        'logo_path'       => trim((string)($config['logo_path'] ?? '')),
        'fg_color'        => qr_style_normalize_hex($config['fg_color'] ?? null, $defaults['fg_color']),
        'bg_color'        => qr_style_normalize_hex($config['bg_color'] ?? null, $defaults['bg_color']),
        'label_color'     => qr_style_normalize_hex($config['label_color'] ?? null, $defaults['label_color']),
        'logo_size_pct'   => max(8, min(30, (int)($config['logo_size_pct'] ?? $defaults['logo_size_pct']))),
    ];

    $json = json_encode($payload);
    if ($json === false) {
        return false;
    }

    if (!$stmt = $conn->prepare("INSERT INTO tbl_config (config_key, config_value) VALUES ('qr_style_config', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)")) {
        return false;
    }
    $stmt->bind_param('s', $json);
    $ok = $stmt->execute();
    $stmt->close();

    // bust static cache
    if (function_exists('qr_style_load_config')) {
        // force reload on next call by resetting via reflection hack - simpler: use a global
    }

    return $ok;
}

function qr_style_merge_overrides(array $base, array $overrides): array
{
    $out = $base;

    if (array_key_exists('use_center_logo', $overrides)) {
        $bool = filter_var($overrides['use_center_logo'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $out['use_center_logo'] = $bool === null ? (bool)$overrides['use_center_logo'] : $bool;
    }

    foreach (['logo_path', 'fg_color', 'bg_color', 'label_color'] as $key) {
        if (array_key_exists($key, $overrides) && $overrides[$key] !== '') {
            $out[$key] = $key === 'logo_path'
                ? trim((string)$overrides[$key])
                : qr_style_normalize_hex((string)$overrides[$key], $out[$key] ?? '#000000');
        }
    }

    if (array_key_exists('logo_size_pct', $overrides) && $overrides['logo_size_pct'] !== '') {
        $out['logo_size_pct'] = max(8, min(30, (int)$overrides['logo_size_pct']));
    }

    if (!empty($overrides['logo_path_absolute'])) {
        $out['logo_path_absolute'] = (string)$overrides['logo_path_absolute'];
    } else {
        $out['logo_path_absolute'] = qr_style_absolute_logo_path($out['logo_path'] ?? '');
    }

    return $out;
}

function qr_style_from_post(array $post): array
{
    return qr_style_merge_overrides(qr_style_default_values(), [
        'use_center_logo' => isset($post['use_center_logo']) && (
            $post['use_center_logo'] === '1' ||
            $post['use_center_logo'] === 'true' ||
            $post['use_center_logo'] === 'on'
        ),
        'logo_path'       => $post['qr_logo_path'] ?? ($post['logo_path'] ?? ''),
        'fg_color'        => $post['qr_fg_color'] ?? '',
        'bg_color'        => $post['qr_bg_color'] ?? '',
        'label_color'     => $post['label_color'] ?? '',
        'logo_size_pct'   => $post['qr_logo_size_pct'] ?? '',
    ]);
}

function upload_qr_center_logo(string $inputName): ?string
{
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    if (!empty($_FILES[$inputName]['size']) && $_FILES[$inputName]['size'] > 4 * 1024 * 1024) {
        return null;
    }

    $targetDirAbs = __DIR__ . '/img/';
    $targetDirRel = 'img/';
    if (!is_dir($targetDirAbs)) {
        @mkdir($targetDirAbs, 0775, true);
    }

    $originalName = basename($_FILES[$inputName]['name']);
    $cleanName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $originalName);
    $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return null;
    }

    $tmp = $_FILES[$inputName]['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_buffer($finfo, file_get_contents($tmp)) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
        return null;
    }

    $uniqueFileName = 'qr_center_logo_' . time() . '_' . $cleanName;
    $targetAbs = $targetDirAbs . $uniqueFileName;
    $targetRel = $targetDirRel . $uniqueFileName;

    if (move_uploaded_file($tmp, $targetAbs)) {
        return $targetRel;
    }

    return null;
}
