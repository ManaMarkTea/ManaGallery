<?php
// Optional private settings live in the data volume, not in the image.
declare(strict_types=1);
$file='/var/lib/managallery/config.local.php';
return array_replace([
    'public_preview_directory' => __DIR__ . '/public/previews',
    'public_preview_url' => '/previews',
], is_file($file) ? require $file : []);
