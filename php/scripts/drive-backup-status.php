<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
$settings = json_decode(file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$base = realpath($settings['backupDirectory']);
$app = realpath($settings['appDirectory']);
if (!$base || !$app || str_contains($base, '/public_html') || str_contains($app, '/public_html')) {
    throw new RuntimeException('Expected private application and backup directories');
}
function backup_json(string $file): array
{
    return is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
}
$latest = backup_json($base . '/latest.json');
$receipts = backup_json($base . '/drive-receipts.json');
$restore = backup_json($base . '/drive-restore-check.json');
$runFile = $base . '/last-worker-run.json';
$run = backup_json($runFile);
if (isset($argv[2])) {
    $run = [
        'checked' => time(),
        'started' => isset($argv[3]) ? (int)$argv[3] : time(),
        'status' => $argv[2] === '0' ? 'success' : 'failed',
    ];
    file_put_contents($runFile, json_encode($run, JSON_THROW_ON_ERROR));
}
$snapshotTime = isset($latest['created']) ? strtotime($latest['created']) : null;
$receipt = $receipts[$latest['archive'] ?? ''] ?? [];
$cloudTime = $receipt['checked'] ?? null;
$lastCloudTime = $receipts ? max(array_column($receipts, 'checked')) : null;
$localFailed = ($run['status'] ?? '') === 'failed' && (!$snapshotTime || $snapshotTime < ($run['started'] ?? 0));
$log = is_file($base . '/latest-run.log') ? file_get_contents($base . '/latest-run.log') : '';
$log = mb_substr($log, -17000);
$jobs = [
    'backup' => [
        'status' => $localFailed ? 'failed' : ($snapshotTime ? 'success' : 'pending'),
        'lastSuccess' => $snapshotTime,
        'lastRun' => $run['checked'] ?? $snapshotTime,
        'log' => $localFailed ? $log : ($snapshotTime ? 'Consistent database and encrypted archive checked locally.' : 'No completed native PHP backup.'),
    ],
    'cloud' => [
        'status' => ($run['status'] ?? '') === 'failed' ? 'failed' : ($cloudTime ? 'success' : 'pending'),
        'lastSuccess' => $lastCloudTime,
        'lastRun' => $run['checked'] ?? $cloudTime,
        'log' => $log ?: 'Drive copy size and checksum checked. Recovery is tested separately.',
    ],
    'restore' => [
        'status' => in_array($restore['status'] ?? '', ['success', 'failed'], true) ? $restore['status'] : 'pending',
        'lastSuccess' => $restore['checked'] ?? null,
        'lastRun' => $restore['lastRun'] ?? $restore['checked'] ?? null,
        'log' => ($restore['status'] ?? '') === 'failed'
            ? 'The latest downloaded restore check failed. Review the private backup run log.'
            : (isset($restore['checked']) ? 'Downloaded the encrypted Drive chain, restored every file and checked database integrity.' : 'No downloaded restore check completed.'),
    ],
    'maintenance' => [
        'status' => $snapshotTime ? 'success' : 'pending',
        'lastSuccess' => $snapshotTime,
        'lastRun' => $snapshotTime,
        'log' => 'Every new archive is decrypted and checksummed before publication. Old chains are retained; deletion is manual.',
    ],
];
$status = ['version' => 1, 'provider' => 'php-drive', 'checked' => time(), 'jobs' => $jobs];
$file = $app . '/storage/backup-status.json';
$lock = fopen($file . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
    throw new RuntimeException('Cannot lock backup status');
}
$temporary = $file . '.next-' . bin2hex(random_bytes(4));
if (file_put_contents($temporary, json_encode($status, JSON_THROW_ON_ERROR)) === false || !rename($temporary, $file)) {
    throw new RuntimeException('Cannot publish native backup status');
}
echo "Native PHP backup status published.\n";
