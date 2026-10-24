<?php
// Install as index.php in ~/public_html_sub/rendergallery-php/.
declare(strict_types=1);
$host=strtolower($_SERVER['HTTP_HOST']??'');
if(!in_array($host,['php.gallery.example.com','gallery.example.com','www.gallery.example.com'],true)){http_response_code(403);exit;}
$canonical=$host==='php.gallery.example.com' ? $host : 'gallery.example.com';
if(empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off' || $host!==$canonical){
    header('Location: https://'.$canonical.($_SERVER['REQUEST_URI']??'/'),true,308);exit;
}
require dirname(__DIR__,2).'/rendergallery-php/app/public/index.php';
