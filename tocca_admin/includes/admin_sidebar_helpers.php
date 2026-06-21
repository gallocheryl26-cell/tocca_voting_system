<?php
declare(strict_types=1);

if (!function_exists('admin_hex_luminance')) {
    /** Relative luminance 0–1 for #RGB hex colors. */
    function admin_hex_luminance(string $hex): float
    {
        $hex = ltrim(trim($hex), '#');
        if ($hex === '') {
            return 0.5;
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return 0.5;
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}

if (!function_exists('admin_pick_sidebar_text_color')) {
    /** Ensure readable sidebar labels for the chosen background. */
    function admin_pick_sidebar_text_color(string $bgHex, string $preferredText): string
    {
        $bgLum = admin_hex_luminance($bgHex);
        $text = trim($preferredText) !== '' ? trim($preferredText) : '#ffffff';
        $textLum = admin_hex_luminance($text);

        if ($bgLum < 0.45 && $textLum < 0.45) {
            return '#ffffff';
        }
        if ($bgLum >= 0.55 && $textLum > 0.55) {
            return '#1e293b';
        }

        return $text;
    }
}

if (!function_exists('admin_sidebar_state_colors')) {
    /** @return array{active_top: string, active_nested: string, open_parent: string, hover: string} */
    function admin_sidebar_state_colors(string $bgHex): array
    {
        if (admin_hex_luminance($bgHex) < 0.45) {
            return [
                'active_top'    => 'rgba(255, 255, 255, 0.22)',
                'active_nested' => 'rgba(255, 255, 255, 0.30)',
                'open_parent'   => 'rgba(255, 255, 255, 0.10)',
                'hover'         => 'rgba(255, 255, 255, 0.12)',
            ];
        }

        return [
            'active_top'    => 'rgba(15, 23, 42, 0.12)',
            'active_nested' => 'rgba(15, 23, 42, 0.16)',
            'open_parent'   => 'rgba(15, 23, 42, 0.06)',
            'hover'         => 'rgba(15, 23, 42, 0.08)',
        ];
    }
}
