<?php
declare(strict_types=1);

require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/choice_token.php';
require_once __DIR__ . '/../tocca_admin/qr_url.php';
require_once __DIR__ . '/../tocca_admin/qr_engine.php';
require_once __DIR__ . '/../tocca_admin/qr_style_config.php';

$token = trim((string)($_GET['t'] ?? ''));
$type  = strtolower(trim((string)($_GET['type'] ?? 'poster')));
$inline = isset($_GET['inline']) && $_GET['inline'] === '1';

$row = $token !== '' ? choice_token_lookup($conn, $token) : null;
if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$choiceId   = (int)$row['choice_id'];
$choiceName = (string)$row['choice_name'];
$voteUrl    = qr_vote_url_for_choice($choiceId, $conn);
$filenameSafe = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $choiceName));

if ($type === 'poster') {
    $posterAbs = __DIR__ . '/../tocca_admin/qrcodes/' . $filenameSafe . '.png';
    if (!is_file($posterAbs)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Poster not generated yet.';
        exit;
    }

    header('Content-Type: image/png');
    header('Content-Length: ' . (string)filesize($posterAbs));
    if (!$inline) {
        header('Content-Disposition: attachment; filename="' . $filenameSafe . '_poster.png"');
    }
    readfile($posterAbs);
    exit;
}

$size = $type === 'sticker' ? 512 : 768;

if ($type === 'social_poster') {
    require_once __DIR__ . '/../tocca_admin/qr_frame_presets.php';
    $img = qr_compose_poster_for_choice($conn, $choiceId, 'social');
    if (!$img) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Social poster could not be generated.';
        exit;
    }
    ob_start();
    imagepng($img, null, 6);
    $png = ob_get_clean();
    imagedestroy($img);
    header('Content-Type: image/png');
    header('Content-Length: ' . (string)strlen($png));
    if (!$inline) {
        header('Content-Disposition: attachment; filename="' . $filenameSafe . '_social.png"');
    }
    echo $png;
    exit;
}

$style = qr_style_load_config($conn);
$img = qr_generate_png_resource($voteUrl, $size, $style);
if (!$img) {
    http_response_code(500);
    exit('QR generation failed');
}
$bgRgb = qr_style_hex_to_rgb((string) ($style['bg_color'] ?? '#ffffff'));
qr_flatten_to_background($img, $bgRgb);
ob_start();
imagepng($img, null, 6);
$png = ob_get_clean();
imagedestroy($img);

header('Content-Type: image/png');
header('Content-Length: ' . (string)strlen($png));
if (!$inline) {
    $suffix = $type === 'sticker' ? '_sticker' : '_qr';
    header('Content-Disposition: attachment; filename="' . $filenameSafe . $suffix . '.png"');
}
echo $png;
