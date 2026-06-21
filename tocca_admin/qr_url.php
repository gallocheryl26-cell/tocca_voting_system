<?php
declare(strict_types=1);

require_once __DIR__ . '/choice_token.php';

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

    return $value !== '' ? rtrim($value, '/ ') : '';
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

    $configured = qr_config_base_url_from_db($conn, 'voting_qr_base_url');
    if ($configured !== '') {
        return $configured;
    }

    return qr_auto_detect_site_root();
}

/**
 * Base URL for event nomination-form QRs (Events screen).
 */
function qr_nomination_base_url(?mysqli $conn = null): string
{
    global $conn;

    if (defined('NOMINATION_FORM_BASE_URL') && NOMINATION_FORM_BASE_URL !== '') {
        return rtrim((string) NOMINATION_FORM_BASE_URL, '/ ');
    }

    $configured = qr_config_base_url_from_db($conn, 'nomination_qr_base_url');
    if ($configured !== '') {
        return $configured;
    }

    return qr_auto_detect_site_root();
}

/**
 * Full public URL to nomination_form.php (optional event_id).
 * Base URL should be the site root (e.g. https://example.com/TOCCA), not the /nomination folder.
 */
function qr_nomination_form_url(?mysqli $conn = null, int $eventId = 0): string
{
    global $conn;

    $base = qr_nomination_base_url($conn);
    $path = str_ends_with(rtrim($base, '/'), '/nomination')
        ? '/nomination_form.php'
        : '/nomination/nomination_form.php';

    $url = rtrim($base, '/') . $path;
    if ($eventId > 0) {
        $url .= '?event_id=' . rawurlencode((string) $eventId);
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

function qr_vote_url_for_choice(int $choiceId, ?mysqli $conn = null): string
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
