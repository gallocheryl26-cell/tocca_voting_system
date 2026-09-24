<?php
$src = dirname(__DIR__) . '/e-vote-final-enhanced/img/1787022194_tatak_ormoc_2026_landing_page_banner.png';
$webp = dirname(__DIR__) . '/e-vote-final-enhanced/img/1787022194_tatak_ormoc_2026_landing_page_banner.webp';
if (!is_file($src)) {
  fwrite(STDERR, "missing $src\n");
  exit(1);
}
$im = imagecreatefrompng($src);
$w = imagesx($im);
$h = imagesy($im);
echo "dims $w x $h\n";
$maxW = 1600;
if ($w > $maxW) {
  $nw = $maxW;
  $nh = (int) round($h * ($maxW / $w));
  $scaled = imagecreatetruecolor($nw, $nh);
  $white = imagecolorallocate($scaled, 255, 255, 255);
  imagefilledrectangle($scaled, 0, 0, $nw, $nh, $white);
  imagecopyresampled($scaled, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
  imagedestroy($im);
  $im = $scaled;
  echo "scaled $nw x $nh\n";
} else {
  $flat = imagecreatetruecolor($w, $h);
  $white = imagecolorallocate($flat, 255, 255, 255);
  imagefilledrectangle($flat, 0, 0, $w, $h, $white);
  imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
  imagedestroy($im);
  $im = $flat;
}
imagewebp($im, $webp, 78);
imagedestroy($im);
echo 'png=' . filesize($src) . ' webp=' . filesize($webp) . "\n";
