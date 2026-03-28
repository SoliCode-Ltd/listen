<?php
$f = 'C:\\Users\\walle\\Downloads\\WhatsApp Audio 2026-03-21 at 6.37.24 AM.mp4';
if (!file_exists($f)) { echo "missing\n"; exit(1); }
$fi = new finfo(FILEINFO_MIME_TYPE);
echo 'finfo: '.($fi->file($f) ?: 'n/a').PHP_EOL;
echo 'mime_content_type: '.(mime_content_type($f) ?: 'n/a').PHP_EOL;
echo 'path_ext: '.strtolower(pathinfo($f, PATHINFO_EXTENSION)).PHP_EOL;
require __DIR__ . '/vendor/autoload.php';
$m = Symfony\Component\Mime\MimeTypes::getDefault();
$mime = mime_content_type($f) ?: '';
echo 'exts_for_detected_mime: '.implode(',', $m->getExtensions($mime)).PHP_EOL;
echo 'mimes_for_mp4: '.implode(',', $m->getMimeTypes('mp4')).PHP_EOL;
