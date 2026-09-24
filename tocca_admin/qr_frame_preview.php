<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/qr_frame_config.php';
require_once __DIR__ . '/qr_utils.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Helper: send JSON and exit.
 */
function qr_preview_respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

/**
 * Simple int input helper.
 */
function qr_preview_int_input(string $key, $default)
{
    return isset($_POST[$key]) && $_POST[$key] !== '' ? (int) $_POST[$key] : (int) $default;
}

/**
 * Accept uploaded preview frame (admin_settings uses frame_file).
 */
function qr_preview_resolve_frame_path(array &$config): void
{
    $uploadKeys = ['frame_file', 'frame_image'];
    foreach ($uploadKeys as $key) {
        if (
            empty($_FILES[$key]['tmp_name']) ||
            !is_uploaded_file($_FILES[$key]['tmp_name']) ||
            (int)($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            continue;
        }

        $config['frame_path_absolute'] = $_FILES[$key]['tmp_name'];
        $config['use_frame'] = true;
        return;
    }

    $config['frame_path_absolute'] = qr_frame_absolute_path($config['frame_path'] ?? '');
}

try {

    /* =========================
       GET  → generate QR via choice_id
       ========================= */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $choiceId = isset($_GET['choice_id']) ? (int) $_GET['choice_id'] : 0;
        if ($choiceId <= 0) {
            qr_preview_respond(400, [
                'status'  => 'error',
                'message' => 'Missing or invalid choice_id.',
            ]);
        }

        $overrides = extract_frame_options_from_request($_GET);
        $overrides['preview'] = true;

        $result = generateAndSaveQR($choiceId, false, $overrides);

        if (($result['status'] ?? '') === 'error') {
            qr_preview_respond(400, $result);
        }

        qr_preview_respond(200, $result);
    }

    /* =========================
       POST → live frame preview (no saving)
       ========================= */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        qr_preview_respond(405, [
            'status'  => 'error',
            'message' => 'Method not allowed',
        ]);
    }

    $defaults = qr_frame_default_values();
    $defaults = array_merge([
        'frame_path'         => 'img/qr_frame.jpg',
        'frame_box_x'        => 500,
        'frame_box_y'        => 1000,
        'frame_box_w'        => 660,
        'frame_box_h'        => 660,
        'card_side_pad'      => 24,
        'card_top_pad'       => 18,
        'label_strip_h_min'  => 72,
        'label_strip_h_max'  => 240,
        'gap_qr_to_label'    => 10,
        'qr_side_min'        => 360,
        'label_side_pad'     => 10,
        'label_top_pad'      => 8,
        'label_bottom_pad'   => 10,
        'label_line_spacing' => 6,
        'label_font_size'    => 26,
    ], $defaults);

    $previewChoiceId = (int) ($_POST['preview_choice_id'] ?? 0);
    $label = trim((string) ($_POST['label'] ?? ''));
    $showLabel = $label !== '' || $previewChoiceId > 0;

    $config = [
        'use_frame'            => isset($_POST['use_frame']) && (
                                  $_POST['use_frame'] === '1' ||
                                  $_POST['use_frame'] === 'true' ||
                                  $_POST['use_frame'] === 'on'
                                ),
        'show_label_on_poster' => $showLabel,
        'frame_path'         => trim($_POST['frame_path'] ?? $defaults['frame_path']),
        'frame_box_x'        => qr_preview_int_input('frame_box_x',        $defaults['frame_box_x']),
        'frame_box_y'        => qr_preview_int_input('frame_box_y',        $defaults['frame_box_y']),
        'frame_box_w'        => qr_preview_int_input('frame_box_w',        $defaults['frame_box_w']),
        'frame_box_h'        => qr_preview_int_input('frame_box_h',        $defaults['frame_box_h']),
        'card_side_pad'      => qr_preview_int_input('card_side_pad',      $defaults['card_side_pad']),
        'card_top_pad'       => qr_preview_int_input('card_top_pad',       $defaults['card_top_pad']),
        'label_strip_h_min'  => qr_preview_int_input('label_strip_h_min',  $defaults['label_strip_h_min']),
        'label_strip_h_max'  => qr_preview_int_input('label_strip_h_max',  $defaults['label_strip_h_max']),
        'gap_qr_to_label'    => qr_preview_int_input('gap_qr_to_label',    $defaults['gap_qr_to_label']),
        'qr_side_min'        => qr_preview_int_input('qr_side_min',        $defaults['qr_side_min']),
        'label_side_pad'     => qr_preview_int_input('label_side_pad',     $defaults['label_side_pad']),
        'label_top_pad'      => qr_preview_int_input('label_top_pad',      $defaults['label_top_pad']),
        'label_bottom_pad'   => qr_preview_int_input('label_bottom_pad',   $defaults['label_bottom_pad']),
        'label_line_spacing' => qr_preview_int_input('label_line_spacing', $defaults['label_line_spacing']),
        'label_font_size'    => qr_preview_int_input('label_font_size',    $defaults['label_font_size']),
        'caption_box_x'      => qr_preview_int_input('caption_box_x',      0),
        'caption_box_y'      => qr_preview_int_input('caption_box_y',      0),
        'caption_box_w'      => qr_preview_int_input('caption_box_w',      0),
        'caption_box_h'      => qr_preview_int_input('caption_box_h',      0),
        'caption_font_size'  => qr_preview_int_input('caption_font_size',  0),
    ];

    qr_preview_resolve_frame_path($config);

    global $conn;
    $style = qr_style_load_config($conn);
    $style = qr_style_merge_overrides($style, qr_style_from_post($_POST));

    if (
        !empty($_FILES['qr_logo_file']['tmp_name']) &&
        is_uploaded_file($_FILES['qr_logo_file']['tmp_name']) &&
        (int)($_FILES['qr_logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
    ) {
        $style['logo_path_absolute'] = $_FILES['qr_logo_file']['tmp_name'];
        $style['use_center_logo'] = true;
    }

    $config['label_color'] = $style['label_color'] ?? '#000000';
    $captionColor = trim((string) ($_POST['caption_color'] ?? ''));
    if ($captionColor !== '') {
        $config['caption_color'] = $captionColor;
        $config['label_color'] = $captionColor;
    }
    $config['show_label_on_poster'] = !isset($_POST['poster_caption'])
        || in_array((string) $_POST['poster_caption'], ['1', 'true', 'on', 'yes'], true);

    if ($previewChoiceId > 0 && $conn instanceof mysqli) {
        $eventId = 0;
        if (function_exists('et_get_active_event_id')) {
            $eventId = (int) (et_get_active_event_id($conn) ?? 0);
        } elseif (function_exists('admin_active_event_id')) {
            $eventId = (int) (admin_active_event_id($conn) ?? 0);
        }
        $st = $eventId > 0
            ? $conn->prepare('SELECT choice_name FROM tbl_choices WHERE choice_id = ? AND event_id = ? LIMIT 1')
            : $conn->prepare('SELECT choice_name FROM tbl_choices WHERE choice_id = ? LIMIT 1');
        if ($st) {
            if ($eventId > 0) {
                $st->bind_param('ii', $previewChoiceId, $eventId);
            } else {
                $st->bind_param('i', $previewChoiceId);
            }
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            $choiceName = trim((string) ($row['choice_name'] ?? ''));
            if ($choiceName !== '') {
                $label = $choiceName;
            }
        }
    }
    if ($showLabel && $label === '') {
        $captionFile = __DIR__ . '/includes/qr_poster_caption.php';
        if (is_file($captionFile)) {
            require_once $captionFile;
        }
        $label = function_exists('qr_poster_sample_caption')
            ? qr_poster_sample_caption()
            : 'Sample Cafe';
    }

    $qrData = rtrim(qr_public_base_url($GLOBALS['conn'] ?? null), '/')
        . '/e-vote-final-enhanced/v.php?t=PREVIEW';

    $qrRaw = qr_generate_png_resource($qrData, 640, $style);
    if (!$qrRaw) {
        qr_preview_respond(500, [
            'status'  => 'error',
            'message' => 'Unable to render QR base image.',
        ]);
    }

    $bgRgb = qr_style_hex_to_rgb((string) ($style['bg_color'] ?? '#ffffff'));
    qr_prepare_qr_for_frame_paste($qrRaw, $bgRgb);

    $usedFrame = false;
    $final = compose_qr_image($qrRaw, $label, $config, $usedFrame);

    if (!$final) {
        imagedestroy($qrRaw);
        qr_preview_respond(500, [
            'status'  => 'error',
            'message' => 'Failed to compose preview.',
        ]);
    }

    $final = scale_image_max_width($final, 960);

    ob_start();
    imagepng($final, null, 6);
    $previewPng = ob_get_clean();

    imagedestroy($final);
    imagedestroy($qrRaw);

    if ($previewPng === false || $previewPng === '') {
        qr_preview_respond(500, [
            'status'  => 'error',
            'message' => 'Failed to capture composed image.',
        ]);
    }

    $base64 = 'data:image/png;base64,' . base64_encode($previewPng);

    qr_preview_respond(200, [
        'status'    => 'ok',
        'usedFrame' => $usedFrame,
        'image'     => $base64,
        'message'   => $usedFrame ? 'Frame applied.' : 'Using plain QR fallback (check frame path).',
    ]);

} catch (Throwable $e) {
    qr_preview_respond(500, [
        'status'  => 'error',
        'message' => 'Preview failed: ' . $e->getMessage(),
    ]);
}
