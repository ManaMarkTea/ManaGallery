<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
$bytes = stream_get_contents(STDIN, 65537);
if (strlen($bytes) > 65536) {
    throw new RuntimeException('Status too large');
}
$status = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
if (($status['version'] ?? null) !== 1 || !isset($status['checked'],$status['jobs']) || !is_int($status['checked']) || !is_array($status['jobs'])) {
    throw new RuntimeException('Invalid status');
}
foreach ($status['jobs'] as $name => $job) {
    if (!in_array($name, ['backup', 'restore', 'cloud', 'maintenance'], true) || !is_array($job) || !in_array($job['status'] ?? '', ['success', 'failed', 'pending', 'running'], true)) {
        throw new RuntimeException('Invalid job status');
    }
    foreach (['lastSuccess', 'lastRun', 'nextRun'] as $field) {
        if (isset($job[$field]) && !is_int($job[$field])) {
            throw new RuntimeException('Invalid timestamp');
        }
    }
    if (strlen($job['log'] ?? '') > 18000) {
        throw new RuntimeException('Log too large');
    }
}
$private = realpath(getenv('MANAGALLERY_PRIVATE_ROOT') ?: '');
if (!$private) {
    throw new RuntimeException('Set MANAGALLERY_PRIVATE_ROOT.');
}
$file = $private . '/app/storage/backup-status.json';
$lock = fopen($file . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
    throw new RuntimeException('Cannot lock status');
}
if (is_file($file) && (json_decode(file_get_contents($file), true)['checked'] ?? 0) > $status['checked']) {
    exit("Newer status already published.\n");
}
$temp = $file . '.next-' . bin2hex(random_bytes(4));
if (file_put_contents($temp, json_encode($status, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Cannot publish status');
}chmod($temp, 0600);
if (!rename($temp, $file)) {
    throw new RuntimeException('Cannot replace status');
}
echo "Backup status published.\n";
