<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
$configuration = realpath($argv[1] ?? '');
if (!$configuration || str_contains($configuration, '/public_html')) {
    throw new RuntimeException('Usage: backup-drive.php PRIVATE_SETTINGS.json [--upload-only]');
}
$settings = json_decode(file_get_contents($configuration), true, 512, JSON_THROW_ON_ERROR);
$base = realpath($settings['backupDirectory']);
if (!$base || is_link($settings['backupDirectory']) || str_contains($base, '/public_html')) {
    throw new RuntimeException('Use an existing private backup directory');
}
$workerLock = fopen($base . '/drive-worker.lock', 'c');
if (!$workerLock || !flock($workerLock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('A Drive backup is already running');
}
if (!in_array('--upload-only', $argv, true)) {
    $argv = [__DIR__ . '/snapshot.php', $settings['appDirectory'], $base, $settings['passwordFile'], '--incremental'];
    require __DIR__ . '/snapshot.php';
}
require_once dirname(__DIR__) . '/src/drive-backup.php';
$client = new DriveBackupClient($settings);
$receiptFile = $base . '/drive-receipts.json';
$receipts = is_file($receiptFile) ? json_decode(file_get_contents($receiptFile), true, 512, JSON_THROW_ON_ERROR) : [];
$archives = glob($base . '/managallery-*.zip');
sort($archives);
foreach ($archives as $archive) {
    if (is_link($archive)) {
        throw new RuntimeException('Unsafe backup archive');
    }
    $name = basename($archive);
    $sha = hash_file('sha256', $archive);
    if (($receipts[$name]['sha256'] ?? '') === $sha) {
        continue;
    }
    echo 'Copying encrypted archive ' . $name . " to Google Drive.\n";
    flush();
    $result = $client->upload($archive, $base . '/' . $name . '.upload.json');
    $receipts[$name] = ['id' => $result['id'], 'sha256' => $sha, 'bytes' => filesize($archive), 'checked' => time()];
    $next = $receiptFile . '.next';
    if (file_put_contents($next, json_encode($receipts, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false || !rename($next, $receiptFile)) {
        throw new RuntimeException('Cannot save confirmed Drive receipt');
    }
}
echo "Drive upload checks passed. Download and restore the chain separately to test recovery.\n";
require_once dirname(__DIR__) . '/src/backups.php';
// Archives made before chain-index.json existed: read their encrypted
// manifests once so recovery checks no longer need the local copies.
$index = backup_json_file($base . '/chain-index.json');
$password = null;
foreach ($archives as $archive) {
    $id = basename($archive, '.zip');
    if (isset($index[$id]) || !is_file($archive)) {
        continue;
    }
    $password ??= rtrim(file_get_contents($settings['passwordFile']), "\r\n");
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Cannot open archive to index its chain: ' . basename($archive));
    }
    $zip->setPassword($password);
    $manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
    $zip->close();
    if (is_array($manifest) && ($manifest['id'] ?? null) === $id && array_key_exists('parent', $manifest)) {
        record_backup_chain($base, $id, $manifest['parent']);
    }
}
if (($settings['keepLocalArchives'] ?? false) !== true) {
    $pruned = remove_uploaded_backup_archives($base, true);
    if ($pruned['removed'] > 0) {
        echo 'Removed ' . $pruned['removed'] . ' local archive(s) already on Google Drive, freeing ' . number_format($pruned['freed'] / 1048576, 1) . " MiB.\n";
    }
}
