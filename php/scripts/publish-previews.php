<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
foreach (['gallery', 'database', 'auth', 'uploads', 'admin', 'public-previews'] as $module) {
    require dirname(__DIR__) . '/src/' . $module . '.php';
}
$configured = config()['public_preview_directory'] ?? '';
if ($configured === '' || is_link($configured)) {
    throw new RuntimeException('Set public_preview_directory in private config.local.php first');
}
if (!is_dir($configured) && !mkdir($configured, 0755, true)) {
    throw new RuntimeException('Cannot create dedicated public preview directory');
}
$directory = realpath($configured);
$private = private_directory();
if ($directory === $private || str_starts_with($directory, $private . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Public previews must be separate from private storage');
}
$marker = $directory . '/.managallery-previews';
if (!is_file($marker)) {
    if (count(scandir($directory)) !== 2) {
        throw new RuntimeException('Choose an empty dedicated preview directory');
    }
    file_put_contents($marker, "ManaGallery public previews v1\n");
}
$rules = <<<'APACHE'
Options -Indexes -ExecCGI
SetHandler default-handler
RemoveHandler .jpg .jpeg .png .webp .gif
<FilesMatch "^\.">
Require all denied
</FilesMatch>
<IfModule mod_headers.c>
Header set Cache-Control "private, max-age=300, must-revalidate"
Header set X-Content-Type-Options "nosniff"
Header set X-Gallery-Media "static-preview"
</IfModule>
APACHE;
public_preview_directory();
if (is_link($directory . '/.htaccess') || file_put_contents($directory . '/.htaccess', $rules . "\n") === false) {
    throw new RuntimeException('Cannot protect static preview directory');
}
chmod($directory, 0755);
chmod($directory . '/.htaccess', 0644);
public_preview_schema();
if (in_array('--init-only', $argv, true)) {
    echo "Static preview directory initialized. No images published.\n";
    exit;
}
$siteLock = fopen(private_directory() . '/site.lock', 'c');
if (!$siteLock || !flock($siteLock, LOCK_SH)) {
    throw new RuntimeException('Cannot lock site');
}
$importLock = fopen(private_directory() . '/catalogue-import.lock', 'c');
if (!$importLock || !flock($importLock, LOCK_SH)) {
    throw new RuntimeException('Cannot lock catalogue import');
}
$archive = catalogue();
$all = all_artworks(apply_categories($archive));
$ids = array_map(static fn (array $item): int => (int)$item['id'], $all['items']);
public_preview_schema();
foreach (query('SELECT artwork_id FROM public_previews') as $row) {
    $ids[] = (int)$row['artwork_id'];
}
// Include files left by an interrupted write or an older database restore.
foreach (glob($directory . '/art-*-preview-*') as $file) {
    if (public_preview_filename(basename($file))) {
        preg_match('/^art-([1-9][0-9]*)-/', basename($file), $match);
        $ids[] = (int)$match[1];
    }
}
$published = 0;
$checked = 0;
foreach (array_unique($ids) as $id) {
    $published += (int)public_preview_publish($id, $archive);
    if (++$checked % 250 === 0) {
        echo 'Checked ' . $checked . " artworks.\n";
        flush();
    }
}
echo 'Published ' . $published . ' static previews; checked ' . $checked . " artworks. Originals remain private.\n";
