<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/get_logo.php';

if (!function_exists('voter_appearance_normalize_logo_path')) {
    function voter_appearance_normalize_logo_path(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return 'img/tocca2023.jpg';
        }
        if (preg_match('#^\.\./e-vote-final-enhanced/(.+)$#', $path, $m)) {
            return $m[1];
        }
        if (preg_match('#^e-vote-final-enhanced/(.+)$#', $path, $m)) {
            return $m[1];
        }

        return $path;
    }
}

if (!function_exists('voter_appearance_is_configured')) {
    function voter_appearance_is_configured(mysqli $conn): bool
    {
        $stmt = $conn->prepare(
            "SELECT 1 FROM tbl_config WHERE config_key IN ('voter_bg_color', 'voter_text_color') LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->execute();
        $stmt->store_result();
        $has = $stmt->num_rows > 0;
        $stmt->close();

        return $has;
    }
}

if (!function_exists('voter_appearance_sanitize_hex')) {
    function voter_appearance_sanitize_hex(string $color, string $fallback): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return $color;
        }
        if (preg_match('/^#[0-9A-Fa-f]{3}$/', $color)) {
            return $color;
        }

        return $fallback;
    }
}

if (!function_exists('voter_appearance_set_config')) {
    function voter_appearance_set_config(mysqli $conn, string $key, string $value): bool
    {
        $stmt = $conn->prepare(
            'INSERT INTO tbl_config (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $key, $value);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok && function_exists('config_invalidate_cache')) {
            config_invalidate_cache();
        }

        return $ok;
    }
}

if (!function_exists('voter_appearance_get_config')) {
    function voter_appearance_get_config(mysqli $conn, string $key, string $default = ''): string
    {
        if (!function_exists('getConfig')) {
            $stmt = $conn->prepare('SELECT config_value FROM tbl_config WHERE config_key = ? LIMIT 1');
            if (!$stmt) {
                return $default;
            }
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $stmt->bind_result($val);
            if ($stmt->fetch()) {
                $stmt->close();

                return (string) $val;
            }
            $stmt->close();

            return $default;
        }

        return (string) getConfig($key, $default);
    }
}

if (!function_exists('voter_appearance_load')) {
    /** @return array{bgColor: string, textColor: string, headerLogo: string} */
    function voter_appearance_load(mysqli $conn): array
    {
        return [
            'bgColor'    => voter_appearance_sanitize_hex(
                voter_appearance_get_config($conn, 'voter_bg_color', '#ffffff'),
                '#ffffff'
            ),
            'textColor'  => voter_appearance_sanitize_hex(
                voter_appearance_get_config($conn, 'voter_text_color', '#000000'),
                '#000000'
            ),
            'headerLogo' => voter_header_logo_public_src(
                voter_appearance_normalize_logo_path(
                    voter_appearance_get_config($conn, 'voter_header_logo', 'img/tocca2023.jpg')
                )
            ),
        ];
    }
}

if (!function_exists('voter_appearance_admin_logo_src')) {
    /** Logo path for &lt;img&gt; on admin pages (under tocca_admin/). */
    function voter_appearance_admin_logo_src(string $stored): string
    {
        $path = voter_appearance_normalize_logo_path($stored);
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if (str_starts_with($path, '../')) {
            return $path;
        }

        return '../e-vote-final-enhanced/' . ltrim($path, '/');
    }
}

if (!function_exists('voter_appearance_save_from_request')) {
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    function voter_appearance_save_from_request(mysqli $conn, array $post, array $files): bool
    {
        $ok1 = voter_appearance_set_config(
            $conn,
            'voter_bg_color',
            voter_appearance_sanitize_hex((string) ($post['voter_bg_color'] ?? '#ffffff'), '#ffffff')
        );
        $ok2 = voter_appearance_set_config(
            $conn,
            'voter_text_color',
            voter_appearance_sanitize_hex((string) ($post['voter_text_color'] ?? '#000000'), '#000000')
        );

        $ok4 = true;
        if (isset($files['voter_header_logo']) && (int) ($files['voter_header_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $targetDir = dirname(__DIR__) . '/../e-vote-final-enhanced/img/';
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }
            $fileName = basename((string) $files['voter_header_logo']['name']);
            $safeName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $fileName);
            $uniqueFileName = time() . '_' . $safeName;
            $targetFile = $targetDir . $uniqueFileName;
            $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (in_array($imageFileType, $allowedTypes, true)
                && move_uploaded_file((string) $files['voter_header_logo']['tmp_name'], $targetFile)) {
                $ok4 = voter_appearance_set_config(
                    $conn,
                    'voter_header_logo',
                    'img/' . $uniqueFileName
                );
            } else {
                $ok4 = false;
            }
        }

        return $ok1 && $ok2 && $ok4;
    }
}
