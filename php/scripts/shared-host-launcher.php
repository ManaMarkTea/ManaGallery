<?php
// Copy into the public root and edit the three deployment settings below.
declare(strict_types=1);
$app = '/absolute/private/path/ManaGallery/app';
$canonical = 'gallery.example.com';
$allowedHosts = ['gallery.example.com', 'www.gallery.example.com'];
$host=strtolower($_SERVER['HTTP_HOST']??'');
if(!in_array($host,$allowedHosts,true)){http_response_code(403);exit;}
if(empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off' || $host!==$canonical){
    $uri=$_SERVER['REQUEST_URI']??'/';
    if(!str_starts_with($uri,'/') || preg_match('/[\r\n]/',$uri)){http_response_code(400);exit;}
    header('Location: https://'.$canonical.$uri,true,308);exit;
}
require $app.'/public/index.php';
