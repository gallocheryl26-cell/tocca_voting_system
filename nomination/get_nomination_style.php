<?php
require_once __DIR__ . '/../tocca_admin/get_logo.php';

if (!function_exists('sanitizeColor')) {
    function sanitizeColor($color, $default = '#000000') {
        $color = trim($color);
        if (preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $color)) {
            return $color;
        }
        return $default;
    }
}

$rawTextColor = getConfig('nominationTextColor', '#000000');
$nominationTextColor = sanitizeColor($rawTextColor, '#000000');

header('Content-Type: application/json');
echo json_encode([
    'bgColor'     => $nominationBgColor,
    'headerImage' => $nominationBannerPath,
    'favicon'     => $faviconPath,
    'textColor'   => $nominationTextColor,
]);