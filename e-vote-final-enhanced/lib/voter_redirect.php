<?php
declare(strict_types=1);

/**
 * Safe redirect within the voter portal.
 * Public pages use short paths (/vote/thanks) instead of /e-vote-final-enhanced/*.php.
 */
function tocca_voter_page_map(): array
{
    return [
        'index.php' => '/vote/',
        'thankyou.php' => '/vote/thanks',
        'summarypoll.php' => '/vote/summary',
        'category.php' => '/vote/categories',
        'selected-category.php' => '/vote/ballot',
        'privacy_policy.php' => '/vote/privacy',
        'terms_and_conditions.php' => '/vote/terms',
        'message.php' => '/vote/message',
        'verification.php' => '/vote/verify',
    ];
}

function tocca_voter_web_root(): string
{
    $qr = dirname(__DIR__) . '/../tocca_admin/qr_url.php';
    if (!function_exists('tocca_request_app_web_root') && is_file($qr)) {
        require_once $qr;
    }
    $webRoot = function_exists('tocca_request_app_web_root') ? tocca_request_app_web_root() : '';
    $webRoot = rtrim(str_replace('\\', '/', (string) $webRoot), '/');
    if (str_ends_with($webRoot, '/e-vote-final-enhanced')) {
        $webRoot = substr($webRoot, 0, -strlen('/e-vote-final-enhanced'));
    }
    if ($webRoot === '/' || $webRoot === '.') {
        $webRoot = '';
    }

    return $webRoot;
}

function tocca_voter_public_url(string $page): string
{
    $page = ltrim(str_replace('\\', '/', $page), '/');
    $qPos = strpos($page, '?');
    $file = strtolower(basename($qPos === false ? $page : substr($page, 0, $qPos)));
    $query = $qPos === false ? '' : substr($page, $qPos);
    $map = tocca_voter_page_map();
    if (!isset($map[$file])) {
        if (defined('TOCCA_ASSET_BASE') && is_string(TOCCA_ASSET_BASE) && TOCCA_ASSET_BASE !== '') {
            return rtrim(TOCCA_ASSET_BASE, '/') . '/' . $page;
        }

        return $page;
    }

    return tocca_voter_web_root() . $map[$file] . $query;
}

function tocca_voter_href(string $page): string
{
    return htmlspecialchars(tocca_voter_public_url($page), ENT_QUOTES, 'UTF-8');
}

function tocca_voter_redirect(string $page, int $status = 302): void
{
    header('Location: ' . tocca_voter_public_url($page), true, $status);
    exit;
}

function tocca_emit_voter_url_helper(): void
{
    $root = tocca_voter_web_root();
    $map = [];
    foreach (tocca_voter_page_map() as $file => $path) {
        $map[$file] = $root . $path;
    }
    echo '<script>window.TOCCA_VOTER_URLS=' . json_encode($map, JSON_UNESCAPED_SLASHES)
        . ';window.toccaVoterUrl=function(page){page=String(page||\'\');var q=page.indexOf(\'?\');'
        . 'var file=(q===-1?page:page.slice(0,q)).split(\'/\').pop().toLowerCase();'
        . 'var query=q===-1?\'\':page.slice(q);'
        . 'return (window.TOCCA_VOTER_URLS&&window.TOCCA_VOTER_URLS[file])?window.TOCCA_VOTER_URLS[file]+query:page;'
        . '};window.toccaVoterGo=function(page){window.location.href=window.toccaVoterUrl(page);};</script>' . "\n";
}
