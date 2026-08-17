<?php
require_once __DIR__ . '/db_connection.php';

if (!function_exists('h')) {
    function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('getConfig')) {

    function config_invalidate_cache(): void
    {
        global $configCacheVersion;
        $configCacheVersion = (int) ($configCacheVersion ?? 0) + 1;
    }

    /** @return array<string, string> */
    function config_load_all(mysqli $conn): array
    {
        static $cache = null;
        static $loadedVersion = -1;
        global $configCacheVersion;
        $version = (int) ($configCacheVersion ?? 0);
        if (is_array($cache) && $loadedVersion === $version) {
            return $cache;
        }

        $cache = [];
        $loadedVersion = $version;
        $res = $conn->query('SELECT config_key, config_value FROM tbl_config');
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $cache[(string) ($row['config_key'] ?? '')] = (string) ($row['config_value'] ?? '');
            }
            $res->free();
        }

        return $cache;
    }

    function getConfig(string $key, string $default = ''): string
    {
        global $conn;
        if (!$conn instanceof mysqli) {
            return $default;
        }

        $all = config_load_all($conn);
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }
}

if (!function_exists('resolveAssetPath')) {
    function resolveAssetPath(?string $storedPath): string {
        if (!$storedPath) return '';

        // Absolute URL or root-relative path
        if (preg_match('~^(https?:)?//|^/~', $storedPath)) return $storedPath;

        // Normalize: remove any leading slash
        $storedPath = ltrim($storedPath, '/');

        // Detect if current script runs inside /tocca_admin/
        $script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        $isAdmin = (strpos($script, '/tocca_admin/') !== false);

        // If we're already in /tocca_admin, return as-is; else prefix to reach admin dir
        return $isAdmin ? $storedPath : ('../tocca_admin/' . $storedPath);
    }
}

if (!function_exists('voter_header_logo_fallback_raw')) {
    /** Bundled admin banner that exists on disk (not a deleted upload). */
    function voter_header_logo_fallback_raw(): string
    {
        $adminImg = __DIR__ . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR;
        foreach (['tocca_banner.jpg', 'tatak ormoc logo.png'] as $file) {
            if (is_file($adminImg . $file)) {
                return 'img/' . $file;
            }
        }

        return 'img/tocca_banner.jpg';
    }
}

if (!function_exists('voter_header_logo_absolute_path')) {
    function voter_header_logo_absolute_path(string $stored): ?string
    {
        $stored = trim($stored);
        if ($stored === '' || preg_match('#^(https?:)?//#i', $stored) || str_starts_with($stored, '/')) {
            return null;
        }

        $root = str_replace('\\', '/', dirname(__DIR__));
        $norm = ltrim(str_replace('\\', '/', $stored), '/');
        $candidates = [];

        if (preg_match('#(?:^|/)e-vote-final-enhanced/(.+)$#', $norm, $m)) {
            $candidates[] = $root . '/e-vote-final-enhanced/' . $m[1];
        }
        if (preg_match('#(?:^|/)tocca_admin/(.+)$#', $norm, $m)) {
            $candidates[] = $root . '/tocca_admin/' . $m[1];
        }
        if (!preg_match('#e-vote-final-enhanced/|tocca_admin/#', $norm)) {
            $rel = preg_replace('#^\.\./#', '', $norm) ?: $norm;
            $candidates[] = $root . '/e-vote-final-enhanced/' . $rel;
            $candidates[] = $root . '/tocca_admin/' . $rel;
            $candidates[] = str_replace('\\', '/', __DIR__) . '/' . $rel;
        }

        foreach (array_unique($candidates) as $cand) {
            if (is_file($cand)) {
                return $cand;
            }
        }

        return null;
    }
}

if (!function_exists('voter_header_logo_public_src')) {
    /**
     * URL for voter pages under /e-vote-final-enhanced/. Falls back to a bundled banner if missing.
     */
    function voter_header_logo_public_src(string $stored): string
    {
        $abs = voter_header_logo_absolute_path($stored);
        if ($abs !== null) {
            $root = str_replace('\\', '/', dirname(__DIR__));
            $absN = str_replace('\\', '/', $abs);
            if (str_starts_with($absN, $root . '/e-vote-final-enhanced/')) {
                return substr($absN, strlen($root . '/e-vote-final-enhanced/'));
            }
            if (str_starts_with($absN, $root . '/tocca_admin/')) {
                return '../tocca_admin/' . substr($absN, strlen($root . '/tocca_admin/'));
            }
        }

        return '../tocca_admin/' . ltrim(voter_header_logo_fallback_raw(), '/');
    }
}

/* ---------------- Core branding ---------------- */
$logoRaw        = getConfig('logo_path',          'img/default-logo.png');
$miniLogoRaw    = getConfig('mini_logo_path',     'img/default-mini.png');
$faviconRaw     = getConfig('favicon_path',       'img/tatakormoclogo.png');

$navbarBg       = getConfig('navbar_bg_color',    '#343a40');
$sidebarBg      = getConfig('sidebar_bg_color',   '#343a40');
$sidebarText    = getConfig('sidebar_text_color', '#ffffff');

/* ---------------- Registration branding ---------------- */
$nomBannerRaw   = getConfig('nominationBanner',   'img/default-banner.png');
$nomBgColor     = getConfig('nominationBgColor',  '#ffffff');

/* ---------------- Login page assets (optional) ---------------- */
$loginSideLogoRaw = getConfig('login_side_logo',  $logoRaw);
$loginBannerRaw   = getConfig('login_banner',     'img/login_banner_default.jpg');
$loginTitle       = getConfig('login_title',      'Tatak Ormoc Consumers’ Choice Awards');
$loginBgColor     = getConfig('login_bg_color',   '#0d47a1');

/* ---------------- Optional: Voter header banner (for message pages, etc.) ---------------- */
$voterHeaderLogoRaw  = getConfig('voter_header_logo', 'img/tocca2023.jpg');
if (voter_header_logo_absolute_path($voterHeaderLogoRaw) === null) {
    $voterHeaderLogoRaw = voter_header_logo_fallback_raw();
}

/* ---------------- Resolve context-aware URLs ---------------- */
$logoPath             = resolveAssetPath($logoRaw);
$miniLogoPath         = resolveAssetPath($miniLogoRaw);
$faviconPath          = resolveAssetPath($faviconRaw);

$nominationBannerPath = resolveAssetPath($nomBannerRaw);
$nominationBgColor    = $nomBgColor; // colors don’t need resolution

$loginSideLogoPath    = resolveAssetPath($loginSideLogoRaw);
$loginBannerPath      = resolveAssetPath($loginBannerRaw);

$voterHeaderLogoPath  = voter_header_logo_public_src($voterHeaderLogoRaw);

/* ---------------- QR frame defaults ---------------- */
$qrFrameDefaults = [
    'box_x' => 500,
    'box_y' => 1000,
    'box_w' => 660,
    'box_h' => 660,
    'card_side_pad' => 24,
    'card_top_pad' => 18,
    'label_strip_h_min' => 72,
    'label_strip_h_max' => 140,
    'gap_qr_to_label' => 10,
    'qr_side_min' => 360,
    'label_side_pad' => 10,
    'label_top_pad' => 8,
    'label_bottom_pad' => 10,
    'label_line_spacing' => 6,
    'label_font_size' => 40,
];

$qrFramePathRaw = trim(getConfig('qr_frame_path', 'img/qr_frame.jpg'));
$qrFrameUseRaw  = getConfig('qr_frame_use', '1');
$qrFrameOptionsRaw = getConfig('qr_frame_options', '');
$qrFrameOptions = json_decode($qrFrameOptionsRaw, true);
if (!is_array($qrFrameOptions)) {
    $qrFrameOptions = [];
}

if (array_key_exists('frame_path', $qrFrameOptions)) {
    $candidate = is_string($qrFrameOptions['frame_path']) ? trim($qrFrameOptions['frame_path']) : '';
    if ($candidate !== '') {
        $qrFramePathRaw = $candidate;
    }
    unset($qrFrameOptions['frame_path']);
}

if (array_key_exists('use_frame', $qrFrameOptions)) {
    $qrFrameUseRaw = $qrFrameOptions['use_frame'];
    unset($qrFrameOptions['use_frame']);
}

$qrFrameOptions = array_intersect_key($qrFrameOptions, $qrFrameDefaults);
$qrFrameOptions = array_merge($qrFrameDefaults, $qrFrameOptions);
foreach ($qrFrameOptions as $key => $value) {
    $qrFrameOptions[$key] = is_numeric($value) ? (int)$value : (int)$qrFrameDefaults[$key];
}

$qrFrameUse = filter_var($qrFrameUseRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($qrFrameUse === null) {
    $qrFrameUse = in_array(strtolower((string)$qrFrameUseRaw), ['1','true','yes','on'], true);
}

$qrFramePathRaw = $qrFramePathRaw !== '' ? $qrFramePathRaw : 'img/qr_frame.jpg';
$qrFramePathResolved = resolveAssetPath($qrFramePathRaw);

$qrFrameConfigNormalized = array_merge([
    'use_frame'  => $qrFrameUse,
    'frame_path' => $qrFramePathRaw,
], $qrFrameOptions);

$qrFrameConfigJson = json_encode($qrFrameConfigNormalized, JSON_UNESCAPED_SLASHES);
if ($qrFrameConfigJson === false) {
    $qrFrameConfigJson = '';
}

$qrFrameDatasetDefaults = [
    'frameUse' => $qrFrameUse ? '1' : '0',
];
if ($qrFramePathRaw) {
    $qrFrameDatasetDefaults['framePath'] = $qrFramePathRaw;
}

$datasetKeyMap = [
    'box_x' => 'frameBoxX',
    'box_y' => 'frameBoxY',
    'box_w' => 'frameBoxW',
    'box_h' => 'frameBoxH',
    'card_side_pad' => 'cardSidePad',
    'card_top_pad' => 'cardTopPad',
    'label_strip_h_min' => 'labelStripHMin',
    'label_strip_h_max' => 'labelStripHMax',
    'gap_qr_to_label' => 'gapQrToLabel',
    'qr_side_min' => 'qrSideMin',
    'label_side_pad' => 'labelSidePad',
    'label_top_pad' => 'labelTopPad',
    'label_bottom_pad' => 'labelBottomPad',
    'label_line_spacing' => 'labelLineSpacing',
    'label_font_size' => 'labelFontSize',
];

foreach ($datasetKeyMap as $optionKey => $datasetKey) {
    $qrFrameDatasetDefaults[$datasetKey] = (string)($qrFrameOptions[$optionKey] ?? $qrFrameDefaults[$optionKey]);
}

if ($qrFrameConfigJson) {
    $qrFrameDatasetDefaults['frameConfig'] = $qrFrameConfigJson;
}

$qrFrameDefaultsForJs = $qrFrameDatasetDefaults;

// No closing PHP tag — prevents accidental output.
