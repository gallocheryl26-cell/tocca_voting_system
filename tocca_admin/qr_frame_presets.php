<?php
declare(strict_types=1);

require_once __DIR__ . '/qr_frame_config.php';

/**
 * Named layout presets (relative to detected frame image size).
 */
function qr_frame_preset_catalog(): array
{
    return [
        'center_fit' => [
            'label'       => 'Center fit (recommended)',
            'description' => 'Maximizes QR inside decorative frames. Best for uploaded border artwork.',
        ],
        'standard' => [
            'label'       => 'Standard A4',
            'description' => 'Balanced layout with room for a business name below the QR.',
        ],
        'compact' => [
            'label'       => 'Compact',
            'description' => 'Smaller QR card — good for counter tents.',
        ],
        'large_qr' => [
            'label'       => 'Large QR',
            'description' => 'Maximizes QR size on the poster.',
        ],
        'social' => [
            'label'       => 'Social square',
            'description' => 'Square QR area for Instagram / Facebook posts.',
        ],
    ];
}

/**
 * Compute layout numbers for a preset given frame pixel dimensions.
 */
function qr_frame_preset_layout(string $preset, int $frameW, int $frameH): array
{
    $frameW = max(400, $frameW);
    $frameH = max(400, $frameH);

    switch ($preset) {
        case 'center_fit':
            $sideLen = (int)round(min($frameW, $frameH) * 0.88);
            $boxW = $sideLen;
            $boxH = $sideLen;
            $pad = max(8, (int)round($sideLen * 0.035));
            $boxX = (int)round(($frameW - $boxW) / 2);
            $boxY = (int)round(($frameH - $boxH) / 2);
            return [
                'frame_box_x'       => $boxX,
                'frame_box_y'       => $boxY,
                'frame_box_w'       => $boxW,
                'frame_box_h'       => $boxH,
                'box_x'             => $boxX,
                'box_y'             => $boxY,
                'box_w'             => $boxW,
                'box_h'             => $boxH,
                'card_side_pad'     => $pad,
                'card_top_pad'      => $pad,
                'qr_side_min'       => max(40, (int)round($sideLen * 0.82)),
                'label_strip_h_min' => 0,
                'label_strip_h_max' => 0,
                'gap_qr_to_label'   => 0,
            ];

        case 'compact':
            $boxW = (int)round($frameW * 0.7);
            $boxH = (int)round($frameH * 0.7);
            $side = (int)round(min($boxW, $boxH) * 0.25);
            break;

        case 'large_qr':
            $boxW = (int)round($frameW * 0.8);
            $boxH = (int)round($frameH * 0.8);
            $side = (int)round(min($boxW, $boxH) * 0.5);
            break;

        case 'social':
            $sideLen = (int)round(min($frameW, $frameH) * 0.72);
            $boxW = $sideLen;
            $boxH = $sideLen;
            $side = (int)round($sideLen * 0.62);
            break;

        case 'standard':
        default:
            $boxW = (int)round($frameW * 0.8);
            $boxH = (int)round($frameH * 0.8);
            $side = (int)round(min($boxW, $boxH) * 0.35);
            break;
    }

    $boxX = (int)round(($frameW - $boxW) / 2);
    $boxY = (int)round(($frameH - $boxH) / 2);
    $sidePad = (int)round(($boxW - $side) / 2);
    $topPad = (int)round(($boxH - $side) / 2);

    if ($preset === 'social') {
        // Leave a bit more room for the label strip at the bottom.
        $labelMin = 64;
        $topPad = max(12, (int)round($boxH * 0.08));
        $side = min($side, $boxW - 2 * max(12, $sidePad));
        $side = min($side, $boxH - $labelMin - $topPad - 10);
    }

    return [
        'frame_box_x'       => $boxX,
        'frame_box_y'       => $boxY,
        'frame_box_w'       => $boxW,
        'frame_box_h'       => $boxH,
        'box_x'             => $boxX,
        'box_y'             => $boxY,
        'box_w'             => $boxW,
        'box_h'             => $boxH,
        'card_side_pad'     => max(0, $sidePad),
        'card_top_pad'      => max(0, $topPad),
        'qr_side_min'       => max(40, $side),
        'label_strip_h_min' => $preset === 'social' ? 56 : 72,
        'label_strip_h_max' => $preset === 'social' ? 100 : 140,
    ];
}

function qr_frame_detect_size(string $framePath): array
{
    if ($framePath === '' || !is_file($framePath)) {
        return [1600, 2000];
    }
    $info = @getimagesize($framePath);
    if (!$info) {
        return [1600, 2000];
    }
    return [(int)$info[0], (int)$info[1]];
}

/**
 * Merge a preset layout into a generation config (does not change frame_path).
 */
function qr_frame_apply_preset_to_config(array $config, string $preset): array
{
    $framePath = $config['frame_path_absolute']
        ?? (isset($config['frame_path']) ? qr_frame_absolute_path((string)$config['frame_path']) : '');
    [$w, $h] = qr_frame_detect_size((string)$framePath);
    $layout = qr_frame_preset_layout($preset, $w, $h);
    return array_merge($config, $layout);
}

/* ---------- Category → frame mappings ---------- */

function qr_category_frames_load($conn): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    if (!$conn instanceof mysqli) {
        return [];
    }

    if (!$stmt = $conn->prepare("SELECT config_value FROM tbl_config WHERE config_key = 'qr_category_frames' LIMIT 1")) {
        return [];
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return [];
    }
    $data = json_decode($row['config_value'], true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

function qr_category_frames_save($conn, array $frames): bool
{
    $clean = [];
    foreach ($frames as $catId => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = (int)$catId;
        if ($id <= 0) {
            continue;
        }
        $path = trim((string)($entry['frame_path'] ?? ''));
        if ($path === '') {
            continue;
        }
        $item = ['frame_path' => $path];
        foreach ([
            'frame_box_x', 'frame_box_y', 'frame_box_w', 'frame_box_h',
            'card_side_pad', 'card_top_pad', 'qr_side_min',
        ] as $k) {
            if (isset($entry[$k]) && $entry[$k] !== '' && is_numeric($entry[$k])) {
                $item[$k] = (int)$entry[$k];
            }
        }
        $clean[(string)$id] = $item;
    }

    $json = json_encode($clean);
    if ($json === false) {
        return false;
    }

    if (!$stmt = $conn->prepare("INSERT INTO tbl_config (config_key, config_value) VALUES ('qr_category_frames', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)")) {
        return false;
    }
    $stmt->bind_param('s', $json);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function qr_fetch_event_categories(mysqli $conn, int $eventId): array
{
    if ($eventId <= 0) {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT category_id, category_name FROM tbl_categories WHERE event_id = ? ORDER BY category_name ASC'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    $stmt->close();
    return $out;
}

function qr_choice_primary_category_id(mysqli $conn, int $choiceId): ?int
{
    if ($choiceId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT c.category_id
         FROM tbl_categories c
         INNER JOIN tbl_questions q ON q.category_id = c.category_id
         INNER JOIN tbl_question_choices qc ON qc.question_id = q.question_id
         WHERE qc.choice_id = ?
         ORDER BY c.category_name ASC
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $choiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }
    return (int)$row['category_id'];
}

/**
 * Apply per-category frame overrides for a nominee when generating QR posters.
 */
function qr_resolve_frame_for_choice(mysqli $conn, int $choiceId, array $config): array
{
    $catId = qr_choice_primary_category_id($conn, $choiceId);
    if (!$catId) {
        return $config;
    }

    $maps = qr_category_frames_load($conn);
    $entry = $maps[(string)$catId] ?? null;
    if (!is_array($entry) || empty($entry['frame_path'])) {
        return $config;
    }

    $path = trim((string)$entry['frame_path']);
    if ($path !== '') {
        $config['frame_path'] = $path;
        $config['frame_path_absolute'] = qr_frame_absolute_path($path);
    }

    $map = [
        'frame_box_x'   => 'box_x',
        'frame_box_y'   => 'box_y',
        'frame_box_w'   => 'box_w',
        'frame_box_h'   => 'box_h',
        'card_side_pad' => 'card_side_pad',
        'card_top_pad'  => 'card_top_pad',
        'qr_side_min'   => 'qr_side_min',
    ];
    foreach ($map as $src => $dest) {
        if (isset($entry[$src]) && is_numeric($entry[$src])) {
            $val = (int)$entry[$src];
            $config[$src] = $val;
            $config[$dest] = $val;
        }
    }

    return $config;
}

function upload_category_frame_image(int $categoryId): ?string
{
    $field = 'category_frame_file_' . $categoryId;
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    if (!empty($_FILES[$field]['size']) && $_FILES[$field]['size'] > 8 * 1024 * 1024) {
        return null;
    }

    $targetDirAbs = __DIR__ . '/img/';
    $targetDirRel = 'img/';
    if (!is_dir($targetDirAbs)) {
        @mkdir($targetDirAbs, 0775, true);
    }

    $originalName = basename($_FILES[$field]['name']);
    $cleanName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $originalName);
    $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return null;
    }

    $unique = 'qr_cat_' . $categoryId . '_' . time() . '_' . $cleanName;
    $targetAbs = $targetDirAbs . $unique;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $targetAbs)) {
        return $targetDirRel . $unique;
    }

    return null;
}

function qr_category_frames_from_post(array $post, array $categories): array
{
    $paths = $post['category_frame_path'] ?? [];
    if (!is_array($paths)) {
        $paths = [];
    }

    $frames = [];
    foreach ($categories as $cat) {
        $catId = (int)($cat['category_id'] ?? 0);
        if ($catId <= 0) {
            continue;
        }

        $path = trim((string)($paths[$catId] ?? $paths[(string)$catId] ?? ''));
        $uploaded = upload_category_frame_image($catId);
        if ($uploaded) {
            $path = $uploaded;
        }
        if ($path === '') {
            continue;
        }

        $frames[(string)$catId] = ['frame_path' => $path];
    }

    return $frames;
}

/**
 * Build a composed poster image for a choice (used for portal social download).
 *
 * @return \GdImage|null
 */
function qr_compose_poster_for_choice(mysqli $conn, int $choiceId, ?string $preset = null)
{
    require_once __DIR__ . '/qr_utils.php';

    $stmt = $conn->prepare('SELECT choice_name FROM tbl_choices WHERE choice_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $choiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }

    $choiceName = trim((string)$row['choice_name']);
    if ($choiceName === '') {
        return null;
    }

    try {
        $qrData = qr_vote_url_for_choice($choiceId, $conn);
    } catch (Throwable $e) {
        return null;
    }

    $config = resolve_generation_config([]);
    $config = qr_resolve_frame_for_choice($conn, $choiceId, $config);

    if ($preset !== null && $preset !== '') {
        $config = qr_frame_apply_preset_to_config($config, $preset);
    }

    $style = qr_style_load_config($conn);
    $config['label_color'] = $style['label_color'] ?? '#000000';

    $qrRaw = qr_generate_png_resource($qrData, 900, $style);
    if (!$qrRaw) {
        return null;
    }
    $bgRgb = qr_style_hex_to_rgb((string) ($style['bg_color'] ?? '#ffffff'));
    qr_prepare_qr_for_frame_paste($qrRaw, $bgRgb);

    $usedFrame = false;
    $final = compose_qr_image($qrRaw, $choiceName, $config, $usedFrame);
    imagedestroy($qrRaw);

    return $final ?: null;
}
