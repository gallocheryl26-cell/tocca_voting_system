<?php

/**
 * Branded QR generator for the public registration form and tracking page.
 * Uses Endroid (ECC High) with optional center logo, colors, and corner brackets.
 * Query: kind=register|track
 */

declare(strict_types=1);



require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/require_admin_session.php';
require_once __DIR__ . '/../tocca_admin/audit_log.php';
require_once __DIR__ . '/../tocca_admin/qr_engine.php';
require_once __DIR__ . '/../tocca_admin/qr_style_config.php';
require_once __DIR__ . '/../tocca_admin/qr_url.php';

tocca_admin_require_login(false);

$eventId  = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$download = isset($_GET['download']) ? (int) $_GET['download'] : 0;
$auditGen = isset($_GET['audit']) && (string) $_GET['audit'] === '1';
$kind = strtolower(trim((string) ($_GET['kind'] ?? 'register')));
if (!in_array($kind, ['register', 'track', 'vote'], true)) {
    $kind = 'register';
}

if ($eventId < 0) {

    http_response_code(400);

    header('Content-Type: application/json');

    echo json_encode(['error' => 'Invalid event_id']);

    exit;

}



$qrUrl = match ($kind) {
    'track' => qr_tracking_url($conn ?? null),
    'vote' => qr_vote_portal_url($conn ?? null),
    default => qr_nomination_form_url($conn ?? null, $eventId),
};



if (ob_get_length()) {

    @ob_clean();

}



try {

    $style = nomination_qr_resolve_style($conn ?? null);

    $size  = 900;



    $qrImg = qr_generate_png_resource($qrUrl, $size, $style);

    if (!$qrImg) {

        throw new RuntimeException('Failed to create QR image.');

    }



    if (!empty($style['use_corner_brackets'])) {

        $fg = qr_style_hex_to_rgb((string) ($style['fg_color'] ?? '#010066'));

        qr_apply_corner_brackets($qrImg, $fg);

    }

    if ($conn instanceof mysqli) {
        if ($download === 1) {
            audit_log_nomination_qr($conn, $eventId, 'export', null, $kind);
        }
    }

    header('Content-Type: image/png');

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    header('Pragma: no-cache');



    if ($download === 1) {

        $suffix = $eventId > 0 ? '-' . $eventId : '';
        $fileBase = match ($kind) {
            'track' => 'tracking-qr',
            'vote' => 'voting-qr',
            default => 'registration-qr',
        };
        header('Content-Disposition: attachment; filename="' . $fileBase . $suffix . '.png"');

    }



    imagepng($qrImg);

    imagedestroy($qrImg);

} catch (Throwable $e) {

    if (ob_get_length()) {

        @ob_clean();

    }

    http_response_code(500);

    header('Content-Type: application/json');

    echo json_encode(['error' => 'Unable to generate QR code', 'detail' => $e->getMessage()]);

}

exit;

