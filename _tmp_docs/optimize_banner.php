<?php
$src = __DIR__ . '/../tocca_admin/img/nom_banner_1787022141_tatak_ormoc_2026_landing_page_banner.png';
$webp = __DIR__ . '/../tocca_admin/img/nom_banner_1787022141_tatak_ormoc_2026_landing_page_banner.webp';
$jpg = __DIR__ . '/../tocca_admin/img/nom_banner_1787022141_tatak_ormoc_2026_landing_page_banner.jpg';

if (!is_file($src)) {
  fwrite(STDERR, "Missing PNG: $src\n");
  exit(1);
}

$im = @imagecreatefrompng($src);
if (!$im) {
  fwrite(STDERR, "Failed to open PNG\n");
  exit(1);
}

$w = imagesx($im);
$h = imagesy($im);
echo "dims: {$w} x {$h}\n";

$maxW = 1600;
if ($w > $maxW) {
  $nw = $maxW;
  $nh = (int) round($h * ($maxW / $w));
  $scaled = imagecreatetruecolor($nw, $nh);
  imagealphablending($scaled, false);
  imagesavealpha($scaled, true);
  $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
  imagefilledrectangle($scaled, 0, 0, $nw, $nh, $transparent);
  imagealphablending($scaled, true);
  imagecopyresampled($scaled, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
  imagedestroy($im);
  $im = $scaled;
  $w = $nw;
  $h = $nh;
  echo "scaled to: {$w} x {$h}\n";
}

// Flatten alpha onto white for JPEG (and safer WebP for photos)
$flat = imagecreatetruecolor($w, $h);
$white = imagecolorallocate($flat, 255, 255, 255);
imagefilledrectangle($flat, 0, 0, $w, $h, $white);
imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
imagedestroy($im);

imagewebp($flat, $webp, 78);
imagejpeg($flat, $jpg, 82);
imagedestroy($flat);

echo 'png=' . filesize($src) . "\n";
echo 'webp=' . filesize($webp) . "\n";
echo 'jpg=' . filesize($jpg) . "\n";
echo "ok\n";
