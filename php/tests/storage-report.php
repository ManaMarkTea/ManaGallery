<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/storage-report.php';
$root = sys_get_temp_dir() . '/gallery-storage-test-' . bin2hex(random_bytes(8));
mkdir($root . '/storage', 0700, true);
mkdir($root . '/archive', 0700);
mkdir($root . '/backups', 0700);
function private_directory(): string
{
    global $root;

    return $root . '/storage';
}
function config(): array
{
    global $root;

    return ['media_root' => $root . '/link-parent/account/archive', 'storage_account_root' => $root];
}
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
try {
    file_put_contents($root . '/archive/image.jpg', str_repeat('a', 200));
    link($root . '/archive/image.jpg', $root . '/backups/image.jpg');
    file_put_contents($root . '/storage/php-gallery.sqlite', str_repeat('b', 100));
    file_put_contents($root . '/storage/php-gallery.sqlite-wal', str_repeat('c', 50));
    for ($i = 0; $i < 100; ++$i) {
        file_put_contents($root . '/archive/' . $i, 'x');
    }
    if (function_exists('symlink')) {
        symlink(sys_get_temp_dir(), $root . '/outside-link');
    }
    mkdir($root . '/link-parent');
    symlink($root, $root . '/link-parent/account');
    // Classifying an alias must still match canonical paths from the scan.
    check(storage_bucket($root . '/archive/image.jpg') === 'Archive artwork', 'Archive bucket resolves configured roots');
    check(storage_database_bytes() === 150, 'Database includes journal bytes');
    $report = storage_scan_step(0.0);
    check(!$report['complete'], 'Zero-budget scan saves a resumable cursor');
    $steps = 1;
    do {
        $report = storage_scan_step(0.002);
        ++$steps;
    } while (!$report['complete'] && $steps < 1000);
    check($report['complete'], 'Resumed scan finishes');
    check($steps > 1, 'Scan exercises saved cursors');
    check(array_sum(array_column($report['buckets'], 'bytes')) === 450, 'Hard links counted once; scan/cache files excluded');
    check(array_sum(array_column($report['buckets'], 'logical')) === 650, 'Logical sizes include both hard-link locations');
    check($report['skipped'] >= 1, 'Symlink outside root is skipped');
    check(storage_report()['complete'], 'Completed report is cached');
    check(!is_file(storage_scan_file()), 'Temporary inode index removed');
    echo "Resumable storage scan, hard-link accounting, database journals and symlink boundaries passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
