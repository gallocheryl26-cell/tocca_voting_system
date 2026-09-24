<?php
require_once 'db_connection.php';
require_once 'qr_engine.php';
require_once 'qr_url.php';
require_once 'choice_token.php';
require_once 'qr_frame_config.php';
require_once 'qr_style_config.php';
require_once 'qr_frame_presets.php';

/**
 * FRAME CONFIG (poster)
 */
const FRAME_PATH  = __DIR__ . '/img/qr_frame.jpg';
const USE_FRAME   = true;

// White card where QR + label live
const FRAME_BOX_X = 500;
const FRAME_BOX_Y = 1000;
const FRAME_BOX_W = 660;
const FRAME_BOX_H = 660;

/**
 * INNER LAYOUT of the white card
 * - We keep a QR area at the top, label strip at the bottom.
 * - If the label needs more space, we expand the label strip and shrink the QR area.
 */
const CARD_SIDE_PAD     = 24;  // inner left/right padding
const CARD_TOP_PAD      = 18;  // inner top padding (above QR)
const LABEL_STRIP_H_MIN = 72;  // minimum label strip height
const LABEL_STRIP_H_MAX = 120; // single business name under the QR
const GAP_QR_TO_LABEL   = 18;  // gap between QR and business name
const QR_SIDE_MIN       = 360; // don't let QR get smaller than this (px)

/* ---------- Label (TTF) settings (inside the label strip) ---------- */
const LABEL_FONT_PATH    = __DIR__ . '/fonts/static/OpenSans_Condensed-SemiBold.ttf';
const LABEL_FONT_SIZE    = 26;   // fallback; live posters pick size from the open band
const LABEL_MAX_LINES    = 2;    // business name only (wrap if long)
const LABEL_LINE_SPACING = 6;    // px between wrapped lines
const LABEL_SIDE_PAD     = 10;   // left/right pad inside the label strip
const LABEL_TOP_PAD      = 8;    // top pad inside strip
const LABEL_BOTTOM_PAD   = 10;   // bottom pad inside strip
const LABEL_COLOR_RGB    = [0, 0, 0];

/* ---------- Bitmap fallback (if TTF unavailable) ---------- */
const BITMAP_FONT     = 5;    // biggest GD font
const BITMAP_SIDE_PAD = 10;
const BITMAP_TOP_PAD  = 8;
const BITMAP_LINE_SP  = 4;

/** Your base URL for the QR target — use qr_voting_base_url() / Public Share Links. Do not hardcode a LAN or localhost host here. */


function resolve_generation_config(array $overrides = [])
{
    $base = load_default_generation_config();

    $normalized = normalize_frame_overrides($overrides);
    foreach ($normalized as $key => $value) {
        if (array_key_exists($key, $base)) {
            $base[$key] = $value;
        }
    }

    $base['frame_path'] = normalize_frame_path($base['frame_path']);
    if (!$base['use_frame']) {
        $base['frame_path'] = null;
    }

    return $base;
}

function load_default_generation_config()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $base = [
        'use_frame'            => USE_FRAME,
        'show_label_on_poster' => true,
        'frame_path'         => FRAME_PATH,
        'box_x'              => FRAME_BOX_X,
        'box_y'              => FRAME_BOX_Y,
        'box_w'              => FRAME_BOX_W,
        'box_h'              => FRAME_BOX_H,
        'card_side_pad'      => CARD_SIDE_PAD,
        'card_top_pad'       => CARD_TOP_PAD,
        'label_strip_h_min'  => LABEL_STRIP_H_MIN,
        'label_strip_h_max'  => LABEL_STRIP_H_MAX,
        'gap_qr_to_label'    => GAP_QR_TO_LABEL,
        'qr_side_min'        => QR_SIDE_MIN,
        'label_side_pad'     => LABEL_SIDE_PAD,
        'label_top_pad'      => LABEL_TOP_PAD,
        'label_bottom_pad'   => LABEL_BOTTOM_PAD,
        'label_line_spacing' => LABEL_LINE_SPACING,
        'label_font_size'    => LABEL_FONT_SIZE,
        'caption_box_x'      => 0,
        'caption_box_y'      => 0,
        'caption_box_w'      => 0,
        'caption_box_h'      => 0,
        'caption_font_size'  => 0,
        'caption_color'      => '#00155C',
    ];

    global $conn;
    $hasNewConfig = false;

    if ($conn instanceof mysqli) {
        // New-style JSON config (qr_frame_config)
        $storedFrameConfig = qr_frame_load_config($conn);
        if (is_array($storedFrameConfig)) {
            $hasNewConfig           = !empty($storedFrameConfig['_from_db']);
            $base['use_frame']      = (bool)$storedFrameConfig['use_frame'];
            $base['show_label_on_poster'] = array_key_exists('poster_caption', $storedFrameConfig)
                ? !empty($storedFrameConfig['poster_caption'])
                : true;
            $base['frame_path']     = $storedFrameConfig['frame_path_absolute'] ?: $storedFrameConfig['frame_path'];

            $map = [
                'frame_box_x'       => 'box_x',
                'frame_box_y'       => 'box_y',
                'frame_box_w'       => 'box_w',
                'frame_box_h'       => 'box_h',
                'card_side_pad'     => 'card_side_pad',
                'card_top_pad'      => 'card_top_pad',
                'label_strip_h_min' => 'label_strip_h_min',
                'label_strip_h_max' => 'label_strip_h_max',
                'gap_qr_to_label'   => 'gap_qr_to_label',
                'qr_side_min'       => 'qr_side_min',
            ];
            foreach ($map as $src => $dest) {
                if (isset($storedFrameConfig[$src])) {
                    $base[$dest] = (int)$storedFrameConfig[$src];
                }
            }
            foreach (['frame_box_x', 'frame_box_y', 'frame_box_w', 'frame_box_h'] as $k) {
                if (isset($storedFrameConfig[$k])) {
                    $base[$k] = (int)$storedFrameConfig[$k];
                }
            }
            foreach (['caption_box_x', 'caption_box_y', 'caption_box_w', 'caption_box_h', 'caption_font_size'] as $k) {
                if (isset($storedFrameConfig[$k])) {
                    $base[$k] = (int)$storedFrameConfig[$k];
                }
            }
            if (!empty($storedFrameConfig['caption_color'])) {
                $base['caption_color'] = (string) $storedFrameConfig['caption_color'];
            }
        }

        // Legacy per-key overrides (only if needed)
        $keys = [
            'qr_frame_use', 'qr_frame_path', 'qr_frame_box_x', 'qr_frame_box_y',
            'qr_frame_box_w', 'qr_frame_box_h', 'qr_card_side_pad', 'qr_card_top_pad',
            'qr_label_strip_h_min', 'qr_label_strip_h_max', 'qr_gap_qr_to_label',
            'qr_side_min', 'qr_label_side_pad', 'qr_label_top_pad', 'qr_label_bottom_pad',
            'qr_label_line_spacing', 'qr_label_font_size', 'qr_frame_options'
        ];

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        if ($placeholders !== '' && ($stmt = $conn->prepare("SELECT config_key, config_value FROM tbl_config WHERE config_key IN ($placeholders)"))) {
            $types = str_repeat('s', count($keys));
            $stmt->bind_param($types, ...$keys);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    $optionsJson = null;
                    $intKeyMap = [
                        'qr_frame_box_x'       => 'box_x',
                        'qr_frame_box_y'       => 'box_y',
                        'qr_frame_box_w'       => 'box_w',
                        'qr_frame_box_h'       => 'box_h',
                        'qr_card_side_pad'     => 'card_side_pad',
                        'qr_card_top_pad'      => 'card_top_pad',
                        'qr_label_strip_h_min' => 'label_strip_h_min',
                        'qr_label_strip_h_max' => 'label_strip_h_max',
                        'qr_gap_qr_to_label'   => 'gap_qr_to_label',
                        'qr_side_min'          => 'qr_side_min',
                        'qr_label_side_pad'    => 'label_side_pad',
                        'qr_label_top_pad'     => 'label_top_pad',
                        'qr_label_bottom_pad'  => 'label_bottom_pad',
                        'qr_label_line_spacing'=> 'label_line_spacing',
                        'qr_label_font_size'   => 'label_font_size',
                    ];

                    while ($row = $result->fetch_assoc()) {
                        $key   = $row['config_key'];
                        $value = $row['config_value'];

                        if ($key === 'qr_frame_options') {
                            $optionsJson = $value;
                            continue;
                        }

                        // If we already have a new JSON config, do NOT let legacy keys
                        // override use_frame or frame_path.
                        if ($hasNewConfig && ($key === 'qr_frame_use' || $key === 'qr_frame_path')) {
                            continue;
                        }

                        if ($key === 'qr_frame_use') {
                            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                            if ($bool === null) $bool = (bool)$value;
                            $base['use_frame'] = $bool;
                            continue;
                        }

                        if ($key === 'qr_frame_path') {
                            $path = is_string($value) ? trim($value) : '';
                            if ($path !== '') {
                                $base['frame_path'] = $path;
                            }
                            continue;
                        }

                        if (isset($intKeyMap[$key])) {
                            $base[$intKeyMap[$key]] = (int)$value;
                        }
                    }

                    if ($optionsJson) {
                        $options = json_decode($optionsJson, true);
                        if (is_array($options)) {
                            foreach ($options as $optKey => $optValue) {
                                if (!array_key_exists($optKey, $base)) continue;

                                // Same rule here: don't override use_frame/frame_path if new config exists
                                if ($hasNewConfig && ($optKey === 'use_frame' || $optKey === 'frame_path')) {
                                    continue;
                                }

                                if ($optKey === 'use_frame') {
                                    $bool = filter_var($optValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                                    if ($bool === null) $bool = (bool)$optValue;
                                    $base[$optKey] = $bool;
                                } elseif ($optKey === 'frame_path') {
                                    $path = is_string($optValue) ? trim($optValue) : '';
                                    if ($path !== '') {
                                        $base[$optKey] = $path;
                                    }
                                } else {
                                    $base[$optKey] = (int)$optValue;
                                }
                            }
                        }
                    }
                }
            }
            $stmt->close();
        }
    }

    return $cache = $base;
}

function normalize_frame_path($path)
{
    if ($path === null) return null;
    $path = trim((string)$path);
    if ($path === '' || strpos($path, '..') !== false) return null;

    // Treat relative paths as being relative to this directory
    if (!preg_match('/^(?:[A-Za-z]:\\\\|\\/)/', $path)) {
        $path = __DIR__ . '/' . ltrim($path, '/');
    }

    // Collapse duplicate slashes
    $path = preg_replace('#/{2,}#', '/', str_replace('\\', '/', $path));

    return $path;
}

function normalize_frame_overrides(array $overrides = [])
{
    $out = [];

    if (isset($overrides['frame_config']) && is_string($overrides['frame_config'])) {
        $decoded = json_decode($overrides['frame_config'], true);
        if (is_array($decoded)) {
            $overrides = array_merge($decoded, $overrides);
        }
    }

    if (isset($overrides['box']) && is_array($overrides['box'])) {
        $box = $overrides['box'];
        if (isset($box['x']))      $overrides['box_x'] = $box['x'];
        if (isset($box['y']))      $overrides['box_y'] = $box['y'];
        if (isset($box['w']))      $overrides['box_w'] = $box['w'];
        if (isset($box['width']))  $overrides['box_w'] = $box['width'];
        if (isset($box['h']))      $overrides['box_h'] = $box['h'];
        if (isset($box['height'])) $overrides['box_h'] = $box['height'];
    }

    $aliasMap = [
        'path'               => 'frame_path',
        'frame_path'         => 'frame_path',
        'frame'              => 'frame_path',
        'framePath'          => 'frame_path',
        'use_frame'          => 'use_frame',
        'frame_use'          => 'use_frame',
        'frame_enabled'      => 'use_frame',
        'useFrame'           => 'use_frame',
        'frameEnabled'       => 'use_frame',
        'show_label_on_poster' => 'show_label_on_poster',
        'showLabelOnPoster'  => 'show_label_on_poster',
        'poster_caption'     => 'show_label_on_poster',
        'box_x'              => 'box_x',
        'frame_box_x'        => 'box_x',
        'boxX'               => 'box_x',
        'box_y'              => 'box_y',
        'frame_box_y'        => 'box_y',
        'boxY'               => 'box_y',
        'box_w'              => 'box_w',
        'frame_box_w'        => 'box_w',
        'boxW'               => 'box_w',
        'box_width'          => 'box_w',
        'boxWidth'           => 'box_w',
        'box_h'              => 'box_h',
        'frame_box_h'        => 'box_h',
        'boxH'               => 'box_h',
        'box_height'         => 'box_h',
        'boxHeight'          => 'box_h',
        'card_side_pad'      => 'card_side_pad',
        'cardSidePad'        => 'card_side_pad',
        'card_top_pad'       => 'card_top_pad',
        'cardTopPad'         => 'card_top_pad',
        'label_strip_h_min'  => 'label_strip_h_min',
        'labelStripHMin'     => 'label_strip_h_min',
        'label_strip_h_max'  => 'label_strip_h_max',
        'labelStripHMax'     => 'label_strip_h_max',
        'gap_qr_to_label'    => 'gap_qr_to_label',
        'gapQrToLabel'       => 'gap_qr_to_label',
        'qr_side_min'        => 'qr_side_min',
        'qrSideMin'          => 'qr_side_min',
        'label_side_pad'     => 'label_side_pad',
        'labelSidePad'       => 'label_side_pad',
        'label_top_pad'      => 'label_top_pad',
        'labelTopPad'        => 'label_top_pad',
        'label_bottom_pad'   => 'label_bottom_pad',
        'labelBottomPad'     => 'label_bottom_pad',
        'label_line_spacing' => 'label_line_spacing',
        'labelLineSpacing'   => 'label_line_spacing',
        'label_font_size'    => 'label_font_size',
        'labelFontSize'      => 'label_font_size',
        'caption_box_x'      => 'caption_box_x',
        'captionBoxX'        => 'caption_box_x',
        'caption_box_y'      => 'caption_box_y',
        'captionBoxY'        => 'caption_box_y',
        'caption_box_w'      => 'caption_box_w',
        'captionBoxW'        => 'caption_box_w',
        'caption_box_h'      => 'caption_box_h',
        'captionBoxH'        => 'caption_box_h',
        'caption_font_size'  => 'caption_font_size',
        'captionFontSize'    => 'caption_font_size',
        'caption_color'      => 'caption_color',
        'captionColor'       => 'caption_color',
    ];

    foreach ($aliasMap as $alias => $target) {
        if (array_key_exists($alias, $overrides)) {
            $out[$target] = $overrides[$alias];
        }
    }

    $result = [];

    if (array_key_exists('use_frame', $out)) {
        $bool = filter_var($out['use_frame'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            $bool = (bool)$out['use_frame'];
        }
        $result['use_frame'] = $bool;
    }

    if (array_key_exists('frame_path', $out)) {
        $path = trim((string)$out['frame_path']);
        $result['frame_path'] = $path === '' ? null : $path;
    }

    foreach (['box_x','box_y','box_w','box_h'] as $key) {
        if (array_key_exists($key, $out) && $out[$key] !== '' && $out[$key] !== null) {
            $result[$key] = (int)$out[$key];
        }
    }

    foreach ([
        'card_side_pad','card_top_pad','label_strip_h_min','label_strip_h_max',
        'gap_qr_to_label','qr_side_min','label_side_pad','label_top_pad',
        'label_bottom_pad','label_line_spacing','label_font_size',
        'caption_box_x','caption_box_y','caption_box_w','caption_box_h','caption_font_size'
    ] as $dimKey) {
        if (array_key_exists($dimKey, $out) && $out[$dimKey] !== '' && $out[$dimKey] !== null) {
            $result[$dimKey] = (int)$out[$dimKey];
        }
    }

    if (array_key_exists('caption_color', $out) && is_string($out['caption_color']) && trim($out['caption_color']) !== '') {
        $result['caption_color'] = trim($out['caption_color']);
    }

    return $result;
}

function extract_frame_options_from_request(array $source)
{
    return normalize_frame_overrides($source);
}

/* ================= Helpers ================= */

function img_from_file($path)
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'png') {
        $im = @imagecreatefrompng($path);
    } elseif ($ext === 'jpg' || $ext === 'jpeg') {
        $im = @imagecreatefromjpeg($path);
    } else {
        return null;
    }
    if ($im) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
    }
    return $im;
}

function resize_image_exact($src, int $w, int $h)
{
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $w, $h, $trans);
    imagealphablending($dst, true);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    return $dst;
}

/** Downscale an image for lightweight browser previews. */
function scale_image_max_width($img, int $maxWidth)
{
    if (!$img) {
        return $img;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= $maxWidth) {
        return $img;
    }
    $newW = $maxWidth;
    $newH = (int)round($h * ($maxWidth / $w));
    $scaled = imagecreatetruecolor($newW, $newH);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    $trans = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
    imagefilledrectangle($scaled, 0, 0, $newW, $newH, $trans);
    imagecopyresampled($scaled, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
    imagedestroy($img);
    return $scaled;
}

/**
 * Make QR background pixels transparent before pasting onto the frame.
 * Must match the configured QR background color (not only pure white).
 *
 * @param array{0:int,1:int,2:int} $bgRgb
 */
function qr_background_to_transparent(\GdImage $img, array $bgRgb, int $tolerance = 20): void
{
    $w = imagesx($img);
    $h = imagesy($img);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $alpha = imagecolorallocatealpha($img, 0, 0, 0, 127);
    $br = max(0, min(255, (int) $bgRgb[0]));
    $bg = max(0, min(255, (int) $bgRgb[1]));
    $bb = max(0, min(255, (int) $bgRgb[2]));

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            if (abs($r - $br) <= $tolerance && abs($g - $bg) <= $tolerance && abs($b - $bb) <= $tolerance) {
                imagesetpixel($img, $x, $y, $alpha);
            }
        }
    }
}

/** @param array{0:int,1:int,2:int} $bgRgb */
function qr_prepare_qr_for_frame_paste(\GdImage $img, array $bgRgb): void
{
    qr_background_to_transparent($img, $bgRgb);
}

/** Back-compat: only strips pure white. Prefer qr_background_to_transparent(). */
function white_to_transparent(&$img): void
{
    qr_background_to_transparent($img, [255, 255, 255]);
}

function qr_label_font_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $candidates = [
        LABEL_FONT_PATH,
        __DIR__ . '/fonts/static/OpenSans_Condensed-SemiBold.ttf',
        __DIR__ . '/fonts/OpenSans_Condensed-SemiBold.ttf',
        __DIR__ . '/fonts/static/OpenSans-SemiBold.ttf',
        __DIR__ . '/fonts/OpenSans-VariableFont_wdth,wght.ttf',
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
            return $path = $candidate;
        }
    }
    return $path = '';
}

function ttf_available()
{
    return qr_label_font_path() !== '' && function_exists('imagettftext');
}

/** Measure TTF text width at fixed size */
function ttf_text_width($font, $size, $text)
{
    $bbox = imagettfbbox($size, 0, $font, $text === '' ? ' ' : $text);
    return $bbox[2] - $bbox[0];
}

/** Measure TTF line height at fixed size (approx) */
function ttf_line_height($font, $size)
{
    $bbox = imagettfbbox($size, 0, $font, 'Hg');
    return $bbox[1] - $bbox[7];
}

/**
 * Word-wrap (TTF) to fit width; clamp to max lines.
 * NO ellipsis. If text exceeds space, it is hard-cut to fit.
 */
function wrap_text_ttf($text, $font, $size, $maxWidth, $maxLines)
{
    $maxLines = max(1, (int) $maxLines);
    $blocks = preg_split('/\R/u', trim((string) $text)) ?: [];
    $lines = [];

    $fit_to_width = function ($s) use ($font, $size, $maxWidth) {
        while ($s !== '' && ttf_text_width($font, $size, $s) > $maxWidth) {
            $s = rtrim(function_exists('mb_substr') ? mb_substr($s, 0, -1, 'UTF-8') : substr($s, 0, -1));
        }
        return $s;
    };

    foreach ($blocks as $block) {
        $block = trim((string) $block);
        if ($block === '') {
            continue;
        }
        $words = preg_split('/\s+/', $block) ?: [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : ($cur . ' ' . $w);
            if (ttf_text_width($font, $size, $try) <= $maxWidth) {
                $cur = $try;
                continue;
            }
            if ($cur === '') {
                $cur = $fit_to_width($w);
            }
            $lines[] = $cur;
            if (count($lines) >= $maxLines) {
                return $lines;
            }
            $cur = ttf_text_width($font, $size, $w) <= $maxWidth ? $w : $fit_to_width($w);
        }
        if ($cur !== '' && count($lines) < $maxLines) {
            $lines[] = ttf_text_width($font, $size, $cur) <= $maxWidth ? $cur : $fit_to_width($cur);
        }
        if (count($lines) >= $maxLines) {
            return $lines;
        }
    }

    return $lines;
}

/** Draw multi-line TTF centered in given rect; returns total height drawn */
function draw_multiline_ttf_center($img, $lines, $font, $size, $rectX, $rectY, $rectW, $color, $lineSpacing, $topPad, $bottomPad)
{
    $lineH = ttf_line_height($font, $size);
    $y = $rectY + $topPad;
    foreach ($lines as $idx => $line) {
        $textW = ttf_text_width($font, $size, $line);
        $x = (int)($rectX + ($rectW - $textW) / 2);
        $yLineBase = $y + $lineH; // baseline for this line
        imagettftext($img, $size, 0, $x, $yLineBase, $color, $font, $line);
        $y = $y + $lineH + ($idx < count($lines) - 1 ? $lineSpacing : 0);
    }
    return ($y - $rectY) + $bottomPad;
}

function qr_flag_enabled($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value !== 0;
    }
    $s = strtolower(trim((string) $value));
    if ($s === '' || $s === '0' || $s === 'false' || $s === 'off' || $s === 'no') {
        return false;
    }
    return in_array($s, ['1', 'true', 'on', 'yes'], true);
}

function qr_business_name_only(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    $parts = preg_split('/\R/u', $label) ?: [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
            return $part;
        }
    }
    return '';
}

/**
 * @return array{0:int,1:int,2:int}
 */
function qr_pixel_rgb(\GdImage $img, int $x, int $y): array
{
    $rgb = imagecolorat($img, $x, $y);
    if (!imageistruecolor($img)) {
        $c = imagecolorsforindex($img, $rgb);
        return [(int) $c['red'], (int) $c['green'], (int) $c['blue']];
    }
    return [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
}

function qr_rgb_lum(array $rgb): float
{
    return (0.299 * $rgb[0]) + (0.587 * $rgb[1]) + (0.114 * $rgb[2]);
}

function qr_row_dark_ratio(\GdImage $img, int $y, int $x0, int $x1): float
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($y < 0 || $y >= $h || $x1 < $x0) {
        return 0.0;
    }
    $x0 = max(0, $x0);
    $x1 = min($w - 1, $x1);
    $dark = 0;
    $n = 0;
    $step = max(1, (int) floor(($x1 - $x0) / 90));
    for ($x = $x0; $x <= $x1; $x += $step) {
        if (qr_rgb_lum(qr_pixel_rgb($img, $x, $y)) < 95) {
            $dark++;
        }
        $n++;
    }
    return $n > 0 ? ($dark / $n) : 0.0;
}

function qr_first_ink_row(\GdImage $img, int $yFrom, int $x0, int $x1): int
{
    $h = imagesy($img);
    $streak = 0;
    $start = $h;
    for ($y = max(0, $yFrom); $y < $h - 1; $y++) {
        if (qr_row_dark_ratio($img, $y, $x0, $x1) >= 0.06) {
            $streak++;
            if ($streak === 1) {
                $start = $y;
            }
            if ($streak >= 2) {
                return $start;
            }
        } else {
            $streak = 0;
            $start = $h;
        }
    }
    return $h;
}

/**
 * @return array{0:int,1:int,2:int}
 */
function qr_sample_region_rgb(\GdImage $img, int $x0, int $y0, int $x1, int $y1): array
{
    $w = imagesx($img);
    $h = imagesy($img);
    $x0 = max(0, min($w - 1, $x0));
    $x1 = max($x0, min($w - 1, $x1));
    $y0 = max(0, min($h - 1, $y0));
    $y1 = max($y0, min($h - 1, $y1));
    $rs = 0;
    $gs = 0;
    $bs = 0;
    $n = 0;
    $stepX = max(1, (int) floor(($x1 - $x0) / 40));
    $stepY = max(1, (int) floor(($y1 - $y0) / 16));
    for ($y = $y0; $y <= $y1; $y += $stepY) {
        for ($x = $x0; $x <= $x1; $x += $stepX) {
            $rgb = qr_pixel_rgb($img, $x, $y);
            $rs += $rgb[0];
            $gs += $rgb[1];
            $bs += $rgb[2];
            $n++;
        }
    }
    if ($n <= 0) {
        return [0, 16, 102];
    }
    return [(int) round($rs / $n), (int) round($gs / $n), (int) round($bs / $n)];
}

/**
 * @return array{0:int,1:int,2:int}
 */
function qr_sample_ink_rgb(\GdImage $img, int $x0, int $x1, int $yFrom): array
{
    $w = imagesx($img);
    $h = imagesy($img);
    $x0 = max(0, $x0);
    $x1 = min($w - 1, $x1);
    $yFrom = max(0, min($h - 1, $yFrom));
    $rs = 0;
    $gs = 0;
    $bs = 0;
    $n = 0;
    $stepX = max(1, (int) floor(($x1 - $x0) / 70));
    for ($y = $yFrom; $y < $h; $y += 2) {
        for ($x = $x0; $x <= $x1; $x += $stepX) {
            $rgb = qr_pixel_rgb($img, $x, $y);
            if (qr_rgb_lum($rgb) > 80) {
                continue;
            }
            $rs += $rgb[0];
            $gs += $rgb[1];
            $bs += $rgb[2];
            $n++;
            if ($n >= 80) {
                break 2;
            }
        }
    }
    if ($n < 8) {
        return [0, 21, 92];
    }
    return [(int) round($rs / $n), (int) round($gs / $n), (int) round($bs / $n)];
}

/**
 * Draw only the business name below the QR, in the open light band, with a gap.
 */
function qr_pick_caption_rgb(array $bg, array $ink): array
{
    $bgLum = qr_rgb_lum($bg);
    $inkLum = qr_rgb_lum($ink);
    if (abs($bgLum - $inkLum) >= 70) {
        return $ink;
    }
    return $bgLum >= 140 ? [0, 21, 92] : [255, 255, 255];
}

/**
 * @return array{x:int,y:int,w:int,h:int,size:int}
 */
function qr_caption_box_from_config(array $config, int $frameW, int $frameH, int $qrX, int $qrY, int $qrSide): array
{
    $x = (int) ($config['caption_box_x'] ?? 0);
    $y = (int) ($config['caption_box_y'] ?? 0);
    $w = (int) ($config['caption_box_w'] ?? 0);
    $h = (int) ($config['caption_box_h'] ?? 0);
    if ($w < 40 || $h < 18) {
        $def = qr_default_caption_box($qrX, $qrY, $qrSide, $qrSide, $frameW, $frameH);
        $x = $def['x'];
        $y = $def['y'];
        $w = $def['w'];
        $h = $def['h'];
    }
    $w = max(40, min($w, max(40, $frameW)));
    $h = max(18, min($h, max(18, $frameH)));
    $x = max(0, min($x, max(0, $frameW - $w)));
    $y = max(0, min($y, max(0, $frameH - $h)));
    $size = (int) ($config['caption_font_size'] ?? 0);
    if ($size < 8) {
        $size = (int) ($config['label_font_size'] ?? 0);
    }
    if ($size < 8) {
        $size = max(18, (int) round($h * 0.72));
    }
    $size = max(10, min(200, $size));
    return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'size' => $size];
}

function qr_draw_business_name_in_box(
    \GdImage $frame,
    string $name,
    int $x,
    int $y,
    int $w,
    int $h,
    int $preferredSize,
    array $requestedRgb
): void {
    $name = qr_business_name_only($name);
    if ($name === '' || $w < 20 || $h < 12) {
        return;
    }
    $frameW = imagesx($frame);
    $frameH = imagesy($frame);
    $x = max(0, min($x, $frameW - 1));
    $y = max(0, min($y, $frameH - 1));
    $w = max(20, min($w, $frameW - $x));
    $h = max(12, min($h, $frameH - $y));

    $bg = qr_sample_region_rgb($frame, $x, $y, $x + $w, $y + $h);
    $ink = qr_pick_caption_rgb($bg, $requestedRgb);

    $maxSize = min(200, max(10, $preferredSize), max(10, (int) round($h * 0.92)));
    $minSize = max(10, min($maxSize, (int) round($h * 0.22)));

    if (ttf_available()) {
        $font = qr_label_font_path();
        $chosen = null;
        for ($try = $maxSize; $try >= $minSize; $try -= 1) {
            foreach ([1, 2] as $maxLines) {
                $wrapped = wrap_text_ttf($name, $font, $try, $w, $maxLines);
                if ($wrapped === []) {
                    continue;
                }
                $lh = ttf_line_height($font, $try);
                $sp = max(2, (int) round($try * 0.12));
                $need = (count($wrapped) * $lh) + (max(0, count($wrapped) - 1) * $sp);
                $widest = 0;
                foreach ($wrapped as $ln) {
                    $widest = max($widest, ttf_text_width($font, $try, $ln));
                }
                if ($need <= $h && $widest <= $w) {
                    $chosen = ['lines' => $wrapped, 'size' => $try, 'sp' => $sp, 'need' => $need];
                    break 2;
                }
            }
        }
        if ($chosen === null) {
            $wrapped = wrap_text_ttf($name, $font, $minSize, $w, 1);
            $lh = ttf_line_height($font, $minSize);
            $chosen = [
                'lines' => $wrapped !== [] ? $wrapped : [$name],
                'size' => $minSize,
                'sp' => 2,
                'need' => min($h, $lh),
            ];
        }
        $drawY = $y + (int) max(0, floor(($h - $chosen['need']) / 2));
        if ($drawY + $chosen['need'] > $y + $h) {
            $drawY = max($y, $y + $h - $chosen['need']);
        }
        $color = imagecolorallocate($frame, $ink[0], $ink[1], $ink[2]);
        draw_multiline_ttf_center(
            $frame,
            $chosen['lines'],
            $font,
            $chosen['size'],
            $x,
            $drawY,
            $w,
            $color,
            $chosen['sp'],
            0,
            0
        );
        return;
    }

    $charW = imagefontwidth(BITMAP_FONT);
    $charH = imagefontheight(BITMAP_FONT);
    if ($charH > $h) {
        return;
    }
    $maxChars = max(1, (int) floor($w / max(1, $charW)));
    $text = strlen($name) > $maxChars ? substr($name, 0, $maxChars) : $name;
    $tw = $charW * strlen($text);
    $px = (int) ($x + ($w - $tw) / 2);
    $py = $y + (int) max(0, floor(($h - $charH) / 2));
    $color = imagecolorallocate($frame, $ink[0], $ink[1], $ink[2]);
    imagestring($frame, BITMAP_FONT, $px, $py, $text, $color);
}

function qr_draw_business_name_below_qr(\GdImage $frame, string $name, int $qrX, int $qrY, int $qrSide): void
{
    $name = qr_business_name_only($name);
    if ($name === '' || $qrSide <= 0) {
        return;
    }
    $frameW = imagesx($frame);
    $frameH = imagesy($frame);
    $qrBottom = $qrY + $qrSide;
    $minGap = max(18, (int) round($qrSide * 0.07), (int) round($frameH * 0.014));
    $scanX0 = (int) round($frameW * 0.16);
    $scanX1 = (int) round($frameW * 0.84);
    $yStart = min($frameH - 2, $qrBottom + $minGap);
    $firstDark = qr_first_ink_row($frame, $yStart, $scanX0, $scanX1);
    $yEnd = $firstDark - $minGap;
    if ($yEnd - $yStart < 16) {
        $yStart = min($frameH - 18, $qrBottom + max(12, (int) round($minGap * 0.7)));
        $yEnd = min($frameH - 8, $yStart + max(22, (int) round($frameH * 0.04)));
        if ($firstDark < $frameH) {
            $yEnd = min($yEnd, $firstDark - 8);
        }
    }
    if ($yEnd <= $yStart) {
        return;
    }

    $textW = max(40, $scanX1 - $scanX0);
    $textX = (int) (($frameW - $textW) / 2);
    $availH = $yEnd - $yStart;
    $bg = qr_sample_region_rgb($frame, $textX, $yStart, $textX + $textW, $yEnd);
    $inkHint = qr_rgb_lum($bg) >= 140
        ? qr_sample_ink_rgb($frame, $scanX0, $scanX1, min($frameH - 1, $firstDark < $frameH ? $firstDark : $yEnd))
        : [255, 255, 255];
    $ink = qr_pick_caption_rgb($bg, $inkHint);

    $maxSize = min(72, (int) round($frameW * 0.042), (int) round($availH * 0.72));
    $minSize = max(14, (int) round($frameW * 0.018));
    if ($maxSize < $minSize) {
        $maxSize = $minSize;
    }

    if (ttf_available()) {
        $font = qr_label_font_path();
        $chosen = null;
        for ($try = $maxSize; $try >= $minSize; $try -= 1) {
            foreach ([1, 2] as $maxLines) {
                $wrapped = wrap_text_ttf($name, $font, $try, $textW, $maxLines);
                if ($wrapped === []) {
                    continue;
                }
                $lh = ttf_line_height($font, $try);
                $sp = max(2, (int) round($try * 0.12));
                $need = (count($wrapped) * $lh) + (max(0, count($wrapped) - 1) * $sp);
                $widest = 0;
                foreach ($wrapped as $ln) {
                    $widest = max($widest, ttf_text_width($font, $try, $ln));
                }
                if ($need <= $availH && $widest <= $textW) {
                    $chosen = ['lines' => $wrapped, 'size' => $try, 'sp' => $sp, 'need' => $need];
                    break 2;
                }
            }
        }
        if ($chosen === null) {
            $wrapped = wrap_text_ttf($name, $font, $minSize, $textW, 1);
            $lh = ttf_line_height($font, $minSize);
            $chosen = [
                'lines' => $wrapped !== [] ? $wrapped : [$name],
                'size' => $minSize,
                'sp' => 2,
                'need' => $lh,
            ];
        }
        if ($chosen['need'] > $availH) {
            return;
        }
        $drawY = $yStart + (int) max(0, floor(($availH - $chosen['need']) / 2));
        if ($drawY + $chosen['need'] > $yEnd) {
            $drawY = max($yStart, $yEnd - $chosen['need']);
        }
        $color = imagecolorallocate($frame, $ink[0], $ink[1], $ink[2]);
        draw_multiline_ttf_center(
            $frame,
            $chosen['lines'],
            $font,
            $chosen['size'],
            $textX,
            $drawY,
            $textW,
            $color,
            $chosen['sp'],
            0,
            0
        );
        return;
    }

    $charW = imagefontwidth(BITMAP_FONT);
    $charH = imagefontheight(BITMAP_FONT);
    if ($charH > $availH) {
        return;
    }
    $maxChars = max(1, (int) floor($textW / max(1, $charW)));
    $text = strlen($name) > $maxChars ? substr($name, 0, $maxChars) : $name;
    $tw = $charW * strlen($text);
    $x = (int) ($textX + ($textW - $tw) / 2);
    $y = $yStart + (int) max(0, floor(($availH - $charH) / 2));
    $color = imagecolorallocate($frame, $ink[0], $ink[1], $ink[2]);
    imagestring($frame, BITMAP_FONT, $x, $y, $text, $color);
}

/* ================= Main: generate + save QR =================
 * UPDATED: now uses compose_qr_image() so saved PNG matches preview exactly.
 */

function generateAndSaveQR($choice_id, $force = false, array $frameOverrides = [])
{
    global $conn;

    // Extract "preview" flag out of overrides, if present
    $preview = false;
    if (array_key_exists('preview', $frameOverrides)) {
        $preview = filter_var($frameOverrides['preview'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($preview === null) {
            $preview = (bool)$frameOverrides['preview'];
        }
        unset($frameOverrides['preview']);
    }

    // Resolve final layout config (defaults + DB + overrides)
    $config = resolve_generation_config($frameOverrides);

    if ($choice_id > 0 && $conn instanceof mysqli) {
        $config = qr_resolve_frame_for_choice($conn, (int)$choice_id, $config);
    }

    $style = qr_style_load_config($conn);
    $style = qr_style_merge_overrides($style, $frameOverrides);
    $config['label_color'] = $style['label_color'] ?? '#000000';
    if (!empty($config['caption_color'])) {
        $config['label_color'] = $config['caption_color'];
    }

    // 1) Lookup business name
    $stmt = $conn->prepare("SELECT choice_name FROM tbl_choices WHERE choice_id = ?");
    $stmt->bind_param("i", $choice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$row = $result->fetch_assoc()) {
        return ['status' => 'error', 'message' => 'Choice not found.'];
    }
    $choice_name = trim($row['choice_name']);
    if ($choice_name === '') {
        return ['status' => 'error', 'message' => 'Choice name is empty.'];
    }

    // 2) Paths + URLs
    $filenameSafe = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $choice_name));
    $qrDirDisk  = $preview ? (sys_get_temp_dir() . '/qr_preview/') : (__DIR__ . '/qrcodes/');
    $qrPathDisk = $qrDirDisk . "{$filenameSafe}.png";
    $qrDirHref  = $preview ? '' : 'qrcodes/';
    $qrPathHref = $qrDirHref ? $qrDirHref . "{$filenameSafe}.png" : null;
    try {
        $qrData = qr_vote_url_for_choice($choice_id, $conn);
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => 'Failed to build QR URL: ' . $e->getMessage()];
    }

    // 3) Ensure directory exists
    if (!is_dir($qrDirDisk)) {
        @mkdir($qrDirDisk, 0755, true);
    }

    $urlMetaPath = $qrPathDisk . '.url';
    // Fast path: reuse existing PNG only when it still encodes the same vote URL
    if (!$preview && !$force && is_file($qrPathDisk) && is_file($urlMetaPath)) {
        $prevUrl = trim((string) @file_get_contents($urlMetaPath));
        if ($prevUrl === $qrData) {
            return [
                'status'  => 'skipped',
                'message' => 'QR already exists.',
                'path'    => $qrPathHref,
                'name'    => $choice_name,
                'url'     => $qrData,
                'data_uri'=> null,
                'preview' => false,
                'frame'   => ['used' => null, 'path' => null],
            ];
        }
    }

    // 4) Generate raw QR PNG (Endroid, ECC High + optional center logo + colors)
    $qrRaw = qr_generate_png_resource($qrData, 900, $style);
    if (!$qrRaw) {
        return ['status' => 'error', 'message' => 'Failed to create QR image.'];
    }
    $bgRgb = qr_style_hex_to_rgb((string) ($style['bg_color'] ?? '#ffffff'));
    qr_prepare_qr_for_frame_paste($qrRaw, $bgRgb);

    // 5) Compose using the SAME logic as preview (business name only)
    $usedFrame  = false;
    $caption = qr_business_name_only($choice_name);
    $finalImage = compose_qr_image($qrRaw, $caption, $config, $usedFrame);

    if (!$finalImage) {
        imagedestroy($qrRaw);
        return ['status' => 'error', 'message' => 'Failed to compose QR image.'];
    }

    // 6) Save to disk (non-preview)
    if (!$preview) {
        imagepng($finalImage, $qrPathDisk, 6);
        @file_put_contents($urlMetaPath, $qrData);

        // New QR image invalidates prior emailed poster — allow resend
        if ($force && $choice_id > 0 && $conn instanceof mysqli) {
            if ($stmt = $conn->prepare('UPDATE tbl_choices SET qr_sent = 0 WHERE choice_id = ?')) {
                $stmt->bind_param('i', $choice_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // 7) Cleanup + build optional preview data URI
    imagedestroy($qrRaw);

    $dataUri = null;
    if ($preview && $finalImage) {
        ob_start();
        imagepng($finalImage);
        $pngData = ob_get_clean();
        $dataUri = 'data:image/png;base64,' . base64_encode($pngData);
    }

    if ($finalImage) {
        imagedestroy($finalImage);
    }

    if (!$preview && $conn instanceof mysqli) {
        try {
            require_once __DIR__ . '/audit_log.php';
            $qrAction = $force ? 'regenerate_qr' : 'generate_qr';
            audit_log_choice_qr($conn, $choice_id, $choice_name, $qrAction, $qrPathHref, (bool) $force);
        } catch (Throwable $e) {
            error_log('audit_log_choice_qr failed: ' . $e->getMessage());
        }
    }

    return [
        'status'  => 'success',
        'message' => $usedFrame ? 'QR generated with frame.' : 'QR generated (plain fallback).',
        'path'    => $qrPathHref,
        'name'    => $choice_name,
        'url'     => $qrData,
        'data_uri'=> $dataUri,
        'preview' => $preview,
        'frame'   => [
            'used' => $usedFrame,
            'path' => $usedFrame ? ($config['frame_path'] ?? null) : null
        ]
    ];
}

/* ================= Preview composer =================
 * Compose a QR + label into the frame, using the same layout logic
 * as generateAndSaveQR(), but without saving to disk.
 */
function compose_qr_image($qrRaw, string $label, array $config, &$usedFrame = false)
{
    if (!$qrRaw) return null;

    // ---- Read config with sensible defaults ----
    $useFrame  = isset($config['use_frame']) ? (bool)$config['use_frame'] : USE_FRAME;

    // frame path: configured file, then default poster template if that file is missing
    $framePath = $config['frame_path_absolute'] ?? ($config['frame_path'] ?? FRAME_PATH);
    if (function_exists('qr_frame_resolve_existing_path')) {
        $resolved = qr_frame_resolve_existing_path(is_string($framePath) ? $framePath : '');
        if ($resolved !== '') {
            $framePath = $resolved;
        }
    }

    // Some configs use frame_box_x/box_x naming; support both
    $frameBoxX = isset($config['frame_box_x']) ? (int)$config['frame_box_x'] : (int)($config['box_x'] ?? FRAME_BOX_X);
    $frameBoxY = isset($config['frame_box_y']) ? (int)$config['frame_box_y'] : (int)($config['box_y'] ?? FRAME_BOX_Y);
    $frameBoxW = isset($config['frame_box_w']) ? (int)$config['frame_box_w'] : (int)($config['box_w'] ?? FRAME_BOX_W);
    $frameBoxH = isset($config['frame_box_h']) ? (int)$config['frame_box_h'] : (int)($config['box_h'] ?? FRAME_BOX_H);

    $cardSidePad    = isset($config['card_side_pad'])      ? (int)$config['card_side_pad']      : CARD_SIDE_PAD;
    $cardTopPad     = isset($config['card_top_pad'])       ? (int)$config['card_top_pad']       : CARD_TOP_PAD;
    $labelStripMin  = isset($config['label_strip_h_min'])  ? (int)$config['label_strip_h_min']  : LABEL_STRIP_H_MIN;
    $labelStripMax  = isset($config['label_strip_h_max'])  ? (int)$config['label_strip_h_max']  : LABEL_STRIP_H_MAX;
    $gapQrToLabel   = isset($config['gap_qr_to_label'])    ? (int)$config['gap_qr_to_label']    : GAP_QR_TO_LABEL;
    $qrSideMin      = isset($config['qr_side_min'])        ? (int)$config['qr_side_min']        : QR_SIDE_MIN;

    $labelSidePad   = isset($config['label_side_pad'])     ? (int)$config['label_side_pad']     : LABEL_SIDE_PAD;
    $labelTopPad    = isset($config['label_top_pad'])      ? (int)$config['label_top_pad']      : LABEL_TOP_PAD;
    $labelBottomPad = isset($config['label_bottom_pad'])   ? (int)$config['label_bottom_pad']   : LABEL_BOTTOM_PAD;
    $labelLineSp    = isset($config['label_line_spacing']) ? (int)$config['label_line_spacing'] : LABEL_LINE_SPACING;
    $labelFontSize  = isset($config['label_font_size'])    ? (int)$config['label_font_size']    : LABEL_FONT_SIZE;
    if ($labelStripMax < 200) {
        $labelStripMax = LABEL_STRIP_H_MAX;
    }

    $labelColorHex = $config['label_color'] ?? '#000000';
    $labelColorRgb = qr_style_hex_to_rgb((string)$labelColorHex);

    $usedFrame  = false;
    $finalImage = null;

    // 1) Try framed layout
    if ($useFrame && $framePath && file_exists($framePath)) {
        $frame = img_from_file($framePath);
        if ($frame) {
            $frameW = imagesx($frame);
            $frameH = imagesy($frame);

            $boxOk =
                $frameBoxX >= 0 && $frameBoxY >= 0 &&
                $frameBoxW > 0 && $frameBoxH > 0 &&
                $frameBoxX + $frameBoxW <= $frameW &&
                $frameBoxY + $frameBoxH <= $frameH;

            if (!$boxOk && $frameW > 40 && $frameH > 40) {
                $frameBoxW = max(40, min($frameBoxW > 0 ? $frameBoxW : (int) round($frameW * 0.55), $frameW));
                $frameBoxH = max(40, min($frameBoxH > 0 ? $frameBoxH : $frameBoxW, $frameH));
                $frameBoxX = max(0, min($frameBoxX, $frameW - $frameBoxW));
                $frameBoxY = max(0, min($frameBoxY, $frameH - $frameBoxH));
                $boxOk = true;
            }

            if ($boxOk) {
                $cardX = $frameBoxX;
                $cardY = $frameBoxY;
                $cardW = $frameBoxW;
                $cardH = $frameBoxH;

                $name = qr_business_name_only($label);
                $showName = $name !== '' && qr_flag_enabled($config['show_label_on_poster'] ?? $config['poster_caption'] ?? true);

                $pad = max(8, min($cardSidePad, $cardTopPad));
                if ($pad <= 0) {
                    $pad = max(8, (int) round(min($cardW, $cardH) * 0.03));
                }
                $qrAreaX = $cardX + $pad;
                $qrAreaY = $cardY + $pad;
                $qrAreaW = max(40, $cardW - 2 * $pad);
                $qrAreaH = max(40, $cardH - 2 * $pad);
                $qrSide  = (int) min($qrAreaW, $qrAreaH);
                $qrPasteX = (int) ($qrAreaX + ($qrAreaW - $qrSide) / 2);
                $qrPasteY = (int) ($qrAreaY + ($qrAreaH - $qrSide) / 2);

                $qrSized = resize_image_exact($qrRaw, $qrSide, $qrSide);
                qr_copy_image_with_alpha($frame, $qrSized, $qrPasteX, $qrPasteY);
                imagedestroy($qrSized);

                if ($showName) {
                    $cap = qr_caption_box_from_config($config, $frameW, $frameH, $qrPasteX, $qrPasteY, $qrSide);
                    $inkRgb = $labelColorRgb;
                    if (!empty($config['caption_color'])) {
                        $inkRgb = qr_style_hex_to_rgb((string) $config['caption_color']);
                    }
                    qr_draw_business_name_in_box(
                        $frame,
                        $name,
                        $cap['x'],
                        $cap['y'],
                        $cap['w'],
                        $cap['h'],
                        $cap['size'],
                        $inkRgb
                    );
                }

                $usedFrame  = true;
                $finalImage = $frame;
            } else {
                imagedestroy($frame);
            }
        }
    }

    // 2) Fallback: plain QR + business name below
    if (!$usedFrame) {
        $qrW = imagesx($qrRaw);
        $qrH = imagesy($qrRaw);
        $name = qr_business_name_only($label);
        $showName = $name !== '' && qr_flag_enabled($config['show_label_on_poster'] ?? $config['poster_caption'] ?? true);

        $charH = imagefontheight(BITMAP_FONT);
        $finalH = $showName ? ($qrH + 16 + $charH + 10) : $qrH;

        $final = imagecreatetruecolor($qrW, $finalH);
        imagealphablending($final, false);
        imagesavealpha($final, true);

        $white = imagecolorallocate($final, 255, 255, 255);
        imagefilledrectangle($final, 0, 0, $qrW, $finalH, $white);

        qr_copy_image_with_alpha($final, $qrRaw, 0, 0);

        if ($showName) {
            $textColor = imagecolorallocate($final, $labelColorRgb[0], $labelColorRgb[1], $labelColorRgb[2]);
            $charW = imagefontwidth(BITMAP_FONT);
            $maxChars = (int) floor(($qrW - 10) / max(1, $charW));
            $text = (strlen($name) > $maxChars) ? substr($name, 0, $maxChars) : $name;
            $textW = $charW * strlen($text);
            $x = (int) (($qrW - $textW) / 2);
            imagestring($final, BITMAP_FONT, $x, $qrH + 16, $text, $textColor);
        }

        $finalImage = $final;
    }

    return $finalImage;
}

/** Public voting URL encoded inside generated QR images. */
function make_qr_url_for_choice(int $choice_id): string
{
    global $conn;
    return qr_vote_url_for_choice($choice_id, $conn);
}

/** Slug used for qrcodes/{slug}.png (must match generateAndSaveQR). */
function qr_slug_from_choice_name(string $name): string
{
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $name), '_'));
}

/** Resolve choice_id from recipient shown on QR email logs. */
function qr_choice_id_by_recipient(mysqli $conn, string $email, string $name): ?int
{
    $email = trim($email);
    $name  = trim($name);
    if ($email !== '' && $name !== '') {
        $stmt = $conn->prepare(
            'SELECT choice_id FROM tbl_choices WHERE email = ? AND choice_name = ? ORDER BY choice_id DESC LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('ss', $email, $name);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                return (int)$row['choice_id'];
            }
        }
    }
    if ($email !== '') {
        $stmt = $conn->prepare('SELECT choice_id FROM tbl_choices WHERE email = ? ORDER BY choice_id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                return (int)$row['choice_id'];
            }
        }
    }
    if ($name !== '') {
        $stmt = $conn->prepare('SELECT choice_id FROM tbl_choices WHERE choice_name = ? ORDER BY choice_id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                return (int)$row['choice_id'];
            }
        }
    }
    return null;
}

/** Public URL path for a saved QR PNG (qrcodes/{slug}.png) when the file exists. */
function qr_public_path_for_choice_name(string $name): ?string
{
    $slug = qr_slug_from_choice_name($name);
    if ($slug === '') {
        return null;
    }
    $fs = __DIR__ . '/qrcodes/' . $slug . '.png';
    return is_file($fs) ? 'qrcodes/' . $slug . '.png' : null;
}

/** Absolute disk path for qrcodes/{slug}.png when the file exists. */
function qr_png_disk_path_for_choice_name(string $name): ?string
{
    $slug = qr_slug_from_choice_name($name);
    if ($slug === '') {
        return null;
    }
    $path = __DIR__ . '/qrcodes/' . $slug . '.png';
    return is_file($path) ? $path : null;
}

/** Ensure framed QR PNG exists on disk; returns absolute filesystem path. */
function ensure_qr_png_for_choice(int $choice_id, bool $force = false): string
{
    global $conn;

    if (!$force && $choice_id > 0 && $conn instanceof mysqli) {
        $stmt = $conn->prepare('SELECT choice_name FROM tbl_choices WHERE choice_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $choice_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                $existing = qr_png_disk_path_for_choice_name((string)($row['choice_name'] ?? ''));
                if ($existing !== null) {
                    return $existing;
                }
            }
        }
    }

    $result = generateAndSaveQR($choice_id, $force);
    $genStatus = (string) ($result['status'] ?? '');
    if ($genStatus !== 'success' && $genStatus !== 'skipped') {
        throw new RuntimeException($result['message'] ?? 'QR generation failed.');
    }
    $href = $result['path'] ?? '';
    if ($href === '') {
        throw new RuntimeException('QR path missing after generation.');
    }
    return __DIR__ . '/' . ltrim($href, '/');
}

/**
 * Load multiple choices in one query.
 * @return array<int, array{choice_id:int, choice_name:string, email:string, event_id:?int}>
 */
function qr_load_choices_batch(mysqli $conn, array $choiceIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $choiceIds))));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT choice_id, choice_name, email, event_id FROM tbl_choices WHERE choice_id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $cid = (int)($row['choice_id'] ?? 0);
        if ($cid > 0) {
            $map[$cid] = $row;
        }
    }
    $stmt->close();
    return $map;
}

/** Self-service portal link for a nominee (download / re-share assets). */
function make_portal_url_for_choice(int $choice_id): string
{
    global $conn;
    return qr_portal_url_for_choice($choice_id, $conn);
}
