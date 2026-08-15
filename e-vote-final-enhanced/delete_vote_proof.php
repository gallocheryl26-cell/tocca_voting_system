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

$data = json_decode(file_get_contents('php://input'), true);
$proofId = isset($data['proof_id']) ? (int)$data['proof_id'] : 0;

if ($proofId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing proof id.']);
    exit;
}

$deleted = vote_proof_delete_draft($conn, $voterId, $proofId);
echo json_encode([
    'status'  => $deleted ? 'success' : 'error',
    'message' => $deleted ? 'Proof removed.' : 'Proof not found or already submitted.',
]);
