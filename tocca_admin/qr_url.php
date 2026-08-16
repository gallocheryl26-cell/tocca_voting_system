<?php
declare(strict_types=1);

/**
 * Public site-root + shareable URL builders.
 *
 * Configuration map for future developers:
 *   docs/HOSTING_PUBLIC_URLS.md
 *   tocca_admin/public_url_config.php (in-admin checklist)
 *
 * Prefer helpers in this file over hard-coded domains or long legacy paths
 * when generating public / QR / social links.
 */

require_once __DIR__ . '/choice_token.php';
require_once __DIR__ . '/includes/public_slugs.php';

/**
 * Read a configured base URL from tbl_config (trimmed, no trailing slash).
 */
function qr_config_base_url_from_db(?mysqli $conn, string $configKey): string
{
    if (!$conn instanceof mysqli || $configKey === '') {
        return '';
    }

    $stmt = $conn->prepare('SELECT config_value FROM tbl_config WHERE config_key = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('s', $configKey);
    $value = '';
    if ($stmt->execute()) {
        $stmt->bind_result($val);
        if ($stmt->fetch()) {
            $value = trim((string) $val);
        }
    }
    $stmt->close();

    return $value !== '' ? qr_normalize_site_root($value) : '';
}

/**
 * Force a configured URL down to the app site root (strip accidental form/file paths).
 */
function qr_normalize_site_root(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $url = rtrim($url, "/ \t");
    $suffixes = [
        '/nomination/nomination_form.php',
        '/nomination/nomination_tracking.php',
        '/nomination/generate_nomination_qr.php',
        '/e-vote-final-enhanced/index.php',
        '/e-vote-final-enhanced/v.php',
        '/e-vote-final-enhanced',
        '/tocca_admin',
        '/nomination',
        '/public_router.php',
    ];

    $changed = true;
    while ($changed) {
        $changed = false;
        $lower = strtolower($url);
        foreach ($suffixes as $suf) {
            $sufLower = strtolower($suf);
            if (str_ends_with($lower, $sufLower)) {
                $url = substr($url, 0, -strlen($suf));
                $url = rtrim($url, '/');
                $changed = true;
                break;
            }
        }
    }

    // Drop trailing .php scripts accidentally saved as "base"
    if (preg_match('#/[^/]+\.php$#i', $url)) {
        $url = preg_replace('#/[^/]+\.php$#i', '', $url) ?? $url;
        $url = rtrim($url, '/');
    }

    return $url;
}

/**
 * Auto-detect public site root (no /tocca_admin, no /e-vote path).
 */
function qr_auto_detect_site_root(): string
{
    if (defined('BASE_URL') && BASE_URL !== '') {
        $base = (string) BASE_URL;
        $pos = strpos($base, '/e-vote-final-enhanced');
        if ($pos !== false) {
            return rtrim(substr($base, 0, $pos), '/');
        }

        return rtrim($base, '/');
    }

    $schemeHeader = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    $scheme = $schemeHeader ?: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

    $hostHeader = $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? 'localhost';
    $hostParts = explode(',', (string) $hostHeader);
    $host = trim($hostParts[0]);

    $port = $_SERVER['HTTP_X_FORWARDED_PORT'] ?? $_SERVER['SERVER_PORT'] ?? null;
    if ($port && strpos($host, ':') === false) {
        $port = (int) $port;
        $isNonStandard = ($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80);
        if ($isNonStandard) {
            $host .= ':' . $port;
        }
    }

    $prefix = trim($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '', '/');
    $scriptDirRaw = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = ($scriptDirRaw === '.' || $scriptDirRaw === DIRECTORY_SEPARATOR)
        ? ''
        : '/' . trim($scriptDirRaw, '/');

    if ($prefix !== '') {
        $scriptDir = '/' . trim(preg_replace('#/+#', '/', $prefix . '/' . ltrim($scriptDir, '/')), '/');
    }

    if (str_ends_with($scriptDir, '/tocca_admin')) {
        $scriptDir = substr($scriptDir, 0, -strlen('/tocca_admin'));
    }
    if (str_ends_with($scriptDir, '/nomination')) {
        $scriptDir = substr($scriptDir, 0, -strlen('/nomination'));
    }

    $root = rtrim($scheme . '://' . $host . ($scriptDir === '' ? '' : $scriptDir), '/');

    return $root === '' ? rtrim($scheme . '://' . $host, '/') : $root;
}

/**
 * Base URL for establishment voting QRs, nominee portal, and saved poster links.
 */
function qr_voting_base_url(?mysqli $conn = null): string
{
    global $conn;

    if (function_exists('tocca_config')) {
        $fromFile = qr_normalize_site_root((string) (tocca_config('public_site_url') ?? ''));
        if ($fromFile !== '') {
            return $fromFile;
        }
    }

    $configured = qr_config_base_url_from_db($conn, 'voting_qr_base_url');
    if ($configured !== '') {
        return $configured;
    }

    return qr_normalize_site_root(qr_auto_detect_site_root());
}

/**
 * Best-effort LAN IPv4 so phone cameras can open links that were generated as localhost.
 */
function qr_detect_lan_ipv4(): string
{
    foreach (['SERVER_ADDR', 'LOCAL_ADDR'] as $key) {
        $ip = trim((string) ($_SERVER[$key] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && !in_array($ip, ['127.0.0.1', '0.0.0.0'], true)
        ) {
            return $ip;
        }
    }

    $sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
    if (is_resource($sock)) {
        $name = @stream_socket_get_name($sock, false);
        fclose($sock);
        if (is_string($name) && preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})/', $name, $m) === 1) {
            $ip = $m[1];
            if ($ip !== '127.0.0.1') {
                return $ip;
            }
        }
    }

    return '';
}

/**
 * Rewrite loopback hosts so a scanned QR can be opened from another device on the same network.
 */
function qr_scan_reachable_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return $url;
    }
    $host = strtolower((string) $parts['host']);
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        return $url;
    }
    $lan = qr_detect_lan_ipv4();
    if ($lan === '') {
        return $url;
    }
    $scheme = $parts['scheme'] ?? 'http';
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = $parts['path'] ?? '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . '://' . $lan . $port . $path . $query . $fragment;
}

/**
 * Base URL for event registration-form QRs (Events screen).
 */
function qr_nomination_base_url(?mysqli $conn = null): string
{
    global $conn;

    if (function_exists('tocca_config')) {
        $fromFile = qr_normalize_site_root((string) (tocca_config('public_site_url') ?? ''));
        if ($fromFile !== '') {
            return $fromFile;
        }
    }

    if (defined('NOMINATION_FORM_BASE_URL') && NOMINATION_FORM_BASE_URL !== '') {
        return qr_normalize_site_root((string) NOMINATION_FORM_BASE_URL);
    }

    $configured = qr_config_base_url_from_db($conn, 'nomination_qr_base_url');
    if ($configured !== '') {
        return $configured;
    }

    $votingConfigured = qr_config_base_url_from_db($conn, 'voting_qr_base_url');
    if ($votingConfigured !== '') {
        return $votingConfigured;
    }

    return qr_normalize_site_root(qr_auto_detect_site_root());
}

/**
 * Absolute asset base when a public short URL is serving an app folder
 * (keeps the browser address as /vote/, /register/, /track/, /vote/{business}/).
 */
function tocca_public_asset_base(): string
{
    if (!defined('TOCCA_ASSET_BASE')) {
        return '';
    }
    $base = trim((string) TOCCA_ASSET_BASE);
    if ($base === '') {
        return '';
    }

    return tocca_force_request_host(rtrim($base, '/') . '/');
}

/** Current request origin (honors ngrok / forwarded HTTPS). */
function tocca_request_origin(): string
{
    $fwdProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $https = $fwdProto === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $hostHeader = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = trim(explode(',', $hostHeader)[0]);

    return $scheme . '://' . $host;
}

/** Keep path/query, swap host to the URL the browser is actually using. */
function tocca_force_request_host(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return $url;
    }
    $origin = parse_url(tocca_request_origin());
    if (!is_array($origin) || empty($origin['host'])) {
        return $url;
    }
    if (strcasecmp((string) $parts['host'], (string) $origin['host']) === 0) {
        return $url;
    }
    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';

    return rtrim((string) ($origin['scheme'] ?? 'http') . '://' . $origin['host'], '/') . $path . $query;
}

/** Emit <base href="…"> so relative CSS/JS resolve under the real app folder. */
function tocca_emit_asset_base_tag(): void
{
    $base = tocca_public_asset_base();
    if ($base === '') {
        return;
    }
    echo '<base href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">' . "\n";
}

/**
 * Absolute (or same-folder relative) URL for a file under /nomination/.
 * Required for Location redirects and JS fetch when the browser address is /register or /track.
 */
function tocca_nomination_url(string $relativeFile): string
{
    $relativeFile = ltrim(str_replace('\\', '/', $relativeFile), '/');
    $assetBase = tocca_public_asset_base();
    if ($assetBase !== '') {
        return $assetBase . $relativeFile;
    }

    return $relativeFile;
}

/** Redirect to a nomination-folder script (safe under vanity /register|/track). */
function tocca_nomination_redirect(string $relativeFile): never
{
    header('Location: ' . tocca_nomination_url($relativeFile), true, 302);
    exit;
}

/** JS base for nomination API calls (always absolute when vanity routing is active). */
function tocca_emit_nomination_js_base(): void
{
    $base = tocca_public_asset_base();
    if ($base === '') {
        // Normal /nomination/*.php access — resolve against the current folder.
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $base = ($dir === '/' || $dir === '\\' || $dir === '.') ? '/' : (rtrim($dir, '/') . '/');
        if (!str_ends_with($base, '/nomination/') && !str_ends_with($base, '/nomination')) {
            // public_router.php SCRIPT_NAME fallback should not happen without TOCCA_ASSET_BASE
            $base = '';
        } else {
            $base = rtrim($base, '/') . '/';
        }
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    $scheme = $https ? 'https' : 'http';
    $hostHeader = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = trim(explode(',', $hostHeader)[0]);
    if ($base !== '' && !preg_match('#^https?://#i', $base)) {
        $base = $scheme . '://' . $host . '/' . ltrim(str_replace('\\', '/', $base), '/');
        $base = rtrim($base, '/') . '/';
    }
    echo '<script>window.TOCCA_NOMINATION_BASE=' . json_encode($base, JSON_UNESCAPED_SLASHES) . ';</script>' . "\n";
}

/**
 * Site-root absolute URL with a public path (e.g. /vote/, /register/, /vote/gemma-s-store/).
 */
function qr_public_path_url(string $path, ?mysqli $conn = null, bool $useNominationBase = false): string
{
    global $conn;
    $base = $useNominationBase ? qr_nomination_base_url($conn) : qr_voting_base_url($conn);
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    $path = rtrim($path, '/') . '/';

    return rtrim($base, '/') . $path;
}

/**
 * Full public registration URL: /register/
 * Uses the active event (or the given event_id for legacy fallback only).
 */
function qr_nomination_form_url(?mysqli $conn = null, int $eventId = 0): string
{
    global $conn;

    return qr_public_path_url('register', $conn, true);
}

/**
 * Main voting portal URL: /vote/
 */
function qr_vote_portal_url(?mysqli $conn = null, int $eventId = 0): string
{
    global $conn;

    return qr_public_path_url('vote', $conn, false);
}

/**
 * Registration tracking URL: /track/
 */
function qr_tracking_url(?mysqli $conn = null): string
{
    global $conn;

    return qr_public_path_url('track', $conn, true);
}

/**
 * Keep only a safe registration reference (blocks URL/script injection in ?ref=).
 */
function tocca_normalize_reference(string $raw): string
{
    $ref = strtoupper(trim($raw));
    $ref = preg_replace('/[^A-Z0-9\-]/', '', $ref) ?? '';
    if (strlen($ref) > 40) {
        $ref = substr($ref, 0, 40);
    }

    return $ref;
}

/** Public tracking URL, optionally with ?ref= */
function qr_tracking_url_with_ref(?mysqli $conn = null, string $ref = ''): string
{
    $url = rtrim(qr_tracking_url($conn), '/?');
    $url .= '/';
    $ref = tocca_normalize_reference($ref);
    if ($ref !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'ref=' . rawurlencode($ref);
    }
    if (function_exists('qr_scan_reachable_url')) {
        return qr_scan_reachable_url($url);
    }

    return $url;
}

/**
 * @deprecated alias — use qr_voting_base_url()
 */
function qr_public_base_url(?mysqli $conn = null): string
{
    return qr_voting_base_url($conn);
}

/**
 * Establishment voting URL: /vote/{business-slug}/
 * Falls back to token URL when slug cannot be allocated.
 */
function qr_vote_url_for_choice(int $choiceId, ?mysqli $conn = null): string
{
    global $conn;
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Database connection required for QR URL generation.');
    }

    $slug = public_slug_for_choice($conn, $choiceId);
    if ($slug !== '') {
        return qr_public_path_url('vote/' . rawurlencode($slug), $conn, false);
    }

    $token = choice_token_get_or_create($conn, $choiceId);

    return rtrim(qr_voting_base_url($conn), '/') . '/e-vote-final-enhanced/v.php?t=' . rawurlencode($token);
}

/**
 * Repair older business URLs that omitted /vote/ before the slug.
 */
function qr_normalize_business_vote_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return $url;
    }
    if (stripos($url, '/vote/') !== false || stripos($url, '/v.php') !== false) {
        return $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['path'])) {
        return $url;
    }
    $path = rtrim((string) $parts['path'], '/');
    $slash = strrpos($path, '/');
    if ($slash === false) {
        return $url;
    }
    $slug = substr($path, $slash + 1);
    $prefix = substr($path, 0, $slash);
    if ($slug === '' || in_array($slug, ['vote', 'register', 'track', 'e-vote-final-enhanced'], true)) {
        return $url;
    }
    $newPath = $prefix . '/vote/' . $slug . '/';
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    if ($host === '') {
        return $url;
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . '://' . $host . $port . $newPath . $query . $fragment;
}

/**
 * Legacy token deep-link (kept for regenerating old posters if needed).
 */
function qr_vote_token_url_for_choice(int $choiceId, ?mysqli $conn = null): string
{
    global $conn;
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Database connection required for QR URL generation.');
    }

    $token = choice_token_get_or_create($conn, $choiceId);

    return rtrim(qr_voting_base_url($conn), '/') . '/e-vote-final-enhanced/v.php?t=' . rawurlencode($token);
}

function qr_portal_url_for_token(string $token, ?mysqli $conn = null): string
{
    return rtrim(qr_voting_base_url($conn), '/') . '/nominee/portal.php?t=' . rawurlencode($token);
}

function qr_portal_url_for_choice(int $choiceId, ?mysqli $conn = null): string
{
    global $conn;
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Database connection required for portal URL generation.');
    }

    $token = choice_token_get_or_create($conn, $choiceId);

    return qr_portal_url_for_token($token, $conn);
}

function qr_png_public_url(string $relativePath, ?mysqli $conn = null): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return rtrim(qr_voting_base_url($conn), '/') . '/tocca_admin/' . $relativePath;
}
