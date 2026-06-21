<?php
declare(strict_types=1);

/**
 * Lightweight audit endpoint for client-side QR actions (e.g. establishment poster download).
 */
require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/audit_log.php';

header('Content-Type: application/json; charset=utf-8');

$choiceId = (int) ($_POST['choice_id'] ?? $_GET['choice_id'] ?? 0);
$action   = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'export')));

if ($choiceId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid choice_id.']);
    exit;
}

if (!in_array($action, ['export', 'download'], true)) {
    $action = 'export';
}

$choiceName = '';
$qrPath     = null;

$stmt = $conn->prepare(
    'SELECT choice_name, qr_code_path FROM tbl_choices WHERE choice_id = ? LIMIT 1'
);
if ($stmt) {
    $stmt->bind_param('i', $choiceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $choiceName = (string) ($row['choice_name'] ?? '');
        $stored     = trim((string) ($row['qr_code_path'] ?? ''));
        if ($stored !== '') {
            $qrPath = 'qrcodes/' . ltrim(basename($stored), '/');
        }
    }
}

try {
    audit_log_choice_qr($conn, $choiceId, $choiceName, $action, $qrPath, false);
    echo json_encode(['status' => 'success']);
} catch (Throwable $e) {
    error_log('log_qr_audit failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not record audit entry.']);
}
