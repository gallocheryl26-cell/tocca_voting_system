<?php
declare(strict_types=1);

/**
 * Domain landing: serve the voting portal at "/" with no HTTP redirect.
 * (Hostinger Page Speed tests the domain root — a 302 to /vote hurts the score.)
 */
$_GET['route'] = 'vote';
require __DIR__ . '/public_router.php';
