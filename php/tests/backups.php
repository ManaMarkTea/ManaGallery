<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/backups.php';
function verify_backup_state(bool $ok): void
{
    if (!$ok) {
        throw new RuntimeException('Backup health status test failed');
    }
}
$now = 1800000000;
$raw = ['version' => 1, 'checked' => $now, 'jobs' => []];
foreach (['backup', 'cloud', 'restore', 'maintenance'] as $kind) {
    $raw['jobs'][$kind] = ['status' => 'success', 'lastRun' => $now - 60, 'lastSuccess' => $now - 30, 'nextRun' => $now + 100, 'log' => 'fictional log'];
}
$result = backup_health($raw, $now);
verify_backup_state($result['configured'] && !$result['attention']);
$raw['jobs']['cloud']['status'] = 'failed';
verify_backup_state(backup_health($raw, $now)['attention']);
$raw['jobs']['cloud']['status'] = 'success';
$raw['jobs']['cloud']['lastSuccess'] = $now - 40 * 3600;
verify_backup_state(backup_health($raw, $now)['jobs'][1]['status'] === 'stale');
$raw['checked'] = $now - 40 * 3600;
verify_backup_state(backup_health($raw, $now)['stale']);
verify_backup_state(!backup_health(['version' => 99], $now)['configured']);
$folder = dirname(__DIR__) . '/test-results/backup-leftovers-' . bin2hex(random_bytes(6));
mkdir($folder . '/live', 0700, true);
file_put_contents($folder . '/live/image.webp', str_repeat('i', 4096));
$stage = $folder . '/managallery-20261110T141540Z-a1b610.stage';
mkdir($stage . '/storage/uploads', 0700, true);
link($folder . '/live/image.webp', $stage . '/storage/uploads/image.webp');
file_put_contents($stage . '/storage/php-gallery.sqlite', str_repeat('d', 2048));
file_put_contents($folder . '/managallery-20261110T141540Z-a1b610.zip.partial', str_repeat('p', 1024));
file_put_contents($folder . '/managallery-20261026T141537Z-8a5f97.zip', 'completed archive');
file_put_contents($folder . '/chain-state.json', '{}');
mkdir($folder . '/keep-me.stage');
mkdir($folder . '/managallery-20261111T141543Z-7192d2.zip.partial');
$leftovers = backup_leftovers($folder);
verify_backup_state(array_column($leftovers, 'name') === ['managallery-20261110T141540Z-a1b610.stage', 'managallery-20261110T141540Z-a1b610.zip.partial']);
verify_backup_state($leftovers[0]['files'] === 2 && $leftovers[0]['reclaimable'] === 2048);
verify_backup_state($leftovers[1]['reclaimable'] === 1024);
$busy = fopen($folder . '/snapshot.lock', 'c');
flock($busy, LOCK_EX);
$refused = false;
try {
    remove_backup_leftovers($folder);
} catch (DomainException) {
    $refused = true;
}
verify_backup_state($refused && is_dir($stage));
flock($busy, LOCK_UN);
verify_backup_state(remove_backup_leftovers($folder) === ['removed' => 2, 'freed' => 3072]);
verify_backup_state(!file_exists($stage) && !file_exists($folder . '/managallery-20261110T141540Z-a1b610.zip.partial'));
verify_backup_state(file_get_contents($folder . '/live/image.webp') === str_repeat('i', 4096));
verify_backup_state(is_file($folder . '/managallery-20261026T141537Z-8a5f97.zip') && is_file($folder . '/chain-state.json'));
verify_backup_state(is_dir($folder . '/keep-me.stage') && is_dir($folder . '/managallery-20261111T141543Z-7192d2.zip.partial'));
verify_backup_state(backup_leftovers($folder) === []);
symlink($folder . '/live', $folder . '/managallery-20261112T141543Z-eaf374.stage');
verify_backup_state(backup_leftovers($folder) === []);
echo "Passed backup health, failure, stale-status and leftover cleanup checks.\n";
