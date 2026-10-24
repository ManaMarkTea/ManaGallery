<?php

// Rootless code-only update. All runtime storage and config stay untouched.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
$release = realpath($argv[1] ?? '');
$app = realpath($argv[2] ?? '');
$public = realpath($argv[3] ?? '');
if (!$release || !$app || !$public || !is_dir($release) || !is_file($app . '/public/index.php') || !is_dir($public)) {
    throw new RuntimeException('Usage: deploy-shared-host.php PRIVATE_RELEASE PRIVATE_APP PUBLIC_ROOT');
}
foreach ([$app, $release] as $private) {
    if ($private === $public || str_starts_with($private, $public . '/')) {
        throw new RuntimeException('Application and release must remain outside the public root.');
    }
}
if ($release === $app || str_starts_with($release, $app . '/') || str_starts_with($app, $release . '/')) {
    throw new RuntimeException('Release and application must be separate directories.');
}
$manifest = json_decode(file_get_contents($release . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$lock = fopen($app . '/storage/deploy.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another update is running.');
}
$prepared = [];
foreach ($manifest['files'] as $entry) {
    $path = $entry['path'];
    if (!preg_match('~^(?:src/[a-z-]+\.(?:php|json)|templates/[a-z-]+\.php|public/index\.php|public/assets/[a-zA-Z0-9._-]+)$~D', $path) || str_contains($path, '..')) {
        throw new RuntimeException('Disallowed update path.');
    }
    $staged = $release . '/' . $path;
    $target = $app . '/' . $path;
    if (!is_file($staged) || is_link($staged) || hash_file('sha256', $staged) !== $entry['after']) {
        throw new RuntimeException('Staged checksum mismatch: ' . $path);
    }
    $contents = file_get_contents($staged);
    if (str_ends_with($path, '.php')) {
        token_get_all($contents, TOKEN_PARSE);
    }
    $targets = [$target];
    if (str_starts_with($path, 'public/assets/')) {
        $targets[] = $public . '/assets/' . basename($path);
    }
    foreach ($targets as $destination) {
        if (is_link($destination) || (file_exists($destination) && !is_file($destination))) {
            throw new RuntimeException('Unsafe target: ' . $path);
        }
        $before = is_file($destination) ? file_get_contents($destination) : null;
        if ($before !== null && hash('sha256', $before) === $entry['after']) {
            continue;
        }
        if ($before === null ? $entry['beforeNormalized'] !== null : hash('sha256', str_replace("\r\n", "\n", $before)) !== $entry['beforeNormalized']) {
            throw new RuntimeException('Target changed since reviewed source: ' . $path);
        }
        $prepared[] = ['target' => $destination, 'before' => $before, 'after' => $contents, 'mode' => $before === null ? (str_starts_with($destination, $public . '/') ? 0644 : 0600) : fileperms($destination) & 0777];
    }
}
if (!$prepared) {
    echo "Code already current; no changes.\n";
    exit;
}
$siteLock = fopen($app . '/storage/site.lock', 'c');
if (!$siteLock || !flock($siteLock, LOCK_EX)) {
    throw new RuntimeException('Cannot lock gallery during code switch.');
}
// Dependencies first, shared assets second, layout last. All originals are backed
// up and all target checks complete before the first replacement.
$backup = $release . '/previous-code';
if (!is_dir($backup)) {
    mkdir($backup, 0700);
}
foreach ($prepared as $i => $item) {
    $name = $backup . '/' . $i;
    if ($item['before'] !== null && file_put_contents($name, $item['before']) !== strlen($item['before'])) {
        throw new RuntimeException('Backup failed.');
    }
    if (file_put_contents($name . '.next', $item['after']) !== strlen($item['after'])) {
        throw new RuntimeException('Staging failed.');
    }
    chmod($name . '.next', $item['mode']);
}
file_put_contents($backup . '/targets.json', json_encode(array_column($prepared, 'target'), JSON_PRETTY_PRINT));
$changed = [];
try {
    foreach ($prepared as $i => $item) {
        if ($item['before'] === null ? file_exists($item['target']) || is_link($item['target']) : hash_file('sha256', $item['target']) !== hash('sha256', $item['before'])) {
            throw new RuntimeException('Target changed during update.');
        }
        if (!rename($backup . '/' . $i . '.next', $item['target'])) {
            throw new RuntimeException('Atomic code replacement failed.');
        }
        $changed[] = $i;
    }
} catch (Throwable $error) {
    foreach (array_reverse($changed) as $i) {
        $item = $prepared[$i];
        if ($item['before'] === null) {
            if (!unlink($item['target'])) {
                throw new RuntimeException('Cannot roll back new file');
            }continue;
        }$temp = $backup . '/' . $i . '.rollback';
        file_put_contents($temp, $item['before']);
        chmod($temp, $item['mode']);
        if (!rename($temp, $item['target'])) {
            throw new RuntimeException('Rollback needs attention; originals remain in ' . $backup);
        }
    }
    throw $error;
}
echo 'Updated ' . count($prepared) . ' code/asset files; original code retained at ' . $backup . "\nRuntime data and configuration untouched.\n";
