<?php

declare(strict_types=1);

function storage_database_bytes(): int
{
    $bytes = 0;
    foreach (glob(private_directory() . '/php-gallery.sqlite*') ?: [] as $file) {
        if (is_file($file) && !is_link($file)) {
            $bytes += filesize($file);
        }
    }

    return $bytes;
}

function storage_report(): ?array
{
    $file = private_directory() . '/storage-report.json';

    return is_file($file) ? json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR) : null;
}

function storage_scan_file(): string
{
    return private_directory() . '/storage-scan.sqlite';
}

function storage_scan_roots(): array
{
    $roots = [config()['storage_account_root'] ?? dirname(__DIR__, 2), private_directory(), config()['media_root']];
    $roots = array_values(array_unique(array_filter(array_map('realpath', $roots))));
    usort($roots, fn ($a, $b) => strlen($a) <=> strlen($b));
    $result = [];
    foreach ($roots as $root) {
        if (!is_dir($root) || $root === DIRECTORY_SEPARATOR) {
            throw new RuntimeException('Unsafe storage scan root.');
        }
        foreach ($result as $parent) {
            if ($root === $parent || str_starts_with($root, $parent . '/')) {
                continue 2;
            }
        }
        $result[] = $root;
    }

    return $result;
}

function storage_bucket(string $path): string
{
    static $prefixes = null;
    static $private = null;
    static $account = null;
    if ($prefixes === null) {
        $private = private_directory();
        $base = dirname(__DIR__, 2);
        $prefixes = [
            config()['media_root'] => 'Archive artwork',
            $private . '/uploads' => 'Member uploads and previews',
            $private . '/attachments' => 'Model and material attachments',
            $private . '/sessions' => 'Sessions',
            $private . '/php-gallery.sqlite' => 'Gallery database',
            $private . '/php-gallery.sqlite-wal' => 'Gallery database',
            $private . '/php-gallery.sqlite-shm' => 'Gallery database',
            $private . '/error_log' => 'Application log',
            $private . '/import-backups' => 'Import recovery data',
            $base . '/native-backups' => 'Native backups and staging',
            $base . '/launch-backups' => 'Launch backups',
            $base . '/recovery-archives' => 'Legacy recovery copies',
            $base . '/backup-export' => 'Backup export',
            $base . '/releases' => 'Deployment releases',
            $base . '/gallery-updates' => 'Update packages and rollback copies',
        ];
        if (!empty(config()['public_preview_directory'])) {
            $prefixes[config()['public_preview_directory']] = 'Published preview copies';
        }
        $canonical = [];
        foreach ($prefixes as $prefix => $label) {
            $canonical[realpath($prefix) ?: $prefix] = $label;
        }
        $prefixes = $canonical;
        $account = realpath(config()['storage_account_root'] ?? $base) ?: $base;
    }
    foreach ($prefixes as $prefix => $label) {
        if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
            return $label;
        }
    }
    if (str_starts_with($path, $private . '/')) {
        return 'Other gallery runtime data';
    }
    if (str_starts_with($path, dirname(__DIR__) . '/')) {
        return 'Application code and dependencies';
    }
    if (str_starts_with($path, $account . '/')) {
        $parts = explode('/', substr($path, strlen($account) + 1));

        return 'Other files: ' . $parts[0] . (in_array($parts[0], ['public_html_sub', 'Apps'], true) && count($parts) > 2 ? '/' . $parts[1] : '');
    }

    return 'Other configured storage';
}

// A scan advances for a bounded time per admin request. Its cursor and inode
// index stay in SQLite, so large session folders do not fill PHP's memory.
function storage_scan_step(float $seconds = 5.0, bool $restart = false): array
{
    $lock = fopen(private_directory() . '/storage-scan.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new DomainException('A storage scan is already running.');
    }
    try {
        $file = storage_scan_file();
        if ($restart && is_file($file)) {
            unlink($file);
        }
        $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA busy_timeout=1000; CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY,value TEXT); CREATE TABLE IF NOT EXISTS queue (id INTEGER PRIMARY KEY,path TEXT,offset INTEGER DEFAULT 0); CREATE TABLE IF NOT EXISTS seen (device TEXT,inode TEXT,PRIMARY KEY(device,inode)); CREATE TABLE IF NOT EXISTS paths (hash TEXT PRIMARY KEY); CREATE TABLE IF NOT EXISTS totals (bucket TEXT PRIMARY KEY,bytes INTEGER DEFAULT 0,logical INTEGER DEFAULT 0,files INTEGER DEFAULT 0); CREATE TABLE IF NOT EXISTS largest (path TEXT,bytes INTEGER);');
        if (!$db->query("SELECT value FROM meta WHERE key='started'")->fetchColumn()) {
            $insert = $db->prepare('INSERT INTO queue(path) VALUES (?)');
            foreach (storage_scan_roots() as $root) {
                $insert->execute([$root]);
            }
            $db->prepare('INSERT INTO meta VALUES (?,?)')->execute(['started', (string)time()]);
            $db->exec("INSERT INTO meta VALUES ('skipped','0')");
        }
        $deadline = microtime(true) + $seconds;
        $scanFile = storage_scan_file();
        $reportFile = private_directory() . '/storage-report.json';
        $db->beginTransaction();
        $seen = $db->prepare('INSERT OR IGNORE INTO seen VALUES (?,?)');
        $pathSeen = $db->prepare('INSERT OR IGNORE INTO paths VALUES (?)');
        $add = $db->prepare('INSERT INTO totals VALUES (?,?,?,1) ON CONFLICT(bucket) DO UPDATE SET bytes=bytes+excluded.bytes,logical=logical+excluded.logical,files=files+1');
        $enqueue = $db->prepare('INSERT INTO queue(path) VALUES (?)');
        $cursor = $db->prepare('UPDATE queue SET offset=? WHERE id=?');
        while (microtime(true) < $deadline && ($directory = $db->query('SELECT * FROM queue ORDER BY id DESC LIMIT 1')->fetch())) {
            $handle = @opendir($directory['path']);
            if (!$handle) {
                $db->exec("UPDATE meta SET value=CAST(value AS INTEGER)+1 WHERE key='skipped'");
                $db->exec('DELETE FROM queue WHERE id=' . (int)$directory['id']);
                continue;
            }
            $offset = 0;
            while ($offset < (int)$directory['offset'] && readdir($handle) !== false) {
                ++$offset;
            }
            $finished = false;
            while (microtime(true) < $deadline) {
                $name = readdir($handle);
                if ($name === false) {
                    $finished = true;
                    break;
                }
                ++$offset;
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $directory['path'] . '/' . $name;
                if (str_starts_with($path, $scanFile) || $path === $reportFile) {
                    continue;
                }
                $stat = @lstat($path);
                if (!$stat || ($stat['mode'] & 0170000) === 0120000) {
                    $db->exec("UPDATE meta SET value=CAST(value AS INTEGER)+1 WHERE key='skipped'");
                    continue;
                }
                if (($stat['mode'] & 0170000) === 0040000) {
                    $enqueue->execute([$path]);
                    // Depth-first traversal makes the saved parent cursor small.
                    break;
                }
                if (($stat['mode'] & 0170000) !== 0100000) {
                    continue;
                }
                $pathSeen->execute([hash('sha256', $path)]);
                if ($pathSeen->rowCount() === 0) {
                    continue;
                }
                $seen->execute([(string)$stat['dev'], (string)$stat['ino']]);
                $unique = $seen->rowCount() > 0;
                $add->execute([storage_bucket($path), $unique ? $stat['size'] : 0, $stat['size']]);
                if ($unique && $stat['size'] >= 1048576) {
                    $db->prepare('INSERT INTO largest VALUES (?,?)')->execute([$path, $stat['size']]);
                    $db->exec('DELETE FROM largest WHERE rowid NOT IN (SELECT rowid FROM largest ORDER BY bytes DESC LIMIT 20)');
                }
            }
            closedir($handle);
            if ($finished) {
                $db->exec('DELETE FROM queue WHERE id=' . (int)$directory['id']);
            } else {
                $cursor->execute([$offset, $directory['id']]);
            }
        }
        $complete = (int)$db->query('SELECT COUNT(*) FROM queue')->fetchColumn() === 0;
        $report = ['started' => (int)$db->query("SELECT value FROM meta WHERE key='started'")->fetchColumn(), 'checked' => time(), 'complete' => $complete,
            'roots' => storage_scan_roots(), 'skipped' => (int)$db->query("SELECT value FROM meta WHERE key='skipped'")->fetchColumn(),
            'buckets' => $db->query('SELECT * FROM totals ORDER BY bytes DESC')->fetchAll(), 'largest' => $db->query('SELECT * FROM largest ORDER BY bytes DESC')->fetchAll()];
        $db->commit();
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        $cache = private_directory() . '/storage-report.json';
        if (file_put_contents($cache . '.tmp', $json) !== strlen($json) || !rename($cache . '.tmp', $cache)) {
            throw new RuntimeException('Cannot save storage report.');
        }
        $db = null;
        if ($complete) {
            unlink($file);
        }

        return $report;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
