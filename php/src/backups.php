<?php

declare(strict_types=1);
function backup_health(?array $raw = null, ?int $now = null): array
{
    $now ??= time();
    if ($raw === null) {
        $file = private_directory() . '/backup-status.json';
        if (is_file($file)) {
            $raw = json_decode(file_get_contents($file), true);
        }
    }
    if (!is_array($raw) || ($raw['version'] ?? null) !== 1 || !is_int($raw['checked'] ?? null) || !is_array($raw['jobs'] ?? null)) {
        return ['configured' => false, 'jobs' => [], 'attention' => true];
    }
    $jobs = [];
    $attention = false;
    $stale = $raw['checked'] < $now - 36 * 3600 || $raw['checked'] > $now + 300;
    $jobDefinitions = [
        'backup' => [
            'label' => 'Encrypted VPS backup',
            'cadence' => 'Daily',
            'maxAgeSeconds' => 36 * 3600,
        ],
        'cloud' => [
            'label' => 'Google Drive copy',
            'cadence' => 'Daily',
            'maxAgeSeconds' => 36 * 3600,
        ],
        'restore' => [
            'label' => 'Recovery checks',
            'cadence' => 'Monthly',
            'maxAgeSeconds' => 40 * 86400,
        ],
        'maintenance' => [
            'label' => 'Retention & integrity',
            'cadence' => 'Weekly',
            'maxAgeSeconds' => 9 * 86400,
        ],
    ];
    if (($raw['provider'] ?? '') === 'php-drive') {
        $jobDefinitions['backup']['label'] = 'Encrypted PHP backup';
        $jobDefinitions['maintenance'] = [
            'label' => 'Archive verification',
            'cadence' => 'Daily',
            'maxAgeSeconds' => 36 * 3600,
        ];
    }

    foreach ($jobDefinitions as $kind => $definition) {
        $maxAgeSeconds = $definition['maxAgeSeconds'];
        $entry = is_array($raw['jobs'][$kind] ?? null) ? $raw['jobs'][$kind] : [];
        $status = in_array($entry['status'] ?? '', ['success', 'failed', 'pending', 'running'], true) ? $entry['status'] : 'pending';
        $lastSuccess = is_int($entry['lastSuccess'] ?? null) ? $entry['lastSuccess'] : null;
        $lastRun = is_int($entry['lastRun'] ?? null) ? $entry['lastRun'] : null;
        $successExpired = $status === 'success'
            && (!$lastSuccess || $lastSuccess < $now - $maxAgeSeconds);
        $runExpired = $status === 'running'
            && (!$lastRun || $lastRun < $now - 3 * 3600);

        if ($stale || $successExpired || $runExpired) {
            $status = 'stale';
        }
        if (!in_array($status, ['success', 'running'], true)) {
            $attention = true;
        }
        $jobs[] = [
            'label' => $definition['label'],
            'cadence' => $definition['cadence'],
            'status' => $status,
            'lastSuccess' => $lastSuccess,
            'lastRun' => $lastRun,
            'nextRun' => is_int($entry['nextRun'] ?? null) ? $entry['nextRun'] : null,
            'log' => is_string($entry['log'] ?? null)
                ? mb_substr($entry['log'], 0, 18000)
                : 'No run log yet.',
        ];
    }

    return [
        'configured' => true,
        'checked' => $raw['checked'],
        'jobs' => $jobs,
        'attention' => $attention,
        'stale' => $stale,
    ];
}

/**
 * The native backup directory, from config 'backup_directory' or the
 * standard shared-hosting layout: a native-backups folder beside the app.
 */
function backup_directory(): ?string
{
    $configured = (string)(config()['backup_directory'] ?? '');
    $candidate = $configured !== '' ? $configured : dirname(__DIR__, 2) . '/native-backups';
    $path = realpath($candidate);
    if (!$path || is_link($candidate) || !is_dir($path)) {
        return null;
    }
    if ($configured === '' && !is_file($path . '/chain-state.json') && !is_file($path . '/latest.json')) {
        return null;
    }

    return $path;
}

const BACKUP_LEFTOVER_PATTERN = '/^managallery-\d{8}T\d{6}Z-[0-9a-f]{6}(?:\.stage|\.zip\.partial)$/D';

/**
 * Staging folders and unfinished archives left by failed snapshot runs.
 * Staged images are hard links to the live files, so only files without
 * another link (the database copy, partial archives) free space on removal.
 */
function backup_leftovers(string $directory): array
{
    $items = [];
    foreach (scandir($directory) ?: [] as $name) {
        $path = $directory . '/' . $name;
        if (!preg_match(BACKUP_LEFTOVER_PATTERN, $name) || is_link($path)) {
            continue;
        }
        $isStage = str_ends_with($name, '.stage');
        if ($isStage !== is_dir($path)) {
            continue;
        }
        $files = 0;
        $reclaimable = 0;
        $entries = $isStage
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
            : [new SplFileInfo($path)];
        foreach ($entries as $file) {
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }
            $files++;
            $stat = stat($file->getPathname());
            if ($stat && $stat['nlink'] <= 1) {
                $reclaimable += $stat['size'];
            }
        }
        $items[] = ['name' => $name, 'modified' => filemtime($path) ?: null, 'files' => $files, 'reclaimable' => $reclaimable];
    }
    usort($items, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $items;
}

/** Remove failed-run leftovers unless a snapshot is currently running. */
function remove_backup_leftovers(string $directory): array
{
    $lock = fopen($directory . '/snapshot.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new DomainException('A backup is running right now. Try again after it finishes.');
    }
    try {
        $removed = 0;
        $freed = 0;
        foreach (backup_leftovers($directory) as $item) {
            $path = $directory . '/' . $item['name'];
            if (is_dir($path)) {
                $children = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($children as $file) {
                    if ($file->isLink()) {
                        throw new DomainException('A staging folder contains a symbolic link; remove ' . $item['name'] . ' manually.');
                    }
                }
                foreach ($children as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($path);
            } else {
                unlink($path);
            }
            $removed++;
            $freed += $item['reclaimable'];
        }

        return ['removed' => $removed, 'freed' => $freed];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

const BACKUP_ARCHIVE_PATTERN = '/^(managallery-\d{8}T\d{6}Z-[0-9a-f]{6})\.zip$/D';

function backup_json_file(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($file), true);

    return is_array($data) ? $data : [];
}

/**
 * Local encrypted archives and whether each can be removed from this server.
 *
 * An archive is removable once Google Drive confirmed the same bytes (its
 * receipt) and its chain position is recorded in chain-index.json, so recovery
 * checks no longer need the local copy. A full baseline is additionally kept
 * until a downloaded recovery check has passed for a chain built on it.
 */
function backup_archives(string $directory): array
{
    $receipts = backup_json_file($directory . '/drive-receipts.json');
    $index = backup_json_file($directory . '/chain-index.json');
    $restore = backup_json_file($directory . '/drive-restore-check.json');
    $verifiedBaseline = null;
    if (($restore['status'] ?? '') === 'success' && is_string($restore['backupId'] ?? null)) {
        $id = $restore['backupId'];
        for ($steps = 0; is_string($id) && isset($index[$id]) && $steps < 1000; $steps++) {
            if ($index[$id]['parent'] === null) {
                $verifiedBaseline = $id;

                break;
            }
            $id = $index[$id]['parent'];
        }
    }
    $archives = [];
    foreach (scandir($directory) ?: [] as $name) {
        $path = $directory . '/' . $name;
        if (!preg_match(BACKUP_ARCHIVE_PATTERN, $name, $match) || is_link($path) || !is_file($path)) {
            continue;
        }
        $id = $match[1];
        $bytes = filesize($path);
        $receipt = $receipts[$name] ?? null;
        $onDrive = is_array($receipt) && ($receipt['bytes'] ?? null) === $bytes
            && is_int($receipt['checked'] ?? null) && filemtime($path) <= $receipt['checked'];
        $indexed = isset($index[$id]) && array_key_exists('parent', $index[$id]);
        $full = $indexed && $index[$id]['parent'] === null;
        if (!$onDrive) {
            $status = 'Not confirmed on Google Drive yet';
        } elseif (!$indexed) {
            $status = 'On Google Drive · kept until the next backup run records its chain';
        } elseif ($full && $verifiedBaseline !== $id) {
            $status = 'On Google Drive · full backup kept until a recovery check passes';
        } else {
            $status = 'On Google Drive · safe to remove here';
        }
        $archives[] = [
            'name' => $name,
            'bytes' => $bytes,
            'modified' => filemtime($path) ?: null,
            'full' => $full,
            'removable' => $status === 'On Google Drive · safe to remove here',
            'status' => $status,
        ];
    }
    usort($archives, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $archives;
}

/**
 * Delete local archives that Google Drive already holds. Pass $locked when the
 * caller already holds drive-worker.lock (the scheduled backup does).
 */
function remove_uploaded_backup_archives(string $directory, bool $locked = false): array
{
    $lock = null;
    if (!$locked) {
        $lock = fopen($directory . '/drive-worker.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new DomainException('A backup or recovery check is running right now. Try again after it finishes.');
        }
    }
    try {
        $removed = 0;
        $freed = 0;
        foreach (backup_archives($directory) as $archive) {
            if ($archive['removable'] && unlink($directory . '/' . $archive['name'])) {
                $removed++;
                $freed += $archive['bytes'];
            }
        }

        return ['removed' => $removed, 'freed' => $freed];
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** Record an archive's place in its backup chain (id => parent). */
function record_backup_chain(string $directory, string $id, ?string $parent): void
{
    $file = $directory . '/chain-index.json';
    $index = backup_json_file($file);
    $index[$id] = ['parent' => $parent];
    ksort($index);
    $next = $file . '.next';
    if (file_put_contents($next, json_encode($index, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || !rename($next, $file)) {
        throw new RuntimeException('Cannot save backup chain index');
    }
}

/**
 * Receipts for the chain ending at $id, newest first. Uses chain-index.json so
 * archives already removed from this server can still be located on Drive;
 * archives without an index entry are read locally with the recovery password.
 */
function backup_chain(string $directory, string $id, array $receipts, string $password): array
{
    $index = backup_json_file($directory . '/chain-index.json');
    $chain = [];
    while ($id !== null) {
        if (!is_string($id) || !preg_match('/^managallery-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{6}$/D', $id)
            || isset($chain[$id]) || count($chain) > 1000) {
            throw new RuntimeException('Invalid local chain metadata');
        }
        $name = $id . '.zip';
        if (!isset($receipts[$name])) {
            throw new RuntimeException('Latest backup chain is not fully copied to Drive');
        }
        if (isset($index[$id]) && array_key_exists('parent', $index[$id])) {
            $parent = $index[$id]['parent'];
        } else {
            $zip = new ZipArchive();
            if ($zip->open($directory . '/' . $name) !== true) {
                throw new RuntimeException('Local chain metadata unavailable; use manual recovery instructions');
            }
            $zip->setPassword($password);
            $manifest = json_decode((string)$zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $zip->close();
            if (($manifest['id'] ?? '') !== $id || !array_key_exists('parent', $manifest)) {
                throw new RuntimeException('Local manifest does not match its chain ID');
            }
            $parent = $manifest['parent'];
        }
        $chain[$id] = $receipts[$name];
        $id = $parent;
    }

    return $chain;
}
