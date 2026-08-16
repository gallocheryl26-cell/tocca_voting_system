<?php
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db_connection.php';
require_once dirname(__DIR__) . '/e-vote-final-enhanced/lib/vote_proof_helpers.php';

if (!($_SESSION['loggedin'] ?? false)) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unauthorized';
    exit;
}

$proofId = (int) ($_GET['proof_id'] ?? 0);
$scope = strtolower(trim((string) ($_GET['scope'] ?? 'final')));
if ($proofId <= 0) {
    http_response_code(400);
    echo 'Missing file.';
    exit;
}

vote_proof_ensure_schema($conn);

if ($scope === 'draft') {
    $stmt = $conn->prepare('SELECT file_path FROM tbl_draft_vote_proof WHERE proof_id = ? LIMIT 1');
} else {
    $stmt = $conn->prepare('SELECT file_path FROM tbl_vote_proof WHERE proof_id = ? LIMIT 1');
}
if (!$stmt) {
    http_response_code(500);
    echo 'Unavailable.';
    exit;
}
$stmt->bind_param('i', $proofId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$abs = vote_proof_fs_path((string) ($row['file_path'] ?? ''));
if ($abs === null) {
    http_response_code(404);
    echo 'File missing.';
    exit;
}

$mime = 'application/octet-stream';
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detected = $finfo->file($abs);
if (is_string($detected) && $detected !== '') {
    $mime = $detected;
}
$allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(403);
    echo 'Unsupported file.';
    exit;
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . (string) filesize($abs));
readfile($abs);
exit;
