<?php
declare(strict_types=1);

require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once __DIR__ . '/lib/voter_redirect.php';

date_default_timezone_set('Asia/Manila');

voter_session_start();

$voterId = (int)($_SESSION['voter_id'] ?? 0);
if ($voterId <= 0) {
    tocca_voter_redirect('index.php');
}

if (!voter_flow_is_voting_open($conn)) {
    tocca_voter_redirect('message.php');
}

if (!voter_flow_voter_has_access_code($conn, $voterId)) {
    tocca_voter_redirect('index.php');
}

try {
    if (voter_flow_voter_has_submitted($conn, $voterId)) {
        tocca_voter_redirect('thankyou.php');
    }
} catch (Throwable $e) {
    error_log('require_voter_page submitted check: ' . $e->getMessage());
}
