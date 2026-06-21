<?php
session_start();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
  exit;
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
  exit;
}
if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'No file uploaded.']);
  exit;
}
$file = $_FILES['logo'];
$maxSize     = 10 * 1024 * 1024; 
$allowedMime = ['image/png', 'image/jpeg', 'image/webp'];
$mimeToExt   = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if (!in_array($mime, $allowedMime, true)) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Allowed: PNG, JPG, WEBP.']);
  exit;
}
if ($file['size'] > $maxSize) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'File too large. Max is 10MB.']);
  exit;
}
$targetDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp_logo_uploads';
if (!is_dir($targetDir)) {
  if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server cannot create temp directory.']);
    exit;
  }
}
$htaccess = $targetDir . DIRECTORY_SEPARATOR . '.htaccess';
if (!file_exists($htaccess)) {
  @file_put_contents($htaccess, "Options -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7\n");
}
$origBase  = pathinfo($file['name'] ?? 'logo', PATHINFO_FILENAME);
$cleanBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origBase);
$ext       = $mimeToExt[$mime] ?? 'bin';
$filename  = $cleanBase . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
$targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
if (!is_uploaded_file($file['tmp_name'])) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Upload not recognized as a valid file.']);
  exit;
}
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded file.']);
  exit;
}
@chmod($targetPath, 0644);
$expire = time() - 86400; 
foreach (glob($targetDir . DIRECTORY_SEPARATOR . '*') as $f) {
  if (is_file($f) && filemtime($f) < $expire) {
    @unlink($f);
  }
}
$publicRel = 'tmp_logo_uploads/' . $filename;
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$absUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $base . '/' . $publicRel;
echo json_encode([
  'status' => 'success',
  'path'   => $publicRel, 
  'url'    => $absUrl   
]);
