<?php
declare(strict_types=1);

/**
 * Proof-of-purchase image uploads for voter ballot answers.
 */

if (!defined('VOTE_PROOF_MAX_FILES')) {
    define('VOTE_PROOF_MAX_FILES', 5);
}
if (!defined('VOTE_PROOF_MAX_BYTES')) {
    define('VOTE_PROOF_MAX_BYTES', 5 * 1024 * 1024);
}
if (!defined('VOTE_PROOF_DIR_FS')) {
    define('VOTE_PROOF_DIR_FS', dirname(__DIR__) . '/uploads/vote_proof');
}
if (!defined('VOTE_PROOF_DIR_WEB')) {
    define('VOTE_PROOF_DIR_WEB', 'uploads/vote_proof');
}

if (!function_exists('vote_proof_ensure_schema')) {
    function vote_proof_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS tbl_draft_vote_proof (
                proof_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                voters_id INT UNSIGNED NOT NULL,
                question_id INT UNSIGNED NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (proof_id),
                KEY idx_draft_proof_voter_q (voters_id, question_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS tbl_vote_proof (
                proof_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                voters_id INT UNSIGNED NOT NULL,
                question_id INT UNSIGNED NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                vote_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (proof_id),
                KEY idx_vote_proof_voter_q (voters_id, question_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('vote_proof_ensure_storage')) {
    function vote_proof_ensure_storage(int $voterId): ?string
    {
        $root = VOTE_PROOF_DIR_FS;
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
        $dir = $root . DIRECTORY_SEPARATOR . $voterId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        return $dir;
    }
}

if (!function_exists('vote_proof_web_url')) {
    function vote_proof_web_url(string $relativePath): string
    {
        $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        if ($base === '' || $base === '.') {
            return $rel;
        }
        return $base . '/' . $rel;
    }
}

if (!function_exists('vote_proof_allowed_mimes')) {
    /** @return array<string,string> */
    function vote_proof_allowed_mimes(): array
    {
        return [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
    }
}

if (!function_exists('vote_proof_count_draft')) {
    function vote_proof_count_draft(mysqli $conn, int $voterId, int $questionId): int
    {
        vote_proof_ensure_schema($conn);
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS cnt FROM tbl_draft_vote_proof WHERE voters_id = ? AND question_id = ?'
        );
        $stmt->bind_param('ii', $voterId, $questionId);
        $stmt->execute();
        $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        return $cnt;
    }
}

if (!function_exists('vote_proof_count_final')) {
    function vote_proof_count_final(mysqli $conn, int $voterId, int $questionId): int
    {
        vote_proof_ensure_schema($conn);
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS cnt FROM tbl_vote_proof WHERE voters_id = ? AND question_id = ?'
        );
        $stmt->bind_param('ii', $voterId, $questionId);
        $stmt->execute();
        $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        return $cnt;
    }
}

if (!function_exists('vote_proof_load_for_question')) {
    /**
     * @return list<array{proof_id:int,file_path:string,url:string,is_final:bool}>
     */
    function vote_proof_load_for_question(mysqli $conn, int $voterId, int $questionId): array
    {
        vote_proof_ensure_schema($conn);
        $items = [];

        $stmt = $conn->prepare(
            'SELECT proof_id, file_path FROM tbl_vote_proof WHERE voters_id = ? AND question_id = ? ORDER BY proof_id ASC'
        );
        $stmt->bind_param('ii', $voterId, $questionId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $path = (string)($row['file_path'] ?? '');
            $items[] = [
                'proof_id'  => (int)$row['proof_id'],
                'file_path' => $path,
                'url'       => vote_proof_web_url($path),
                'is_final'  => true,
            ];
        }
        $stmt->close();

        if ($items !== []) {
            return $items;
        }

        $stmt = $conn->prepare(
            'SELECT proof_id, file_path FROM tbl_draft_vote_proof WHERE voters_id = ? AND question_id = ? ORDER BY proof_id ASC'
        );
        $stmt->bind_param('ii', $voterId, $questionId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $path = (string)($row['file_path'] ?? '');
            $items[] = [
                'proof_id'  => (int)$row['proof_id'],
                'file_path' => $path,
                'url'       => vote_proof_web_url($path),
                'is_final'  => false,
            ];
        }
        $stmt->close();

        return $items;
    }
}

if (!function_exists('vote_proof_save_upload')) {
    /**
     * @param array<string,mixed> $file
     * @return array{proof_id:int,file_path:string,url:string}
     */
    function vote_proof_save_upload(mysqli $conn, int $voterId, int $questionId, array $file): array
    {
        vote_proof_ensure_schema($conn);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed. Please try again.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > VOTE_PROOF_MAX_BYTES) {
            throw new RuntimeException('Image must be 5 MB or smaller.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $extMap = vote_proof_allowed_mimes();
        if (!isset($extMap[$mime])) {
            throw new RuntimeException('Only PNG, JPG, WEBP, and GIF images are allowed.');
        }

        $existing = vote_proof_count_draft($conn, $voterId, $questionId);
        $existing += vote_proof_count_final($conn, $voterId, $questionId);
        if ($existing >= VOTE_PROOF_MAX_FILES) {
            throw new RuntimeException('You can upload at most ' . VOTE_PROOF_MAX_FILES . ' proof images.');
        }

        $dir = vote_proof_ensure_storage($voterId);
        if ($dir === null) {
            throw new RuntimeException('Could not prepare upload folder.');
        }

        $rawName = (string)($file['name'] ?? 'proof');
        $base = pathinfo($rawName, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$base);
        $base = substr(trim((string)$base, '_'), 0, 40) ?: 'proof';
        $filename = sprintf('%s_q%d_%s.%s', $base, $questionId, bin2hex(random_bytes(4)), $extMap[$mime]);
        $abs = $dir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmp, $abs)) {
            throw new RuntimeException('Failed to save uploaded image.');
        }
        @chmod($abs, 0644);

        $webRel = trim(VOTE_PROOF_DIR_WEB, '/\\') . '/' . $voterId . '/' . $filename;

        $stmt = $conn->prepare(
            'INSERT INTO tbl_draft_vote_proof (voters_id, question_id, file_path) VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iis', $voterId, $questionId, $webRel);
        $stmt->execute();
        $proofId = (int)$conn->insert_id;
        $stmt->close();

        return [
            'proof_id'  => $proofId,
            'file_path' => $webRel,
            'url'       => vote_proof_web_url($webRel),
        ];
    }
}

if (!function_exists('vote_proof_delete_draft')) {
    function vote_proof_delete_draft(mysqli $conn, int $voterId, int $proofId): bool
    {
        vote_proof_ensure_schema($conn);
        $stmt = $conn->prepare(
            'SELECT file_path FROM tbl_draft_vote_proof WHERE proof_id = ? AND voters_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $proofId, $voterId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }

        $stmt = $conn->prepare('DELETE FROM tbl_draft_vote_proof WHERE proof_id = ? AND voters_id = ?');
        $stmt->bind_param('ii', $proofId, $voterId);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();

        if ($deleted) {
            $fs = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', (string)$row['file_path']), '/');
            if (is_file($fs)) {
                @unlink($fs);
            }
        }
        return $deleted;
    }
}

if (!function_exists('vote_proof_clear_drafts_for_question')) {
    function vote_proof_clear_drafts_for_question(mysqli $conn, int $voterId, int $questionId): void
    {
        vote_proof_ensure_schema($conn);
        $stmt = $conn->prepare(
            'SELECT proof_id, file_path FROM tbl_draft_vote_proof WHERE voters_id = ? AND question_id = ?'
        );
        $stmt->bind_param('ii', $voterId, $questionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $del = $conn->prepare('DELETE FROM tbl_draft_vote_proof WHERE voters_id = ? AND question_id = ?');
        $del->bind_param('ii', $voterId, $questionId);
        $del->execute();
        $del->close();

        foreach ($rows as $row) {
            $fs = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', (string)$row['file_path']), '/');
            if (is_file($fs)) {
                @unlink($fs);
            }
        }
    }
}

if (!function_exists('vote_proof_promote_drafts')) {
    function vote_proof_promote_drafts(mysqli $conn, int $voterId, int $questionId): void
    {
        vote_proof_ensure_schema($conn);

        $stmt = $conn->prepare(
            'SELECT proof_id, file_path FROM tbl_draft_vote_proof WHERE voters_id = ? AND question_id = ? ORDER BY proof_id ASC'
        );
        $stmt->bind_param('ii', $voterId, $questionId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($rows === []) {
            return;
        }

        $insert = $conn->prepare(
            'INSERT INTO tbl_vote_proof (voters_id, question_id, file_path, vote_at) VALUES (?, ?, ?, NOW())'
        );
        foreach ($rows as $row) {
            $path = (string)$row['file_path'];
            $insert->bind_param('iis', $voterId, $questionId, $path);
            $insert->execute();
        }
        $insert->close();

        vote_proof_clear_drafts_for_question($conn, $voterId, $questionId);
    }
}
