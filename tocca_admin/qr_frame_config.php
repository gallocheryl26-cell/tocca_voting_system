<?php

require_once 'db_connection.php';



function qr_frame_default_values(): array {

  return [

    'use_frame' => true,

    'frame_path' => 'img/qr_frame.jpg',

    'default_preset' => 'center_fit',

    'show_label_on_poster' => false,

    'frame_box_x' => 500,

    'frame_box_y' => 1000,

    'frame_box_w' => 660,

    'frame_box_h' => 660,

    'card_side_pad' => 24,

    'card_top_pad' => 18,

    'label_strip_h_min' => 72,

    'label_strip_h_max' => 140,

    'gap_qr_to_label' => 10,

    'qr_side_min' => 360,

  ];

}



function qr_frame_absolute_path(string $framePath): string {

  if ($framePath === '') return '';

  if ($framePath[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $framePath)) {

    return $framePath;

  }

  return __DIR__ . '/' . ltrim($framePath, '/');

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

            if (array_key_exists('show_label_on_poster', $data)) {

              $config['show_label_on_poster'] = (bool)$data['show_label_on_poster'];

            }

            if (!empty($data['default_preset']) && is_string($data['default_preset'])) {

              $config['default_preset'] = $data['default_preset'];

            }

            $config['frame_path'] = isset($data['frame_path']) && is_string($data['frame_path']) && $data['frame_path'] !== ''

              ? $data['frame_path']

              : $config['frame_path'];



            $intKeys = [

              'frame_box_x','frame_box_y','frame_box_w','frame_box_h',

              'card_side_pad','card_top_pad','label_strip_h_min','label_strip_h_max','gap_qr_to_label','qr_side_min'

            ];

            foreach ($intKeys as $k) {

              if (isset($data[$k]) && is_numeric($data[$k])) {

                $config[$k] = (int)$data[$k];

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

    'show_label_on_poster' => (bool)($config['show_label_on_poster'] ?? false),

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


