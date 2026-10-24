<?php

// Create a fresh, empty gallery. Never replace an existing catalogue or database.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
if (PHP_VERSION_ID < 80300) {
    throw new RuntimeException('PHP 8.3 or newer is required.');
}
foreach (['pdo_sqlite', 'gd', 'mbstring', 'sodium', 'fileinfo'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException('Enable the PHP ' . $extension . ' extension first.');
    }
}
if (!function_exists('imagewebp')) {
    throw new RuntimeException('GD needs WebP support.');
}
require dirname(__DIR__) . '/src/gallery.php';
require dirname(__DIR__) . '/src/database.php';
$storage = private_directory();
$catalogue = config()['catalogue'];
if (!is_string($catalogue) || !is_dir(dirname($catalogue))) {
    throw new RuntimeException('Create the private catalogue parent directory first.');
}
$parent = realpath(dirname($catalogue));
$public = realpath(dirname(__DIR__) . '/public');
if ($parent === $public || str_starts_with($parent, $public . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('The catalogue must remain outside public/.');
}
if (file_exists($catalogue) || is_link($catalogue)) {
    throw new RuntimeException('A catalogue already exists. Nothing changed.');
}
if (file_exists($storage . '/php-gallery.sqlite')) {
    throw new RuntimeException('An existing database needs a reviewed migration, not fresh initialization. Nothing changed.');
}
$handle = fopen($catalogue, 'x');
if (!$handle) {
    throw new RuntimeException('Cannot create private catalogue.');
}
try {
    $bytes = json_encode(['items' => [], 'categories' => [['id' => 1, 'name' => 'Community art']]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    if (fwrite($handle, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Cannot write catalogue.');
    }
} finally {
    fclose($handle);
}
database();
echo "Empty gallery initialized. No account or password was created.\nRegister your account, then run: php scripts/promote-admin.php YOUR_USERNAME\n";
