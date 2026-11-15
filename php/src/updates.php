<?php

declare(strict_types=1);

const UPDATE_REPOSITORY = 'ManaMarkTea/ManaGallery';
const UPDATE_MAX_BYTES = 33554432;

function update_directory(): string
{
    $path = dirname(__DIR__, 2) . '/gallery-updates';
    if (!is_dir($path) && !mkdir($path, 0700, true)) {
        throw new RuntimeException('Cannot create private update directory.');
    }

    return $path;
}

function update_state(): array
{
    $file = update_directory() . '/state.json';

    return is_file($file) ? json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) : [];
}

function update_save(array $state): void
{
    $file = update_directory() . '/state.json';
    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    if (file_put_contents($file . '.tmp', $json) !== strlen($json) || !rename($file . '.tmp', $file)) {
        throw new RuntimeException('Cannot save update status.');
    }
}

// HTTPS only. Follow redirects ourselves so a release cannot send requests to
// arbitrary hosts, even if GitHub metadata is malformed.
function update_fetch(string $url, int $limit): string
{
    for ($redirect = 0; $redirect < 5; ++$redirect) {
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https'
            || !in_array($host, ['api.github.com', 'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PORT) !== null) {
            throw new RuntimeException('Untrusted update download address.');
        }
        $body = '';
        $location = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'ManaGallery-Updater', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$location): int {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            }]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false) {
            throw new RuntimeException('Update download failed or exceeded its size limit.');
        }
        if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
            $url = $location;
            continue;
        }
        if ($status === 404) {
            throw new DomainException('No published gallery release is available yet.');
        }
        if ($status !== 200) {
            throw new RuntimeException('GitHub returned HTTP ' . $status . '. Try again later.');
        }

        return $body;
    }
    throw new RuntimeException('Too many update redirects.');
}

function update_latest(): array
{
    $release = json_decode(update_fetch('https://api.github.com/repos/' . UPDATE_REPOSITORY . '/releases/latest', 1048576), true, 64, JSON_THROW_ON_ERROR);
    if (!empty($release['draft']) || !empty($release['prerelease'])) {
        throw new RuntimeException('Release is not stable.');
    }
    foreach ($release['assets'] ?? [] as $asset) {
        if (($asset['name'] ?? '') !== 'managallery-update.zip') {
            continue;
        }
        if (!preg_match('/^sha256:[a-f0-9]{64}$/D', $asset['digest'] ?? '') || ($asset['size'] ?? 0) < 1 || $asset['size'] > UPDATE_MAX_BYTES
            || !str_starts_with($asset['browser_download_url'] ?? '', 'https://github.com/' . UPDATE_REPOSITORY . '/releases/download/')) {
            throw new RuntimeException('Release has no valid verified update package.');
        }

        return ['id' => $release['id'], 'tag' => $release['tag_name'], 'asset' => $asset['id'], 'digest' => substr($asset['digest'], 7),
            'size' => $asset['size'], 'url' => $asset['browser_download_url'], 'checked' => time()];
    }
    throw new DomainException('The latest release does not contain a gallery update package.');
}

function update_extract(string $archive, string $destination): void
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Invalid update archive.');
    }
    try {
        $total = 0;
        $seen = [];
        if ($zip->numFiles > 500) {
            throw new RuntimeException('Too many update files.');
        }
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = $zip->statIndex($i);
            $name = $entry['name'];
            if (!preg_match('~^(?:manifest\.json|deploy-shared-host\.php|src/[a-z-]+\.(?:php|json)|templates/[a-z-]+\.php|public/index\.php|public/assets/[A-Za-z0-9._-]+|backup-tools/(?:scripts|src)/[a-z-]+\.php)$~D', $name)
                || str_contains($name, '..') || isset($seen[$name]) || ($total += $entry['size']) > 67108864) {
                throw new RuntimeException('Unsafe update archive entry.');
            }
            $seen[$name] = true;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            if ((($attributes >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('Update links are forbidden.');
            }
            $file = $destination . '/' . $name;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0700, true);
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false || file_put_contents($file, $contents) !== strlen($contents)) {
                throw new RuntimeException('Cannot stage update.');
            }
            chmod($file, 0600);
        }
        if (!isset($seen['manifest.json'], $seen['src/code-deploy.php'], $seen['public/index.php'])) {
            throw new RuntimeException('Incomplete update package.');
        }
    } finally {
        $zip->close();
    }
}

function update_targets(): array
{
    $app = dirname(__DIR__);
    $public = realpath(config()['update_public_root'] ?? $app . '/public');
    $tools = config()['update_backup_tools'] ?? null;
    if (!$public) {
        throw new RuntimeException('Update public directory is unavailable.');
    }

    return [$app, $public, $tools];
}

function update_install(array $candidate): array
{
    $latest = update_latest();
    foreach (['id', 'asset', 'digest'] as $key) {
        if (($candidate[$key] ?? null) !== $latest[$key]) {
            throw new DomainException('The release changed. Check for updates again before installing.');
        }
    }
    $root = update_directory() . '/release-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $archive = $root . '/package.zip';
    try {
        $body = update_fetch($latest['url'], UPDATE_MAX_BYTES);
        if (strlen($body) !== $latest['size'] || !hash_equals($latest['digest'], hash('sha256', $body))) {
            throw new RuntimeException('Release checksum verification failed.');
        }
        file_put_contents($archive, $body);
        unset($body);
        update_extract($archive, $root);
        [$app, $public, $tools] = update_targets();
        // Call the already-installed installer, never code from the download.
        ob_start();
        try {
            deploy_code_release($root, $app, $public, $tools);
        } finally {
            ob_end_clean();
        }
    } catch (Throwable $error) {
        // Preserve recovery copies if a switch or rollback needs attention.
        if (!is_dir($root . '/previous-code')) {
            update_remove_release($root);
        }
        throw $error;
    } finally {
        if (is_file($archive)) {
            unlink($archive);
        }
    }

    return ['tag' => $latest['tag'], 'digest' => $latest['digest'], 'time' => time(), 'directory' => $root];
}

function update_remove_release(string $directory): void
{
    if (dirname($directory) !== update_directory() || !preg_match('/^release-[a-f0-9]{16}$/D', basename($directory)) || is_link($directory)) {
        throw new RuntimeException('Unsafe update cleanup path.');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}

function update_prune(string $current): void
{
    $directories = glob(update_directory() . '/release-*', GLOB_ONLYDIR) ?: [];
    usort($directories, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    $keep = [$current];
    foreach ($directories as $directory) {
        if (in_array($directory, $keep, true)) {
            continue;
        }
        if (count($keep) < 2) {
            $keep[] = $directory;
            continue;
        }
        update_remove_release($directory);
    }
}

function update_action(string $path, ?array $user, $siteLock): never
{
    $user = require_admin($user);
    verify_csrf();
    $lock = fopen(update_directory() . '/update.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        $_SESSION['notice'] = 'Another update is running.';
        redirect_to('/admin/updates');
    }
    $installed = false;
    try {
        $state = update_state();
        if ($path === '/admin/updates/check') {
            take_attempt('update-check', (string)$user['id'], 10, 3600);
            $state['candidate'] = update_latest();
            update_save($state);
            $_SESSION['notice'] = 'Checked GitHub for the latest published release.';
        } else {
            take_attempt('update-password', (string)$user['id'], 5, 900);
            $hash = query('SELECT password FROM users WHERE id=?', [$user['id']])->fetchColumn();
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if (strlen($password) > 1024 || !$hash || !password_verify($password, $hash)) {
                throw new DomainException('Confirm your current password to deploy an update.');
            }
            if ($path !== '/admin/updates/install' || empty($state['candidate']) || param($_POST, 'digest') !== $state['candidate']['digest']) {
                throw new DomainException('Check for updates and review the release first.');
            }
            if (($state['installed']['digest'] ?? '') === $state['candidate']['digest']) {
                throw new DomainException('This release is already installed.');
            }
            set_time_limit(240);
            ignore_user_abort(true);
            // The installer needs an exclusive site lock; do not retain the
            // request's shared lock while waiting for it.
            flock($siteLock, LOCK_UN);
            $state['installed'] = update_install($state['candidate']);
            $installed = true;
            update_save($state);
            update_prune($state['installed']['directory']);
            log_activity((int)$user['id'], 'Gallery update installed', $state['installed']['tag']);
            $_SESSION['notice'] = 'Gallery updated to ' . $state['installed']['tag'] . '. Previous code is saved for recovery.';
        }
    } catch (Throwable $error) {
        error_log('Gallery update: ' . $error->getMessage());
        $_SESSION['notice'] = $installed ? 'The update installed, but saving its status or cleaning older copies failed. Check the application log.' : ($error instanceof DomainException ? $error->getMessage() : 'Update failed. Check the application log; recovery copies are preserved if a code switch needs attention.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    redirect_to('/admin/updates');
}
