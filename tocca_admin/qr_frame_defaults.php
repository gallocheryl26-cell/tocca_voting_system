<?php
require_once 'qr_utils.php';

header('Content-Type: application/json');

try {
  $config = load_default_generation_config();
  echo json_encode([
    'status' => 'success',
    'config' => $config,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'status'  => 'error',
    'message' => $e->getMessage(),
  ]);
}