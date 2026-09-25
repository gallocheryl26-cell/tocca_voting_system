<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';

voter_session_start();

$voterId = (int)($_SESSION['voter_id'] ?? 0);
$hasSession = $voterId > 0;
$votingOpen = voter_flow_is_voting_open($conn);
$ballotSubmitted = $hasSession && voter_flow_voter_has_submitted($conn, $voterId);
$hasAccessCode = $hasSession && voter_flow_voter_has_access_code($conn, $voterId);

echo json_encode([
    'status' => 'success',
    'authenticated' => $hasSession,
    'voter_id' => $voterId,
    'auth_provider' => $hasSession ? (string)($_SESSION['voter_auth_provider'] ?? 'legacy_mobile') : null,
    'voting_open' => $votingOpen,
    'has_access_code' => $hasAccessCode,
    'ballot_submitted' => $ballotSubmitted,
    'can_access_ballot' => $hasSession && $votingOpen && $hasAccessCode && !$ballotSubmitted,
]);
