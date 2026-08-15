<?php
declare(strict_types=1);
/**
 * Public update endpoint for an existing registration (by reference + email).
 */
session_start();
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function json_ok(array $payload = []): void
{
    echo json_encode(['status' => 'success'] + $payload);
    exit;
}
function json_err(string $msg, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg] + $extra);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('update_nomination: ' . $e->getMessage());
    json_err('Unable to update your registration. Please try again later.', 500);
});

if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    json_err('Invalid session. Please refresh the page and try again.', 419);
}

require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/includes/establishment_type_event_helpers.php';
require_once __DIR__ . '/nomination_field_helpers.php';
require_once __DIR__ . '/email_check.php';

if (!isset($conn) || !$conn instanceof mysqli) {
    json_err('Database unavailable.', 500);
}
$conn->set_charset('utf8mb4');
et_ensure_m2m_schema($conn);

$reference = strtoupper(trim((string) ($_POST['reference_no'] ?? '')));
$verifyEmail = strtolower(trim((string) ($_POST['verify_email'] ?? '')));
if ($reference === '' || $verifyEmail === '') {
    json_err('Reference number and registration email are required.', 422);
}

$st = $conn->prepare('SELECT nomination_id, event_id, reference_no, status FROM tbl_nominations WHERE reference_no = ? LIMIT 1');
$st->bind_param('s', $reference);
$st->execute();
$nom = $st->get_result()->fetch_assoc();
$st->close();
if (!$nom) {
    json_err('Reference number not found.', 404);
}

$nominationId = (int) $nom['nomination_id'];
$eventId = (int) $nom['event_id'];
$status = strtolower((string) ($nom['status'] ?? ''));
if (!in_array($status, ['pending', 'submitted', 'needs_info'], true)) {
    json_err('This registration can no longer be edited because it is already ' . ($nom['status'] ?: 'locked') . '.', 403);
}

$fieldDefs = [];
foreach (nf_load_fields($conn, $eventId, ['active_only' => true]) as $r) {
    $fieldDefs[] = [
        'id'             => (int) $r['id'],
        'name'           => (string) ($r['name'] ?? ''),
        'label'          => (string) ($r['label'] ?? ''),
        'type'           => (string) ($r['type'] ?? 'text'),
        'is_required'    => !empty($r['is_required']),
        'profile_role'   => (string) ($r['profile_role'] ?? 'custom'),
        'validation_arr' => is_array($r['validation_arr'] ?? null) ? $r['validation_arr'] : [],
    ];
}

// Verify email against stored answer
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

$establishment_type_ids = [];
if (isset($_POST['establishment_type_ids'])) {
    $establishment_type_ids = et_parse_type_ids($_POST['establishment_type_ids']);
}
if ($establishment_type_ids === []) {
    $establishment_type_ids = et_get_nomination_type_ids($conn, $nominationId);
}
if ($establishment_type_ids === []) {
    json_err('Please select at least one business category.', 422);
}
if (!et_types_belong_to_event($conn, $establishment_type_ids, $eventId)) {
    json_err('One or more business categories are invalid for this event.', 422);
}

$selected_awards = [];
if (!empty($_POST['selected_awards_json'])) {
    $arr = json_decode((string) $_POST['selected_awards_json'], true);
    if (is_array($arr)) {
        $selected_awards = array_values(array_filter(array_map('intval', $arr)));
    }
}
if ($selected_awards === []) {
    json_err('Please select at least one award.', 422);
}
if (!et_awards_belong_to_event($conn, $selected_awards, $eventId)) {
    json_err('One or more selected awards are not valid for this event.', 422);
}
if (!et_awards_match_establishment_types($conn, $selected_awards, $establishment_type_ids, $eventId)) {
    json_err('One or more selected awards are not allowed for your business categories.', 422);
}

// Reuse submit helpers via include of function definitions by copying minimal ones
function posted_value_for_field_update(array $def)
{
    $fid  = (int) $def['id'];
    $name = trim((string) ($def['name'] ?? ''));
    $type = (string) $def['type'];
    if ($type === 'file') {
        if ($name !== '' && !empty($_FILES[$name]) && $_FILES[$name]['error'] === UPLOAD_ERR_OK) {
            return ['file' => $_FILES[$name], 'keep' => null];
        }
        if (isset($_FILES['files']['error'][$fid]) && $_FILES['files']['error'][$fid] === UPLOAD_ERR_OK) {
            return [
                'file' => [
                    'name'     => $_FILES['files']['name'][$fid],
                    'type'     => $_FILES['files']['type'][$fid],
                    'tmp_name' => $_FILES['files']['tmp_name'][$fid],
                    'error'    => $_FILES['files']['error'][$fid],
                    'size'     => $_FILES['files']['size'][$fid],
                ],
                'keep' => null,
            ];
        }
        $keepName = $name !== '' ? ($name . '_keep') : ('files_keep[' . $fid . ']');
        $keep = $name !== '' ? ($_POST[$name . '_keep'] ?? null) : ($_POST['files_keep'][$fid] ?? null);
        return ['file' => null, 'keep' => $keep];
    }
    if ($name !== '') {
        return $_POST[$name] ?? null;
    }
    return $_POST['fields'][$fid] ?? null;
}

function save_uploaded_file_update(array $file, int $nominationId): ?string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($extMap[$mime])) {
        return null;
    }
    $yearDirFs = __DIR__ . '/uploads/nominations/' . date('Y');
    if (!is_dir($yearDirFs) && !mkdir($yearDirFs, 0775, true) && !is_dir($yearDirFs)) {
        throw new RuntimeException('Failed to create upload folder.');
    }
    $safeBase = preg_replace('/[^a-z0-9]+/i', '-', strtolower(pathinfo($file['name'], PATHINFO_FILENAME)));
    $newName  = sprintf('%s-%d-%s.%s', $safeBase ?: 'file', $nominationId, bin2hex(random_bytes(6)), $extMap[$mime]);
    $destFs   = $yearDirFs . '/' . $newName;
    if (!move_uploaded_file($file['tmp_name'], $destFs)) {
        throw new RuntimeException('Failed to move uploaded file.');
    }
    $scriptName = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/nomination'));
    return rtrim($scriptName, '/') . '/uploads/nominations/' . date('Y') . '/' . $newName;
}

$errors = [];
foreach ($fieldDefs as $def) {
    if (empty($def['is_required'])) {
        continue;
    }
    $val = posted_value_for_field_update($def);
    if (($def['type'] ?? '') === 'file') {
        $hasNew = is_array($val) && !empty($val['file']) && ($val['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
        $hasKeep = is_array($val) && !empty($val['keep']);
        if (!$hasNew && !$hasKeep) {
            $errors[] = ($def['label'] ?: 'File') . ' is required.';
        }
        continue;
    }
    if (is_array($val)) {
        if (count(array_filter($val, static fn($v) => trim((string) $v) !== '')) === 0) {
            $errors[] = ($def['label'] ?: 'Field') . ' is required.';
        }
    } elseif (trim((string) ($val ?? '')) === '') {
        $errors[] = ($def['label'] ?: 'Field') . ' is required.';
    }
}

foreach ($fieldDefs as $def) {
    if (($def['type'] ?? '') !== 'file') {
        continue;
    }
    $val = posted_value_for_field_update($def);
    if (!is_array($val) || empty($val['file']) || ($val['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        continue;
    }
    $tmp = (string) ($val['file']['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        continue;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        $errors[] = ($def['label'] ?: 'File') . ' must be an image file (PNG, JPG, or WEBP).';
    }
}
if ($errors !== []) {
    json_err('Validation failed.', 422, ['errors' => $errors]);
}

$conn->begin_transaction();
try {
    et_set_nomination_types($conn, $nominationId, $establishment_type_ids);

    $upsert = $conn->prepare(
        'INSERT INTO tbl_nomination_answers (nomination_id, field_id, answer)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE answer = VALUES(answer)'
    );
    if (!$upsert) {
        throw new RuntimeException($conn->error);
    }

    foreach ($fieldDefs as $def) {
        $fid = (int) $def['id'];
        $type = (string) $def['type'];
        $val = posted_value_for_field_update($def);

        if ($type === 'file') {
            $answer = null;
            if (is_array($val) && !empty($val['file']) && ($val['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $answer = save_uploaded_file_update($val['file'], $nominationId);
                if ($answer === null) {
                    throw new RuntimeException('Invalid image upload for ' . ($def['label'] ?: 'file'));
                }
            } elseif (is_array($val) && !empty($val['keep'])) {
                $answer = trim((string) $val['keep']);
            }
            if ($answer !== null && $answer !== '') {
                $upsert->bind_param('iis', $nominationId, $fid, $answer);
                if (!$upsert->execute()) {
                    throw new RuntimeException($upsert->error);
                }
            }
            continue;
        }

        if (is_array($val)) {
            $clean = array_values(array_filter(array_map(static fn($v) => trim((string) $v), $val), static fn($s) => $s !== ''));
            $answer = json_encode($clean, JSON_UNESCAPED_UNICODE);
        } else {
            $answer = trim((string) ($val ?? ''));
        }
        if ($answer !== '' && $answer !== '[]') {
            $upsert->bind_param('iis', $nominationId, $fid, $answer);
            if (!$upsert->execute()) {
                throw new RuntimeException($upsert->error);
            }
        }
    }
    $upsert->close();

    $del = $conn->prepare('DELETE FROM tbl_nomination_questions WHERE nomination_id = ?');
    $del->bind_param('i', $nominationId);
    $del->execute();
    $del->close();
    $insQ = $conn->prepare('INSERT INTO tbl_nomination_questions (nomination_id, question_id) VALUES (?, ?)');
    foreach ($selected_awards as $qid) {
        $qid = (int) $qid;
        $insQ->bind_param('ii', $nominationId, $qid);
        $insQ->execute();
    }
    $insQ->close();

    // After an update from Needs Information, send back to pending review.
    $newStatus = ($status === 'needs_info') ? 'pending' : $status;
    if ($newStatus !== $status) {
        $upd = $conn->prepare('UPDATE tbl_nominations SET status = ?, updated_at = NOW() WHERE nomination_id = ?');
        if ($upd) {
            $upd->bind_param('si', $newStatus, $nominationId);
            $upd->execute();
            $upd->close();
        }
    } else {
        $conn->query('UPDATE tbl_nominations SET updated_at = NOW() WHERE nomination_id = ' . (int) $nominationId);
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    throw $e;
}

json_ok([
    'reference_no' => $reference,
    'status'       => $newStatus ?? $status,
    'redirect'     => 'nomination_tracking.php?ref=' . rawurlencode($reference),
]);
