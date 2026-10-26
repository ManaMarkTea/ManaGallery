<?php
// Local development only. Host public/ as the document root in production.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (preg_match('~^/assets/[a-zA-Z0-9._-]+$~D',$path) && is_file(__DIR__.'/public'.$path)) return false;
if (preg_match('~^/previews/art-[1-9][0-9]*-preview-[a-f0-9]{64}\.(jpg|png|webp|gif)$~D', $path) && is_file(__DIR__.'/public'.$path) && !is_link(__DIR__.'/public'.$path)) {
    header('Cache-Control: private, max-age=300, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Gallery-Media: static-preview');
    $file = __DIR__.'/public'.$path;
    $etag = '"'.hash_file('sha256', $file).'"';
    header('ETag: '.$etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); return true; }
    header('Content-Type: '.(new finfo(FILEINFO_MIME_TYPE))->file($file));
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') readfile($file);
    return true;
}
require __DIR__.'/public/index.php';
