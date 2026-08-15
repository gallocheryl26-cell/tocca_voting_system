<?php
declare(strict_types=1);

/**
 * Domain landing: send visitors to the active event voting short link when possible.
 */
require_once __DIR__ . '/tocca_admin/db_connection.php';
require_once __DIR__ . '/tocca_admin/qr_url.php';

$target = qr_vote_portal_url($conn instanceof mysqli ? $conn : null);
header('Location: ' . $target, true, 302);
exit;
