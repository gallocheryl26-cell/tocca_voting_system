<?php
declare(strict_types=1);

require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';

date_default_timezone_set('Asia/Manila');

voter_session_start();

$voterId = (int)($_SESSION['voter_id'] ?? 0);
if ($voterId <= 0) {
    header('Location: index.php');
    exit;
}

if (!voter_flow_is_voting_open($conn)) {
    header('Location: message.php');
    exit;
}

if (!voter_flow_voter_has_access_code($conn, $voterId)) {
    header('Location: index.php');
    exit;
}

if (voter_flow_voter_has_submitted($conn, $voterId)) {
    header('Location: thankyou.php');
    exit;
}
