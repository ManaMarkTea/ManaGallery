<?php

declare(strict_types=1);

// Restore to a NEW private directory. Never run this against the live gallery.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
if (count($argv) < 4) {
    throw new RuntimeException('Usage: restore-chain.php NEW_PRIVATE_DIRECTORY PASSWORD_FILE FULL.zip [DELTA.zip ...]');
}
$target = $argv[1];
$password = rtrim(file_get_contents($argv[2]), "\r\n");
if (strlen($password) < 32 || file_exists($target) || is_link($target)
    || str_contains(str_replace('\\', '/', $target), '/public_html')) {
    throw new RuntimeException('Use a strong recovery password and a new private destination');
}
$parentDirectory = realpath(dirname($target));
if (!$parentDirectory) {
    throw new RuntimeException('Destination parent must exist');
}
$target = $parentDirectory . '/' . basename($target);

function recovery_path(string $path): void
{
    $parts = explode('/', $path);
    if ($path === '' || preg_match('/[\\\\:\x00-\x1f]/', $path)
        || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)
        || !in_array($parts[0], ['src', 'templates', 'public', 'vendor', 'storage', 'config.example.php', 'config.local.php', 'composer.json', 'composer.lock'], true)) {
        throw new RuntimeException('Unsafe recovery path');
    }
}

$chain = [];
$previous = null;
foreach (array_slice($argv, 3) as $archive) {
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Cannot open recovery archive');
    }
    $zip->setPassword($password);
    $stat = $zip->statName('manifest.json');
    if (!$stat || $stat['size'] > 16 * 1024 * 1024) {
        throw new RuntimeException('Missing or oversized recovery manifest');
    }
    $json = $zip->getFromName('manifest.json');
    if ($json === false) {
        throw new RuntimeException('Cannot decrypt recovery manifest');
    }
    $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['format'] ?? '') !== 'ManaGallery recovery v2'
        || !is_string($manifest['id'] ?? null)
        || !array_key_exists('parent', $manifest)
        || $manifest['parent'] !== ($previous['id'] ?? null)
        || !is_array($manifest['files'] ?? null)
        || !is_array($manifest['changed'] ?? null)
        || !is_array($manifest['deleted'] ?? null)) {
        throw new RuntimeException('Incomplete or out-of-order recovery chain');
    }
    foreach ($manifest['files'] as $path => $entry) {
        recovery_path($path);
        if (!is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0
            || !preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '')) {
            throw new RuntimeException('Invalid recovery file metadata');
        }
    }
    $expectedChanged = [];
    foreach ($manifest['files'] as $path => $entry) {
        if (($previous['files'][$path] ?? null) !== $entry) {
            $expectedChanged[] = $path;
        }
    }
    $expectedDeleted = array_values(array_diff(array_keys($previous['files'] ?? []), array_keys($manifest['files'])));
    if ($expectedChanged !== $manifest['changed'] || $expectedDeleted !== $manifest['deleted']) {
        throw new RuntimeException('Recovery changes do not match the parent inventory');
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $expectedNames = [...$expectedChanged, 'manifest.json'];
    sort($names);
    sort($expectedNames);
    if ($names !== $expectedNames) {
        throw new RuntimeException('Unexpected, duplicate or missing recovery archive entries');
    }
    $chain[] = [$zip, $manifest];
    $previous = $manifest;
}
if (!mkdir($target, 0700)) {
    throw new RuntimeException('Cannot create recovery destination');
}
foreach ($chain as [$zip, $manifest]) {
    foreach ($manifest['deleted'] as $path) {
        if (!unlink($target . '/' . $path)) {
            throw new RuntimeException('Cannot apply recovery deletion');
        }
    }
    foreach ($manifest['changed'] as $path) {
        $destination = $target . '/' . $path;
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }
        $input = $zip->getStream($path);
        $output = fopen($destination, 'wb');
        if (!$input || !$output) {
            throw new RuntimeException('Cannot restore encrypted file');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        $expected = $manifest['files'][$path];
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false || $bytes + strlen($chunk) > $expected['bytes']
                || fwrite($output, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Incomplete recovery write');
            }
            $bytes += strlen($chunk);
            hash_update($hash, $chunk);
        }
        fclose($input);
        fclose($output);
        if ($bytes !== $expected['bytes'] || hash_final($hash) !== $expected['sha256']) {
            throw new RuntimeException('Recovered file checksum mismatch; destination is incomplete');
        }
    }
    $zip->close();
}
foreach ($previous['files'] as $path => $entry) {
    if (!is_file($target . '/' . $path) || filesize($target . '/' . $path) !== $entry['bytes']
        || hash_file('sha256', $target . '/' . $path) !== $entry['sha256']) {
        throw new RuntimeException('Final recovery inventory mismatch');
    }
}
$db = new PDO('sqlite:' . $target . '/storage/php-gallery.sqlite');
if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
    throw new RuntimeException('Recovered database integrity check failed');
}
echo 'Restored and verified ' . count($previous['files']) . " files. Destination remains private; nothing deployed.\n";
