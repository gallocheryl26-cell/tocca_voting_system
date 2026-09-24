<?php
declare(strict_types=1);

/**
 * Registration photo/video gallery (tbl_nomination_media).
 * Used by first submit, Track My Registration, and Edit registration.
 */

if (!defined('NOM_MEDIA_DIR_FS')) {
    define('NOM_MEDIA_DIR_FS', __DIR__ . '/uploads/nomination_media');
}
if (!defined('NOM_MEDIA_DIR_REL')) {
    define('NOM_MEDIA_DIR_REL', 'uploads/nomination_media');
}
if (!defined('NOM_MEDIA_IMAGE_MAX')) {
    define('NOM_MEDIA_IMAGE_MAX', 10 * 1024 * 1024);
}
if (!defined('NOM_MEDIA_VIDEO_MAX')) {
    define('NOM_MEDIA_VIDEO_MAX', 100 * 1024 * 1024);
}
if (!defined('NOM_MEDIA_MAX_FILES')) {
    define('NOM_MEDIA_MAX_FILES', 8);
}
if (!defined('TABLE_NOMINATION_MEDIA')) {
    define('TABLE_NOMINATION_MEDIA', 'tbl_nomination_media');
}

if (!function_exists('ensure_nomination_media_table')) {
    function ensure_nomination_media_table(mysqli $conn): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_NOMINATION_MEDIA . " (
            id INT NOT NULL AUTO_INCREMENT,
            nomination_id INT NOT NULL,
            media_type ENUM('image','video') NOT NULL DEFAULT 'image',
            file_path VARCHAR(500) NOT NULL,
            caption VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_nom_media_nomination (nomination_id, sort_order)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        return $conn->query($sql) !== false;
    }
}

if (!function_exists('ensure_nomination_media_storage')) {
    function ensure_nomination_media_storage(int $nominationId): ?string
    {
        $root = NOM_MEDIA_DIR_FS;
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            return null;
        }
        $ht = $root . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents(
                $ht,
                "Options -ExecCGI -Indexes\n" .
                "RemoveHandler .php .phtml .php3 .php4 .php5 .php7\n" .
                "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n" .
                "  Require all denied\n" .
                "</FilesMatch>\n"
            );
        }
        $dir = $root . DIRECTORY_SEPARATOR . $nominationId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        return $dir;
    }
}

if (!function_exists('classify_nomination_media')) {
    function classify_nomination_media(string $mime): ?string
    {
        static $images = ['image/png', 'image/jpeg', 'image/pjpeg', 'image/jpg', 'image/webp', 'image/gif'];
        static $videos = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
        if (in_array($mime, $images, true)) {
            return 'image';
        }
        if (in_array($mime, $videos, true)) {
            return 'video';
        }
        return null;
    }
}

if (!function_exists('ext_for_nomination_media')) {
    function ext_for_nomination_media(string $mime): string
    {
        static $map = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogg' => 'ogv',
            'video/quicktime' => 'mov',
        ];
        return $map[$mime] ?? 'bin';
    }
}

if (!function_exists('nomination_media_clip')) {
    function nomination_media_clip(string $s, int $max): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($s, 0, $max);
        }
        return substr($s, 0, $max);
    }
}

if (!function_exists('nomination_media_list')) {
    /** @return list<array{id:int,media_type:string,file_path:string,url:string,caption:string,sort_order:int}> */
    function nomination_media_list(mysqli $conn, int $nominationId): array
    {
        if ($nominationId <= 0 || !ensure_nomination_media_table($conn)) {
            return [];
        }
        $out = [];
        $st = $conn->prepare(
            'SELECT id, media_type, file_path, caption, sort_order
             FROM ' . TABLE_NOMINATION_MEDIA . '
             WHERE nomination_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        if (!$st) {
            return [];
        }
        $st->bind_param('i', $nominationId);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $rel = ltrim((string) ($row['file_path'] ?? ''), '/');
            $out[] = [
                'id' => (int) $row['id'],
                'media_type' => (string) ($row['media_type'] ?? 'image'),
                'file_path' => $rel,
                'url' => $rel,
                'caption' => trim((string) ($row['caption'] ?? '')),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }
        $st->close();
        return $out;
    }
}

if (!function_exists('nomination_media_count')) {
    function nomination_media_count(mysqli $conn, int $nominationId): int
    {
        return count(nomination_media_list($conn, $nominationId));
    }
}

if (!function_exists('nomination_media_unlink_rel')) {
    function nomination_media_unlink_rel(string $rel): void
    {
        $rel = ltrim(str_replace(['\\', '..'], ['/', ''], $rel), '/');
        if ($rel === '' || !str_starts_with($rel, NOM_MEDIA_DIR_REL . '/')) {
            return;
        }
        $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}

if (!function_exists('nomination_media_keep_only')) {
    /** @param list<int> $keepIds */
    function nomination_media_keep_only(mysqli $conn, int $nominationId, array $keepIds): void
    {
        $keep = [];
        foreach ($keepIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $keep[$id] = true;
            }
        }
        foreach (nomination_media_list($conn, $nominationId) as $row) {
            $id = (int) $row['id'];
            if (isset($keep[$id])) {
                continue;
            }
            $del = $conn->prepare('DELETE FROM ' . TABLE_NOMINATION_MEDIA . ' WHERE id = ? AND nomination_id = ?');
            if ($del) {
                $del->bind_param('ii', $id, $nominationId);
                $del->execute();
                $del->close();
            }
            nomination_media_unlink_rel((string) $row['file_path']);
        }
    }
}

if (!function_exists('nomination_media_append')) {
    /**
     * Save newly uploaded nomination_media[] files. Returns number saved.
     */
    function nomination_media_append(mysqli $conn, int $nominationId): int
    {
        if (empty($_FILES['nomination_media']) || !is_array($_FILES['nomination_media']['name'] ?? null)) {
            return 0;
        }
        if (!ensure_nomination_media_table($conn)) {
            return 0;
        }
        $dir = ensure_nomination_media_storage($nominationId);
        if (!$dir) {
            throw new RuntimeException('Failed to prepare media storage.');
        }

        $existing = nomination_media_list($conn, $nominationId);
        $existingCount = count($existing);
        $maxSort = 0;
        foreach ($existing as $row) {
            $maxSort = max($maxSort, (int) $row['sort_order']);
        }

        $names = (array) $_FILES['nomination_media']['name'];
        $tmps = (array) $_FILES['nomination_media']['tmp_name'];
        $errs = (array) $_FILES['nomination_media']['error'];
        $sizes = (array) $_FILES['nomination_media']['size'];
        $captions = (array) ($_POST['nomination_media_caption'] ?? []);
        $sharedCaption = trim((string) ($_POST['nomination_media_caption_all'] ?? ''));

        $incoming = 0;
        foreach ($errs as $err) {
            if ((int) $err === UPLOAD_ERR_OK) {
                $incoming++;
            }
        }
        if ($incoming === 0) {
            return 0;
        }
        if ($existingCount + $incoming > NOM_MEDIA_MAX_FILES) {
            $left = max(0, NOM_MEDIA_MAX_FILES - $existingCount);
            throw new RuntimeException(
                $left === 0
                    ? 'This registration already has ' . NOM_MEDIA_MAX_FILES . ' photos/videos. Remove one before adding another.'
                    : 'You can add at most ' . $left . ' more file(s) (maximum ' . NOM_MEDIA_MAX_FILES . ' total).'
            );
        }

        $insert = $conn->prepare(
            'INSERT INTO ' . TABLE_NOMINATION_MEDIA .
            ' (nomination_id, media_type, file_path, caption, sort_order) VALUES (?, ?, ?, ?, ?)'
        );
        if (!$insert) {
            throw new RuntimeException('Prepare registration media insert failed.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $saved = 0;
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            $err = (int) ($errs[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                $errMap = [
                    UPLOAD_ERR_INI_SIZE => 'is larger than the server allows (max 10 MB for photos).',
                    UPLOAD_ERR_FORM_SIZE => 'is larger than the form allows.',
                    UPLOAD_ERR_PARTIAL => 'was only partly received. Please try again.',
                    UPLOAD_ERR_NO_TMP_DIR => 'could not be saved (server temp folder missing).',
                    UPLOAD_ERR_CANT_WRITE => 'could not be written to disk.',
                    UPLOAD_ERR_EXTENSION => 'was blocked by a server extension.',
                ];
                throw new RuntimeException(
                    'File #' . ($i + 1) . ' ' . ($errMap[$err] ?? ('failed to upload (code ' . $err . ').'))
                );
            }
            $tmp = (string) ($tmps[$i] ?? '');
            if (!is_uploaded_file($tmp)) {
                continue;
            }
            $mime = (string) $finfo->file($tmp);
            $kind = classify_nomination_media($mime);
            if (!$kind) {
                throw new RuntimeException('Unsupported media type for file #' . ($i + 1) . '. Allowed: PNG, JPG, WEBP, GIF, MP4, WebM, OGG, MOV.');
            }
            $size = (int) ($sizes[$i] ?? 0);
            $max = $kind === 'image' ? NOM_MEDIA_IMAGE_MAX : NOM_MEDIA_VIDEO_MAX;
            if ($size > $max) {
                $mb = (int) round($max / (1024 * 1024));
                throw new RuntimeException('File #' . ($i + 1) . ' is too large. Max is ' . $mb . ' MB for ' . $kind . 's.');
            }

            $rawName = (string) ($names[$i] ?? 'media');
            $base = pathinfo($rawName, PATHINFO_FILENAME);
            $base = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $base);
            $base = substr(trim((string) $base, '_'), 0, 60) ?: 'media';
            $ext = ext_for_nomination_media($mime);
            $filename = $base . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
            $abs = $dir . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file($tmp, $abs)) {
                throw new RuntimeException('Failed to save file #' . ($i + 1) . '.');
            }
            @chmod($abs, 0644);

            $relPath = NOM_MEDIA_DIR_REL . '/' . $nominationId . '/' . $filename;
            $capRaw = isset($captions[$i]) ? trim((string) $captions[$i]) : '';
            if ($capRaw === '') {
                $capRaw = $sharedCaption;
            }
            $caption = $capRaw !== '' ? nomination_media_clip($capRaw, 255) : '';
            $sort = $maxSort + $saved + 1;

            $insert->bind_param('isssi', $nominationId, $kind, $relPath, $caption, $sort);
            if (!$insert->execute()) {
                @unlink($abs);
                throw new RuntimeException('Failed to record file #' . ($i + 1) . ' in the database.');
            }
            $saved++;
        }
        $insert->close();
        return $saved;
    }
}

if (!function_exists('save_nomination_media')) {
    function save_nomination_media(mysqli $conn, int $nominationId): int
    {
        return nomination_media_append($conn, $nominationId);
    }
}

if (!function_exists('nomination_media_apply_posted')) {
    /**
     * Optional keep_media_ids[] plus new nomination_media[] uploads.
     */
    function nomination_media_apply_posted(mysqli $conn, int $nominationId): int
    {
        if (!empty($_POST['keep_media_ids_posted'])) {
            $keep = $_POST['keep_media_ids'] ?? [];
            $ids = [];
            if (is_array($keep)) {
                foreach ($keep as $id) {
                    $ids[] = (int) $id;
                }
            }
            nomination_media_keep_only($conn, $nominationId, $ids);
        }
        return nomination_media_append($conn, $nominationId);
    }
}
