<?php
$p = $argv[1] ?? '';
if ($p === '' || !is_file($p)) {
    fwrite(STDERR, "Usage: php read_docx.php <path>\n");
    exit(1);
}
$z = new ZipArchive();
if ($z->open($p) !== true) {
    fwrite(STDERR, "Cannot open docx\n");
    exit(1);
}
$xml = $z->getFromName('word/document.xml');
$z->close();
$xml = preg_replace('/<\/w:p>/', "\n", $xml);
$text = strip_tags($xml);
$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$text = preg_replace("/\n{2,}/", "\n", trim($text));
echo $text;
