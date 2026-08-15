<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once 'connection.php';
require_once 'voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once __DIR__ . '/lib/vote_proof_helpers.php';

$voterId = voter_require_authenticated();
voter_flow_json_ballot_denied($conn, $voterId);

$questionId = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;
if ($questionId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing question.']);
    exit;
}

if (empty($_FILES['proof']) || !is_array($_FILES['proof'])) {
    echo json_encode(['status' => 'error', 'message' => 'No image uploaded.']);
    exit;
}

try {
    $saved = vote_proof_save_upload($conn, $voterId, $questionId, $_FILES['proof']);
    echo json_encode([
        'status' => 'success',
        'proof'  => $saved,
    ]);
} catch (Throwable $e) {
    error_log('upload_vote_proof: ' . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage() ?: 'Upload failed.',
    ]);
}
