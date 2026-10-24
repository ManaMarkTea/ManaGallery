<?php
// Optional private settings live in the data volume, not in the image.
declare(strict_types=1);
$file='/var/lib/managallery/config.local.php';
return is_file($file) ? require $file : [];
