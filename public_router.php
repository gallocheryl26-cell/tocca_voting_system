<?php
declare(strict_types=1);

/**
 * Public short-URL front controller.
 *
 * Keeps the browser address as:
 *   /vote/ | /register/ | /track/ | /vote/{business-slug}/
 * while loading the real app pages (assets via <base href>).
 *
 * Configure / copy links in admin: Public Share Links (public_url_config.php)
 * Docs: docs/HOSTING_PUBLIC_URLS.md
 */

require_once __DIR__ . '/tocca_admin/db_connection.php';
require_once __DIR__ . '/tocca_admin/qr_url.php';
require_once __DIR__ . '/tocca_admin/choice_token.php';
require_once __DIR__ . '/tocca_admin/includes/public_slugs.php';

function public_router_fail(int $code, string $title, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMsg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $safeTitle . '</title></head>'
        . '<body style="font-family:Arial,sans-serif;padding:2rem;text-align:center;max-width:32rem;margin:auto;">'
        . '<h1 style="font-size:1.35rem;">' . $safeTitle . '</h1>'
        . '<p style="color:#444;line-height:1.5;">' . $safeMsg . '</p>'
        . '</body></html>';
    exit;
}

/** @return array<string,mixed>|null */
function public_router_active_event(mysqli $conn): ?array
{
    $res = $conn->query(
        'SELECT event_id, event_name, year, is_active, is_archived, nomination_start, nomination_end, voting_start, voting_end
         FROM tbl_events
         WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0
         ORDER BY year DESC, event_id DESC
         LIMIT 1'
    );
    if (!$res instanceof mysqli_result) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();

    return $row ?: null;
}

/**
 * Prepare a real app script to run under a short public URL.
 * Returns the absolute path so the caller can require it in file scope
 * (included pages must not run inside a function, or they can wipe $conn).
 */
function public_router_prepare(string $absoluteScript, string $assetBaseUrl): string
{
    global $conn;

    if (!is_file($absoluteScript)) {
        public_router_fail(500, 'Page unavailable', 'The requested page could not be loaded.');
    }
    if (!defined('TOCCA_ASSET_BASE')) {
        define('TOCCA_ASSET_BASE', rtrim($assetBaseUrl, '/') . '/');
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        $conn = $GLOBALS['conn'] ?? null;
    }
    if ($conn instanceof mysqli) {
        $GLOBALS['conn'] = $conn;
    }

    $dir = dirname($absoluteScript);
    if ($dir !== '' && is_dir($dir)) {
        chdir($dir);
    }

    return $absoluteScript;
}

$route = strtolower(trim((string) ($_GET['route'] ?? '')));
$route = preg_replace('/[^a-z]/', '', $route) ?? '';
$allowedRoutes = ['track', 'vote', 'register', 'business', 'b'];
if ($route === '' || !in_array($route, $allowedRoutes, true)) {
    public_router_fail(404, 'Link not found', 'This public link is invalid or no longer available.');
}

$slug = public_slug_normalize((string) ($_GET['slug'] ?? ''));

$siteRoot = qr_voting_base_url($conn);
$nominationRoot = qr_nomination_base_url($conn);

if ($route === 'track') {
    require public_router_prepare(
        __DIR__ . '/nomination/nomination_tracking.php',
        $nominationRoot . '/nomination'
    );
    exit;
}

if ($route === 'vote') {
    $event = public_router_active_event($conn);
    if (!$event) {
        public_router_fail(404, 'Voting unavailable', 'Voting is not open right now.');
    }
    require public_router_prepare(
        __DIR__ . '/e-vote-final-enhanced/index.php',
        $siteRoot . '/e-vote-final-enhanced'
    );
    exit;
}

if ($route === 'register') {
    $event = public_router_active_event($conn);
    if (!$event) {
        public_router_fail(404, 'Registration unavailable', 'Registration is not open right now.');
    }
    $_GET['event_id'] = (string) (int) ($event['event_id'] ?? 0);
    require public_router_prepare(
        __DIR__ . '/nomination/nomination_form.php',
        $nominationRoot . '/nomination'
    );
    exit;
}

if ($route === 'business' || $route === 'b') {
    if ($slug === '' || in_array($slug, public_slug_reserved(), true)) {
        public_router_fail(404, 'Link not found', 'This public link is invalid or no longer available.');
    }
    $choice = public_slug_lookup_choice($conn, $slug);
    if (!$choice) {
        public_router_fail(404, 'Voting link unavailable', 'This establishment voting link is invalid or no longer available.');
    }
    $choiceId = (int) ($choice['choice_id'] ?? 0);
    // Skip v.php redirect so the short /vote/{slug}/ address stays in the browser.
    $_GET['choice_id'] = (string) $choiceId;
    require public_router_prepare(
        __DIR__ . '/e-vote-final-enhanced/qr_vote.php',
        $siteRoot . '/e-vote-final-enhanced'
    );
    exit;
}

public_router_fail(404, 'Link not found', 'This public link is invalid or no longer available.');
