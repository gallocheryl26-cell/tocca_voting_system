<?php
declare(strict_types=1);

require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/choice_token.php';

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing token.';
    exit;
}

$row = choice_token_lookup($conn, $token);
if (!$row) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Link unavailable</title></head><body style="font-family:Arial,sans-serif;padding:2rem;text-align:center;"><h1>This voting link is unavailable.</h1><p>Please contact the TOCCA team if you believe this is an error.</p></body></html>';
    exit;
}

$choiceId = (int)$row['choice_id'];
header('Location: qr_vote.php?choice_id=' . $choiceId, true, 302);
exit;
