<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/updates.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/gallery-update-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    foreach (['../config.local.php', '/tmp/escape', 'src/../../escape', 'storage/php-gallery.sqlite', 'public/assets/../index.php'] as $name) {
        $archive = $root . '/bad.zip';
        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($name, 'untrusted');
        $zip->close();
        try {
            update_extract($archive, $root . '/staged');
            throw new LogicException('Accepted unsafe entry: ' . $name);
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'Unsafe'), $error->getMessage());
        }
    }
    $archive = $root . '/valid.zip';
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    foreach (['manifest.json' => '{}', 'src/code-deploy.php' => '<?php', 'public/index.php' => '<?php'] as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    update_extract($archive, $root . '/valid');
    check(is_file($root . '/valid/src/code-deploy.php'), 'Valid package was not staged');
    foreach (['http://github.com/a', 'https://example.com/a', 'https://github.com:443/a', 'https://user@github.com/a'] as $url) {
        try {
            update_fetch($url, 100);
            throw new LogicException('Accepted unsafe URL');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'Untrusted'), $error->getMessage());
        }
    }
    echo "Update archive traversal, runtime-file and download-host checks passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
