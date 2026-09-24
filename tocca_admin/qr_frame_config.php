<?php

require_once 'db_connection.php';



function qr_frame_default_values(): array {

  return [

    'use_frame' => true,

    'frame_path' => 'img/qr_frame.jpg',

    'default_preset' => 'center_fit',

    'show_label_on_poster' => true,
    'poster_caption' => true,

    'frame_box_x' => 500,

    'frame_box_y' => 1000,

    'frame_box_w' => 660,

    'frame_box_h' => 660,

    'card_side_pad' => 24,

    'card_top_pad' => 18,

    'label_strip_h_min' => 72,

    'label_strip_h_max' => 240,

    'gap_qr_to_label' => 10,

    'qr_side_min' => 360,

    'caption_box_x' => 0,

    'caption_box_y' => 0,

    'caption_box_w' => 0,

    'caption_box_h' => 0,

    'caption_font_size' => 0,

    'caption_color' => '#00155C',

  ];

}



/**
 * Default business-name box: under the QR, same width, tall enough to read.
 *
 * @return array{x:int,y:int,w:int,h:int}
 */
function qr_default_caption_box(int $qrX, int $qrY, int $qrW, int $qrH, int $frameW = 0, int $frameH = 0): array
{
    $gap = max(16, (int) round(max(1, $qrH) * 0.045));
    $h = max(64, (int) round(max(1, $qrH) * 0.18));
    $w = max(80, $qrW);
    $x = max(0, $qrX);
    $y = $qrY + $qrH + $gap;
    if ($frameW > 0 && $x + $w > $frameW) {
        $w = max(40, $frameW - $x);
    }
    if ($frameH > 0 && $y + $h > $frameH - 4) {
        $y = max(0, $frameH - 4 - $h);
        if ($qrH > 0 && $y < $qrY + $qrH + 8) {
            $y = $qrY + $qrH + 8;
            $h = max(24, ($frameH - 4) - $y);
        }
    }
    return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
}

function qr_frame_absolute_path(string $framePath): string {

  if ($framePath === '') return '';

  if ($framePath[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $framePath)) {

    return $framePath;

  }

  return __DIR__ . '/' . ltrim($framePath, '/');

}

/** First existing frame file: configured path, then default poster template. */
function qr_frame_resolve_existing_path(?string $path): string
{
    $candidates = [];
    $raw = trim((string) $path);
    if ($raw !== '') {
        $candidates[] = $raw;
        $abs = qr_frame_absolute_path($raw);
        if ($abs !== '' && $abs !== $raw) {
            $candidates[] = $abs;
        }
    }
    $candidates[] = qr_frame_absolute_path('img/qr_frame.jpg');

    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = str_replace('\\', '/', (string) $candidate);
        if ($candidate === '' || isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return '';
}



function qr_frame_load_config($conn): array {

  $defaults = qr_frame_default_values();

  $config = $defaults;

  $config['_from_db'] = false;



  if ($stmt = $conn->prepare("SELECT config_value FROM tbl_config WHERE config_key = 'qr_frame_config' LIMIT 1")) {

    if ($stmt->execute()) {

      $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {

          $data = json_decode($row['config_value'], true);

          if (is_array($data)) {

            $config['_from_db'] = true;

            $config['use_frame'] = isset($data['use_frame']) ? (bool)$data['use_frame'] : $config['use_frame'];

            if (array_key_exists('poster_caption', $data)) {
              $config['poster_caption'] = (bool)$data['poster_caption'];
              $config['show_label_on_poster'] = $config['poster_caption'];
            } else {
              $config['poster_caption'] = true;
              $config['show_label_on_poster'] = true;
            }

            if (!empty($data['default_preset']) && is_string($data['default_preset'])) {

              $config['default_preset'] = $data['default_preset'];

            }

            $config['frame_path'] = isset($data['frame_path']) && is_string($data['frame_path']) && $data['frame_path'] !== ''

              ? $data['frame_path']

              : $config['frame_path'];



            $intKeys = [

              'frame_box_x','frame_box_y','frame_box_w','frame_box_h',

              'card_side_pad','card_top_pad','label_strip_h_min','label_strip_h_max','gap_qr_to_label','qr_side_min',

              'caption_box_x','caption_box_y','caption_box_w','caption_box_h','caption_font_size'

            ];

            foreach ($intKeys as $k) {

              if (isset($data[$k]) && is_numeric($data[$k])) {

                $config[$k] = (int)$data[$k];

              }

            }

            if (!empty($data['caption_color']) && is_string($data['caption_color'])) {
              $hex = ltrim(trim($data['caption_color']), '#');
              if (preg_match('/^[0-9a-fA-F]{3}$|^[0-9a-fA-F]{6}$/', $hex)) {
                $config['caption_color'] = '#' . (strlen($hex) === 3
                  ? ($hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2])
                  : $hex);
              }
            }

          }

        }

    }

    $stmt->close();

  }



  $config['frame_path_absolute'] = qr_frame_absolute_path($config['frame_path']);

  return $config;

}



function qr_frame_save_config($conn, array $config): bool {

  $payload = [

    'use_frame' => (bool)($config['use_frame'] ?? true),

    'show_label_on_poster' => (bool)($config['show_label_on_poster'] ?? true),
    'poster_caption' => (bool)($config['poster_caption'] ?? $config['show_label_on_poster'] ?? true),

    'default_preset' => trim((string)($config['default_preset'] ?? 'center_fit')),

    'frame_path' => trim((string)($config['frame_path'] ?? 'img/qr_frame.jpg')),

    'frame_box_x' => (int)($config['frame_box_x'] ?? 0),

    'frame_box_y' => (int)($config['frame_box_y'] ?? 0),

    'frame_box_w' => (int)($config['frame_box_w'] ?? 0),

    'frame_box_h' => (int)($config['frame_box_h'] ?? 0),

    'card_side_pad' => (int)($config['card_side_pad'] ?? 0),

    'card_top_pad' => (int)($config['card_top_pad'] ?? 0),

    'label_strip_h_min' => (int)($config['label_strip_h_min'] ?? 0),

    'label_strip_h_max' => (int)($config['label_strip_h_max'] ?? 0),

    'gap_qr_to_label' => (int)($config['gap_qr_to_label'] ?? 0),

    'qr_side_min' => (int)($config['qr_side_min'] ?? 0),

    'caption_box_x' => (int)($config['caption_box_x'] ?? 0),

    'caption_box_y' => (int)($config['caption_box_y'] ?? 0),

    'caption_box_w' => (int)($config['caption_box_w'] ?? 0),

    'caption_box_h' => (int)($config['caption_box_h'] ?? 0),

    'caption_font_size' => (int)($config['caption_font_size'] ?? 0),

    'caption_color' => trim((string)($config['caption_color'] ?? '#00155C')),

  ];



  $json = json_encode($payload);

  if ($json === false) return false;



  if (!$stmt = $conn->prepare("INSERT INTO tbl_config (config_key, config_value) VALUES ('qr_frame_config', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)")) {

    return false;

  }

  $stmt->bind_param('s', $json);

  $ok = $stmt->execute();

  $stmt->close();

  return $ok;

}

?>


