<?php
/**
 * Admin API for managing per-establishment (choice) media: images and videos.
 *
 * Actions (JSON or multipart POST):
 *   - list      : { action: 'list', choice_id }
 *   - upload    : multipart with action=upload, choice_id, file, caption?
 *   - delete    : { action: 'delete', id }
 *   - reorder   : { action: 'reorder', choice_id, order: [id, id, ...] }
 *   - update    : { action: 'update', id, caption }
 */
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/audit_log.php';

mysqli_report(MYSQLI_REPORT_OFF);

// Storage configuration. Path is relative to this admin directory; voter pages
// reach it via "../tocca_admin/uploads/choice_media/...".
const CHOICE_MEDIA_DIR_REL = 'uploads/choice_media';
const CHOICE_MEDIA_IMAGE_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB
const CHOICE_MEDIA_VIDEO_MAX_BYTES = 100 * 1024 * 1024;  // 100 MB

function choice_media_storage_root(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, CHOICE_MEDIA_DIR_REL);
}

function choice_media_ensure_storage(): bool {
    $root = choice_media_storage_root();
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        return false;
    }
    // Drop a tight .htaccess so uploaded files are never executed.
    $htaccess = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Options -ExecCGI -Indexes\n" .
            "RemoveHandler .php .phtml .php3 .php4 .php5 .php7\n" .
            "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n" .
            "  Require all denied\n" .
            "</FilesMatch>\n"
        );
    }
    return true;
}

function choice_media_ensure_table(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS tbl_choice_media (
        id INT NOT NULL AUTO_INCREMENT,
        choice_id INT NOT NULL,
        media_type ENUM('image','video') NOT NULL DEFAULT 'image',
        file_path VARCHAR(500) NOT NULL,
        caption VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_choice_media_choice (choice_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return $conn->query($sql) !== false;
}

function choice_media_fail(string $message, int $status = 400, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['status' => 'error', 'message' => $message], $extra));
    exit;
}

function choice_media_choice_exists(mysqli $conn, int $choiceId): bool {
    $st = $conn->prepare('SELECT 1 FROM tbl_choices WHERE choice_id = ? LIMIT 1');
    if (!$st) return false;
    $st->bind_param('i', $choiceId);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    return $ok;
}

function choice_media_list(mysqli $conn, int $choiceId): array {
    $st = $conn->prepare(
        'SELECT id, choice_id, media_type, file_path, caption, sort_order, uploaded_at
         FROM tbl_choice_media
         WHERE choice_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $st->bind_param('i', $choiceId);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['id']         = (int)$r['id'];
        $r['choice_id']  = (int)$r['choice_id'];
        $r['sort_order'] = (int)$r['sort_order'];
        $rows[] = $r;
    }
    $st->close();
    return $rows;
}

function choice_media_classify(string $mime): ?string {
    static $images = ['image/png','image/jpeg','image/webp','image/gif'];
    static $videos = ['video/mp4','video/webm','video/ogg','video/quicktime'];
    if (in_array($mime, $images, true)) return 'image';
    if (in_array($mime, $videos, true)) return 'video';
    return null;
}

function choice_media_extension(string $mime): string {
    static $map = [
        'image/png'        => 'png',
        'image/jpeg'       => 'jpg',
        'image/webp'       => 'webp',
        'image/gif'        => 'gif',
        'video/mp4'        => 'mp4',
        'video/webm'       => 'webm',
        'video/ogg'        => 'ogv',
        'video/quicktime'  => 'mov',
    ];
    return $map[$mime] ?? 'bin';
}

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------
if (!choice_media_ensure_table($conn)) {
    choice_media_fail('Failed to initialize media storage table.', 500);
}
if (!choice_media_ensure_storage()) {
    choice_media_fail('Failed to initialize media storage directory.', 500);
}

$isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0;
$data = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);
$action = (string)($data['action'] ?? '');

// -----------------------------------------------------------------------------
// LIST
// -----------------------------------------------------------------------------
if ($action === 'list') {
    $choiceId = (int)($data['choice_id'] ?? 0);
    if ($choiceId <= 0) choice_media_fail('Missing choice_id.');
    echo json_encode(['status' => 'success', 'data' => choice_media_list($conn, $choiceId)]);
    exit;
}

// -----------------------------------------------------------------------------
// UPLOAD
// -----------------------------------------------------------------------------
if ($action === 'upload') {
    if (!$isMultipart) choice_media_fail('Upload must use multipart/form-data.');
    $choiceId = (int)($_POST['choice_id'] ?? 0);
    $caption  = trim((string)($_POST['caption'] ?? ''));
    if ($choiceId <= 0) choice_media_fail('Missing choice_id.');
    if (!choice_media_choice_exists($conn, $choiceId)) choice_media_fail('Business not found.', 404);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['file']['error'] ?? 'missing';
        choice_media_fail('No file uploaded (error: ' . (string)$err . ').');
    }

    $tmp  = $_FILES['file']['tmp_name'];
    $size = (int)$_FILES['file']['size'];
    if (!is_uploaded_file($tmp)) choice_media_fail('Invalid upload.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($tmp);
    $kind  = choice_media_classify($mime);
    if (!$kind) {
        choice_media_fail('Unsupported file type. Allowed: PNG, JPG, WEBP, GIF, MP4, WebM, OGG, MOV.');
    }
    $maxBytes = $kind === 'image' ? CHOICE_MEDIA_IMAGE_MAX_BYTES : CHOICE_MEDIA_VIDEO_MAX_BYTES;
    if ($size > $maxBytes) {
        $limitMb = (int)round($maxBytes / (1024 * 1024));
        choice_media_fail("File too large. Max is {$limitMb} MB for " . $kind . 's.');
    }

    $choiceDirRel = CHOICE_MEDIA_DIR_REL . '/' . $choiceId;
    $choiceDirAbs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $choiceDirRel);
    if (!is_dir($choiceDirAbs) && !mkdir($choiceDirAbs, 0775, true) && !is_dir($choiceDirAbs)) {
        choice_media_fail('Could not prepare storage folder.', 500);
    }

    $ext      = choice_media_extension($mime);
    $base     = pathinfo($_FILES['file']['name'] ?? 'media', PATHINFO_FILENAME);
    $base     = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$base);
    $base     = substr(trim($base, '_'), 0, 60) ?: 'media';
    $filename = $base . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $target   = $choiceDirAbs . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        choice_media_fail('Failed to save uploaded file.', 500);
    }
    @chmod($target, 0644);
    $relPath = $choiceDirRel . '/' . $filename;

    // Next sort_order
    $st = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tbl_choice_media WHERE choice_id = ?');
    $st->bind_param('i', $choiceId);
    $st->execute();
    $sortOrder = (int)($st->get_result()->fetch_row()[0] ?? 1);
    $st->close();

    $captionInsert = $caption !== '' ? mb_substr($caption, 0, 255) : null;
    $st = $conn->prepare(
        'INSERT INTO tbl_choice_media (choice_id, media_type, file_path, caption, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    $st->bind_param('isssi', $choiceId, $kind, $relPath, $captionInsert, $sortOrder);
    if (!$st->execute()) {
        @unlink($target);
        choice_media_fail('Failed to save media record.', 500);
    }
    $newId = (int)$st->insert_id;
    $st->close();

    audit_log($conn, 'choices', 'media_upload', 'choice_media', $newId, [
        'choice_id' => $choiceId,
        'media_type' => $kind,
        'file_path' => $relPath,
    ]);

    echo json_encode([
        'status' => 'success',
        'item' => [
            'id'         => $newId,
            'choice_id'  => $choiceId,
            'media_type' => $kind,
            'file_path'  => $relPath,
            'caption'    => $captionInsert,
            'sort_order' => $sortOrder,
        ],
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// DELETE
// -----------------------------------------------------------------------------
if ($action === 'delete') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) choice_media_fail('Missing id.');

    $st = $conn->prepare('SELECT choice_id, file_path FROM tbl_choice_media WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) choice_media_fail('Media item not found.', 404);

    $st = $conn->prepare('DELETE FROM tbl_choice_media WHERE id = ?');
    $st->bind_param('i', $id);
    $ok = $st->execute();
    $st->close();
    if (!$ok) choice_media_fail('Failed to delete media record.', 500);

    $absPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$row['file_path']);
    if (is_file($absPath)) @unlink($absPath);

    audit_log($conn, 'choices', 'media_delete', 'choice_media', $id, [
        'choice_id' => (int)$row['choice_id'],
        'file_path' => $row['file_path'],
    ]);

    echo json_encode(['status' => 'success']);
    exit;
}

// -----------------------------------------------------------------------------
// REORDER
// -----------------------------------------------------------------------------
if ($action === 'reorder') {
    $choiceId = (int)($data['choice_id'] ?? 0);
    $order    = $data['order'] ?? [];
    if ($choiceId <= 0) choice_media_fail('Missing choice_id.');
    if (!is_array($order) || empty($order)) choice_media_fail('Missing order.');

    $conn->begin_transaction();
    try {
        $st = $conn->prepare('UPDATE tbl_choice_media SET sort_order = ? WHERE id = ? AND choice_id = ?');
        $i = 1;
        foreach ($order as $rawId) {
            $mid = (int)$rawId;
            if ($mid <= 0) continue;
            $st->bind_param('iii', $i, $mid, $choiceId);
            $st->execute();
            $i++;
        }
        $st->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        choice_media_fail('Failed to reorder media.', 500);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// -----------------------------------------------------------------------------
// UPDATE caption
// -----------------------------------------------------------------------------
if ($action === 'update') {
    $id      = (int)($data['id'] ?? 0);
    $caption = trim((string)($data['caption'] ?? ''));
    if ($id <= 0) choice_media_fail('Missing id.');
    $captionInsert = $caption !== '' ? mb_substr($caption, 0, 255) : null;
    $st = $conn->prepare('UPDATE tbl_choice_media SET caption = ? WHERE id = ?');
    $st->bind_param('si', $captionInsert, $id);
    $ok = $st->execute();
    $st->close();
    if (!$ok) choice_media_fail('Failed to update caption.', 500);
    echo json_encode(['status' => 'success']);
    exit;
}

choice_media_fail('Unknown action.', 400);
