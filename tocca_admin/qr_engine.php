<?php
declare(strict_types=1);

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/qr_style_config.php')) {
    require_once __DIR__ . '/qr_style_config.php';
}

/**
 * Candidate logo paths when no custom upload is configured.
 */
function qr_logo_candidates(): array
{
    $dir = __DIR__;
    return [
        $dir . '/img/qr_center_logo.png',
        $dir . '/img/mini_logo.png',
        $dir . '/img/tatakormoclogo.png',
        $dir . '/img/default-mini.png',
        $dir . '/img/default-logo.png',
    ];
}

function qr_style_defaults_array(): array
{
    if (function_exists('qr_style_default_values')) {
        return qr_style_default_values();
    }
    return [
        'use_center_logo' => true,
        'logo_path'       => '',
        'logo_path_absolute' => '',
        'fg_color'        => '#000000',
        'bg_color'        => '#ffffff',
        'logo_size_pct'   => 18,
        'label_color'     => '#000000',
    ];
}

function qr_resolve_logo_path(array $style = []): ?string
{
    if (!empty($style['logo_path_absolute']) && is_file($style['logo_path_absolute'])) {
        return $style['logo_path_absolute'];
    }

    if (!empty($style['logo_path'])) {
        $abs = function_exists('qr_style_absolute_logo_path')
            ? qr_style_absolute_logo_path((string)$style['logo_path'])
            : __DIR__ . '/' . ltrim((string)$style['logo_path'], '/');
        if ($abs !== '' && is_file($abs)) {
            return $abs;
        }
    }

    foreach (qr_logo_candidates() as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Load a logo PNG/GIF with alpha preserved (fixes black boxes from Endroid merge).
 */
function qr_load_logo_image(string $path): ?\GdImage
{
    if ($path === '' || !is_file($path)) {
        return null;
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        return null;
    }

    $img = @imagecreatefromstring($bytes);
    if (!$img) {
        return null;
    }

    imagealphablending($img, false);
    imagesavealpha($img, true);
    qr_normalize_logo_alpha($img);

    return $img;
}

/**
 * Remove common PNG export artifacts (black/white fringes marked as opaque).
 */
function qr_normalize_logo_alpha(\GdImage $img): void
{
    $w = imagesx($img);
    $h = imagesy($img);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $a = ($rgba >> 24) & 0x7F;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;

            if ($a >= 120) {
                imagesetpixel($img, $x, $y, $transparent);
                continue;
            }

            if ($r >= 248 && $g >= 248 && $b >= 248) {
                imagesetpixel($img, $x, $y, $transparent);
                continue;
            }

            if ($r <= 28 && $g <= 28 && $b <= 28 && $a >= 100) {
                imagesetpixel($img, $x, $y, $transparent);
            }
        }
    }

    imagealphablending($img, false);
    imagesavealpha($img, true);
}

/**
 * @return \GdImage
 */
function qr_resize_logo_preserving_alpha(\GdImage $src, int $targetW, int $targetH): \GdImage
{
    $dst = imagecreatetruecolor($targetW, $targetH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
    imagealphablending($dst, true);
    imagecopyresampled(
        $dst,
        $src,
        0,
        0,
        0,
        0,
        $targetW,
        $targetH,
        imagesx($src),
        imagesy($src)
    );
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    qr_normalize_logo_alpha($dst);

    return $dst;
}

function qr_copy_image_with_alpha(\GdImage $dst, \GdImage $src, int $dstX, int $dstY): void
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    $dw = imagesx($dst);
    $dh = imagesy($dst);

    imagealphablending($dst, true);
    imagesavealpha($dst, true);

    for ($y = 0; $y < $sh; $y++) {
        for ($x = 0; $x < $sw; $x++) {
            $rgba = imagecolorat($src, $x, $y);
            $a = ($rgba >> 24) & 0x7F;
            if ($a >= 127) {
                continue;
            }

            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;

            $tx = $dstX + $x;
            $ty = $dstY + $y;
            if ($tx < 0 || $ty < 0 || $tx >= $dw || $ty >= $dh) {
                continue;
            }

            $opacity = (127 - $a) / 127;
            if ($opacity >= 0.98) {
                $color = imagecolorallocate($dst, $r, $g, $b);
                if ($color !== false) {
                    imagesetpixel($dst, $tx, $ty, $color);
                }
                continue;
            }

            if ($opacity < 0.04) {
                continue;
            }

            $drgba = imagecolorat($dst, $tx, $ty);
            $dr = ($drgba >> 16) & 0xFF;
            $dg = ($drgba >> 8) & 0xFF;
            $db = $drgba & 0xFF;
            $nr = (int) round($dr * (1 - $opacity) + $r * $opacity);
            $ng = (int) round($dg * (1 - $opacity) + $g * $opacity);
            $nb = (int) round($db * (1 - $opacity) + $b * $opacity);
            $color = imagecolorallocate($dst, $nr, $ng, $nb);
            if ($color !== false) {
                imagesetpixel($dst, $tx, $ty, $color);
            }
        }
    }
}

/**
 * Place center logo with true PNG transparency (not Endroid's logo merge).
 *
 * @param array{0:int,1:int,2:int} $bgRgb
 */
function qr_apply_center_logo_gd(\GdImage $qr, string $logoPath, int $logoSizePct, array $bgRgb, bool $punchoutBg = true): bool
{
    $logoSrc = qr_load_logo_image($logoPath);
    if (!$logoSrc) {
        return false;
    }

    $qw = imagesx($qr);
    $qh = imagesy($qr);
    $side = min($qw, $qh);
    $pct = max(8, min(30, $logoSizePct));
    $logoW = max(24, (int) round($side * ($pct / 100)));
    $srcW = imagesx($logoSrc);
    $srcH = imagesy($logoSrc);
    $logoH = max(24, (int) round($logoW * ($srcH / max(1, $srcW))));

    $logoResized = qr_resize_logo_preserving_alpha($logoSrc, $logoW, $logoH);
    imagedestroy($logoSrc);

    $x = (int) round(($qw - $logoW) / 2);
    $y = (int) round(($qh - $logoH) / 2);

    if ($punchoutBg) {
        $bgCol = imagecolorallocate($qr, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
        if ($bgCol !== false) {
            imagefilledrectangle($qr, $x, $y, $x + $logoW, $y + $logoH, $bgCol);
        }
    }

    qr_copy_image_with_alpha($qr, $logoResized, $x, $y);
    imagedestroy($logoResized);

    return true;
}

/**
 * Generate a QR PNG as binary string (Endroid; center logo applied separately in GD).
 *
 * @param array $style Keys: use_center_logo, logo_path, logo_path_absolute, fg_color, bg_color, logo_size_pct
 */
function qr_generate_png_string(string $data, int $size = 800, array $style = []): string
{
    $style = array_merge(qr_style_defaults_array(), $style);
    $size = max(120, $size);

    $fg = function_exists('qr_style_hex_to_rgb')
        ? qr_style_hex_to_rgb((string)$style['fg_color'])
        : [0, 0, 0];
    $bg = function_exists('qr_style_hex_to_rgb')
        ? qr_style_hex_to_rgb((string)$style['bg_color'])
        : [255, 255, 255];

    $builder = Builder::create()
        ->writer(new PngWriter())
        ->data($data)
        ->encoding(new Encoding('UTF-8'))
        ->errorCorrectionLevel(ErrorCorrectionLevel::High)
        ->size($size)
        ->margin(8)
        ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
        ->foregroundColor(new Color($fg[0], $fg[1], $fg[2]))
        ->backgroundColor(new Color($bg[0], $bg[1], $bg[2]));

    return $builder->build()->getString();
}

/**
 * @return \GdImage|false
 */
function qr_generate_png_resource(string $data, int $size = 800, array $style = [])
{
    $style = array_merge(qr_style_defaults_array(), $style);
    $size = max(120, $size);

    $png = qr_generate_png_string($data, $size, $style);
    $img = @imagecreatefromstring($png);
    if (!$img) {
        return false;
    }
    imagealphablending($img, true);
    imagesavealpha($img, true);

    if (!empty($style['use_center_logo'])) {
        $logoPath = qr_resolve_logo_path($style);
        if ($logoPath) {
            $bg = function_exists('qr_style_hex_to_rgb')
                ? qr_style_hex_to_rgb((string)($style['bg_color'] ?? '#ffffff'))
                : [255, 255, 255];
            $pct = (int)($style['logo_size_pct'] ?? 18);
            qr_apply_center_logo_gd($img, $logoPath, $pct, $bg, true);
        }
    }

    if (!empty($style['use_corner_brackets'])) {
        $fg = function_exists('qr_style_hex_to_rgb')
            ? qr_style_hex_to_rgb((string)($style['fg_color'] ?? '#000000'))
            : [0, 0, 0];
        qr_apply_corner_brackets($img, $fg);
    }

    return $img;
}

/**
 * Remove alpha so PNG viewers do not show transparent areas as black.
 *
 * @param array{0:int,1:int,2:int} $bgRgb
 */
function qr_flatten_to_background(\GdImage $img, array $bgRgb): void
{
    $w = imagesx($img);
    $h = imagesy($img);
    $br = max(0, min(255, (int) $bgRgb[0]));
    $bg = max(0, min(255, (int) $bgRgb[1]));
    $bb = max(0, min(255, (int) $bgRgb[2]));
    $bgCol = imagecolorallocate($img, $br, $bg, $bb);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $a = ($rgba >> 24) & 0x7F;
            if ($a >= 127 && $bgCol !== false) {
                imagesetpixel($img, $x, $y, $bgCol);
            }
        }
    }

    imagealphablending($img, true);
    imagesavealpha($img, false);
}

/**
 * Draw L-shaped corner brackets (styled QR frame) on a square QR image.
 *
 * @param array{0:int,1:int,2:int} $fgRgb
 */
function qr_apply_corner_brackets(\GdImage $img, array $fgRgb, int $armLen = 0, int $thickness = 0, int $inset = 0): void
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 1 || $h < 1) {
        return;
    }

    $side = min($w, $h);
    if ($armLen <= 0) {
        $armLen = (int) max(24, round($side * 0.13));
    }
    if ($thickness <= 0) {
        $thickness = (int) max(4, round($side * 0.014));
    }
    if ($inset <= 0) {
        $inset = (int) max(8, round($side * 0.035));
    }

    $color = imagecolorallocate($img, $fgRgb[0], $fgRgb[1], $fgRgb[2]);
    if ($color === false) {
        return;
    }

    $drawCorner = static function (\GdImage $img, int $x, int $y, int $dx, int $dy, int $arm, int $t, int $color): void {
        if ($dx > 0 && $dy > 0) {
            imagefilledrectangle($img, $x, $y, $x + $arm, $y + $t, $color);
            imagefilledrectangle($img, $x, $y, $x + $t, $y + $arm, $color);
            return;
        }
        if ($dx < 0 && $dy > 0) {
            imagefilledrectangle($img, $x - $arm, $y, $x, $y + $t, $color);
            imagefilledrectangle($img, $x - $t, $y, $x, $y + $arm, $color);
            return;
        }
        if ($dx > 0 && $dy < 0) {
            imagefilledrectangle($img, $x, $y - $t, $x + $arm, $y, $color);
            imagefilledrectangle($img, $x, $y - $arm, $x + $t, $y, $color);
            return;
        }
        if ($dx < 0 && $dy < 0) {
            imagefilledrectangle($img, $x - $arm, $y - $t, $x, $y, $color);
            imagefilledrectangle($img, $x - $t, $y - $arm, $x, $y, $color);
        }
    };

    $x0 = $inset;
    $y0 = $inset;
    $x1 = $w - $inset - 1;
    $y1 = $h - $inset - 1;

    $drawCorner($img, $x0, $y0, 1, 1, $armLen, $thickness, $color);
    $drawCorner($img, $x1, $y0, -1, 1, $armLen, $thickness, $color);
    $drawCorner($img, $x0, $y1, 1, -1, $armLen, $thickness, $color);
    $drawCorner($img, $x1, $y1, -1, -1, $armLen, $thickness, $color);
}

/**
 * Style used for registration QR codes (center logo + optional corner brackets).
 */
function nomination_qr_resolve_style($conn): array
{
    $style = function_exists('qr_style_load_config') && $conn instanceof mysqli
        ? qr_style_load_config($conn)
        : qr_style_defaults_array();

    if (strtoupper((string) ($style['fg_color'] ?? '')) === '#000000') {
        $style['fg_color'] = '#010066';
    }
    if (strtoupper((string) ($style['bg_color'] ?? '')) === '#FFFFFF') {
        $style['bg_color'] = '#EEF3FB';
    }

    $style['use_corner_brackets'] = true;
    return $style;
}
