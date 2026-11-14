<?php

// Build a code-only release for deploy-shared-host.php from this checkout.
// Usage: php scripts/build-release.php NEW_OUTPUT_DIRECTORY [COMMIT]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
$source = dirname(__DIR__);
$output = $argv[1] ?? '';
if ($output === '' || file_exists($output)) {
    throw new RuntimeException('Usage: build-release.php NEW_OUTPUT_DIRECTORY [COMMIT]');
}
$files = [];
foreach ([
    'src/*.php', 'src/*.json', 'templates/*.php', 'public/index.php', 'public/assets/*',
] as $pattern) {
    foreach (glob($source . '/' . $pattern) ?: [] as $file) {
        $files[substr($file, strlen($source) + 1)] = $file;
    }
}
// Command-line backup tools run from their own private directory on the host.
foreach (['scripts/*.php', 'src/*.php'] as $pattern) {
    foreach (glob($source . '/' . $pattern) ?: [] as $file) {
        $files['backup-tools/' . substr($file, strlen($source) + 1)] = $file;
    }
}
ksort($files);
mkdir($output, 0700, true);
$manifest = ['version' => 1, 'commit' => $argv[2] ?? null, 'created' => gmdate('c'), 'files' => []];
foreach ($files as $relative => $file) {
    if (is_link($file) || !is_file($file)) {
        throw new RuntimeException('Unsafe release source: ' . $relative);
    }
    $target = $output . '/' . $relative;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0700, true);
    }
    copy($file, $target);
    // Git is the reviewed source, so any current server copy may be replaced;
    // deploy-shared-host.php still keeps every replaced file for rollback.
    $manifest['files'][] = ['path' => $relative, 'after' => hash_file('sha256', $file), 'replace' => 'any'];
}
copy(__DIR__ . '/deploy-shared-host.php', $output . '/deploy-shared-host.php');
file_put_contents($output . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'Built release with ' . count($files) . " files.\n";
