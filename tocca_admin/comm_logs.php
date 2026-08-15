<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
date_default_timezone_set('Asia/Manila');

function fail($m, $c = 400): void
{
    http_response_code($c);
    echo json_encode(['status' => 'error', 'message' => $m]);
    exit;
}

function ok(array $p = []): void
{
    echo json_encode(['status' => 'success'] + $p);
    exit;
}

$nomination_id = isset($_GET['nomination_id']) ? (int) $_GET['nomination_id'] : 0;
if ($nomination_id <= 0) {
    fail('Missing nomination_id');
}

$meta = $conn->prepare('SELECT event_id FROM tbl_nominations WHERE nomination_id = ? LIMIT 1');
$meta->bind_param('i', $nomination_id);
$meta->execute();
$nom = $meta->get_result()->fetch_assoc();
$meta->close();
if (!$nom) {
    fail('Registration not found.', 404);
}

$event_id = (int) ($nom['event_id'] ?? 0);

$emailStmt = $conn->prepare(
    "SELECT a.answer
     FROM tbl_nomination_answers a
     INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
     WHERE a.nomination_id = ?
       AND LOWER(f.name) IN ('email', 'contact_email')
     ORDER BY FIELD(LOWER(f.name), 'email', 'contact_email')
     LIMIT 1"
);
$emailStmt->bind_param('i', $nomination_id);
$emailStmt->execute();
$emailRow = $emailStmt->get_result()->fetch_assoc();
$emailStmt->close();

$recipient_email = trim((string) ($emailRow['answer'] ?? ''));
if ($recipient_email === '') {
    ok(['rows' => []]);
}

$stmt = $conn->prepare(
    "SELECT id, created_at, sent_at, status, type,
            recipient_name, recipient_email, subject, LEFT(error_text, 500) AS error_text
     FROM tbl_comm_messages
     WHERE event_id = ? AND recipient_email = ?
     ORDER BY id DESC"
);
$stmt->bind_param('is', $event_id, $recipient_email);
$stmt->execute();
$res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ok(['rows' => $res]);
