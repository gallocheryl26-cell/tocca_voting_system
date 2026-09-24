<?php
declare(strict_types=1);
/**
 * Public photo/video upload for an existing registration (Track My Registration).
 */
ob_start();
session_start();
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function json_ok(array $payload = []): void
{
    if (ob_get_level()) {
        ob_clean();
    }
    echo json_encode(['status' => 'success'] + $payload);
    exit;
}
function json_err(string $msg, int $code = 400, array $extra = []): void
{
    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg] + $extra);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('update_nomination_media: ' . $e->getMessage());
    json_err('Unable to save your photos. Please try again later.', 500);
});

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    json_err('The photo did not reach the server. Rename the file to letters and numbers only (no apostrophes), then try again.', 413);
}

if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    json_err('Invalid session. Please refresh the page and try again.', 419);
}

require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/nomination_field_helpers.php';
require_once __DIR__ . '/nomination_media_helpers.php';

if (!isset($conn) || !$conn instanceof mysqli) {
    json_err('Database unavailable.', 500);
}
$conn->set_charset('utf8mb4');

$reference = strtoupper(trim((string) ($_POST['nom_edit_ref'] ?? $_POST['reference_no'] ?? '')));
$verifyEmail = strtolower(trim((string) ($_POST['verify_email'] ?? '')));
if ($verifyEmail === '') {
    json_err('Please confirm your registration email.', 422);
}
if ($reference === '') {
    json_err('Missing reference number.', 422);
}

$nom = nf_fetch_nomination_by_reference($conn, $reference);
if (!$nom) {
    json_err('Reference number not found.', 404);
}

$nominationId = (int) $nom['nomination_id'];
$eventId = (int) ($nom['event_id'] ?? 0);
$status = strtolower((string) ($nom['status'] ?? ''));
if (!nf_nomination_is_editable($status)) {
    json_err('This registration can no longer be edited because it is already ' . ($nom['status'] ?: 'locked') . '.', 403);
}

$fieldDefs = nf_load_fields($conn, $eventId, ['active_only' => true]);
$storedEmail = '';
foreach ($fieldDefs as $def) {
    $role = (string) ($def['profile_role'] ?? '');
    $label = strtolower(($def['name'] ?? '') . ' ' . ($def['label'] ?? ''));
    $isEmail = ($role === 'email') || ($def['type'] ?? '') === 'email' || (bool) preg_match('/\bemail\b/', $label);
    if (!$isEmail) {
        continue;
    }
    $fid = (int) $def['id'];
    $q = $conn->prepare('SELECT answer FROM tbl_nomination_answers WHERE nomination_id = ? AND field_id = ? LIMIT 1');
    $q->bind_param('ii', $nominationId, $fid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    $ans = strtolower(trim((string) ($row['answer'] ?? '')));
    if ($ans !== '') {
        $storedEmail = $ans;
        break;
    }
}
if ($storedEmail === '' || !hash_equals($storedEmail, $verifyEmail)) {
    json_err('Email does not match this registration. Use the same email you submitted.', 403);
}

$conn->begin_transaction();
try {
    $saved = nomination_media_apply_posted($conn, $nominationId);
    if ($saved <= 0 && !array_key_exists('keep_media_ids', $_POST)) {
        throw new RuntimeException('Please choose at least one photo or video to upload.');
    }
    $newStatus = ($status === 'needs_info') ? 'pending' : $status;
    if ($newStatus !== $status) {
        $upd = $conn->prepare('UPDATE tbl_nominations SET status = ?, updated_at = NOW() WHERE nomination_id = ?');
        if ($upd) {
            $upd->bind_param('si', $newStatus, $nominationId);
            $upd->execute();
            $upd->close();
        }
    } else {
        $conn->query('UPDATE tbl_nominations SET updated_at = NOW() WHERE nomination_id = ' . $nominationId);
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    $msg = $e->getMessage();
    $low = strtolower($msg);
    if (str_contains($low, 'at most') || str_contains($low, 'too large') || str_contains($low, 'unsupported')
        || str_contains($low, 'please choose') || str_contains($low, 'already has')
        || str_contains($low, 'partly received') || str_contains($low, 'failed to upload')
        || str_contains($low, 'larger than')) {
        json_err($msg, 422);
    }
    throw $e;
}

require_once __DIR__ . '/../tocca_admin/qr_url.php';
json_ok([
    'reference_no' => $reference,
    'nomination_status' => $newStatus ?? $status,
    'media_uploaded' => $saved,
    'media' => nomination_media_list($conn, $nominationId),
    'redirect' => qr_tracking_url_with_ref($conn, $reference),
]);
