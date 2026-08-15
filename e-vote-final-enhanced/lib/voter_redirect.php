<?php
declare(strict_types=1);

/**
 * Safe redirect within the voter portal.
 * When pages are served under short URLs (/vote), relative Location headers
 * would resolve to the site root (e.g. /TOCCA.../message.php) and 404.
 */
function tocca_voter_redirect(string $page, int $status = 302): void
{
    $page = ltrim($page, '/');
    if (defined('TOCCA_ASSET_BASE') && is_string(TOCCA_ASSET_BASE) && TOCCA_ASSET_BASE !== '') {
        $target = rtrim(TOCCA_ASSET_BASE, '/') . '/' . $page;
    } else {
        $target = $page;
    }
    header('Location: ' . $target, true, $status);
    exit;
}
