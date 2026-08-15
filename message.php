<?php
declare(strict_types=1);

/**
 * Compatibility shim: old/short-URL relative redirects sometimes land here.
 * Forward to the real voting status page.
 */
$target = 'e-vote-final-enhanced/message.php';
$qs = $_SERVER['QUERY_STRING'] ?? '';
if (is_string($qs) && $qs !== '') {
    $target .= '?' . $qs;
}
header('Location: ' . $target, true, 302);
exit;
