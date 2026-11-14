<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
$configuration = realpath($argv[1] ?? '');
$destination = $argv[2] ?? '';
if (!$configuration || $destination === '' || file_exists($destination) || is_link($destination)
    || str_contains(str_replace('\\', '/', $destination), '/public_html')) {
    throw new RuntimeException('Usage: verify-drive-backup.php PRIVATE_SETTINGS.json NEW_PRIVATE_DIRECTORY');
}
$settings = json_decode(file_get_contents($configuration), true, 512, JSON_THROW_ON_ERROR);
$base = realpath($settings['backupDirectory']);
if (!$base || str_contains($base, '/public_html')) {
    throw new RuntimeException('Expected a private backup directory');
}
$restoreStatusFile = $base . '/drive-restore-check.json';
$priorCheck = is_file($restoreStatusFile) ? json_decode(file_get_contents($restoreStatusFile), true, 512, JSON_THROW_ON_ERROR) : [];
$restorePassed = false;
$restoreStarted = time();
register_shutdown_function(static function () use (&$restorePassed, $restoreStatusFile, $priorCheck, $restoreStarted): void {
    if (!$restorePassed) {
        file_put_contents($restoreStatusFile, json_encode([
            'status' => 'failed',
            'lastRun' => $restoreStarted,
            'checked' => $priorCheck['checked'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }
});
$lock = fopen($base . '/drive-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Wait for the active backup before checking recovery');
}
$state = json_decode(file_get_contents($base . '/chain-state.json'), true, 512, JSON_THROW_ON_ERROR);
$receipts = json_decode(file_get_contents($base . '/drive-receipts.json'), true, 512, JSON_THROW_ON_ERROR);
$password = rtrim(file_get_contents($settings['passwordFile']), "\r\n");
require_once dirname(__DIR__) . '/src/backups.php';
$chain = backup_chain($base, $state['id'], $receipts, $password);
if (!mkdir($destination, 0700)) {
    throw new RuntimeException('Cannot create private recovery check directory');
}
require_once dirname(__DIR__) . '/src/drive-backup.php';
$client = new DriveBackupClient($settings);
$archives = [];
foreach (array_reverse($chain, true) as $id => $receipt) {
    $archive = $destination . '/' . $id . '.zip';
    echo 'Downloading encrypted recovery archive ' . $id . ".\n";
    flush();
    $client->download($receipt['id'], $archive, $receipt['bytes'], $receipt['sha256']);
    $archives[] = $archive;
}
$argv = [__DIR__ . '/restore-chain.php', $destination . '/restored', $settings['passwordFile'], ...$archives];
require __DIR__ . '/restore-chain.php';
file_put_contents($base . '/drive-restore-check.json', json_encode([
    'checked' => time(),
    'lastRun' => $restoreStarted,
    'backupId' => $state['id'],
    'archives' => count($archives),
    'status' => 'success',
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
$restorePassed = true;
echo "Downloaded Drive recovery chain passed; no live data changed.\n";
