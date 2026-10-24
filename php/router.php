<?php
// Local development only. Host public/ as the document root in production.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (preg_match('~^/assets/[a-zA-Z0-9._-]+$~D',$path) && is_file(__DIR__.'/public'.$path)) return false;
require __DIR__.'/public/index.php';
