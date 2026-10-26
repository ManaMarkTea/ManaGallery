<?php

declare(strict_types=1);

function public_previews_enabled(): bool
{
    return (config()['public_preview_directory'] ?? '') !== '';
}

function public_preview_directory(): string
{
    $configured = config()['public_preview_directory'] ?? '';
    $directory = $configured !== '' ? realpath($configured) : false;
    $private = private_directory();
    if (!$directory || is_link($configured) || $directory === $private || str_starts_with($directory, $private . DIRECTORY_SEPARATOR)
        || !is_file($directory . '/.managallery-previews') || is_link($directory . '/.managallery-previews')
        || file_get_contents($directory . '/.managallery-previews') !== "ManaGallery public previews v1\n") {
        throw new RuntimeException('Configure a dedicated public preview directory using publish-previews.php');
    }

    return $directory;
}

function public_preview_schema(): void
{
    static $ready = false;
    if (!$ready) {
        query('CREATE TABLE IF NOT EXISTS public_previews (artwork_id INTEGER PRIMARY KEY, filename TEXT NOT NULL)');
        $ready = true;
    }
}

function public_preview_filename(string $name): bool
{
    return (bool)preg_match('/^art-[1-9][0-9]*-preview-[a-f0-9]{64}\.(jpg|png|webp|gif)$/D', $name);
}

function public_preview_urls(): array
{
    if (!public_previews_enabled()) {
        return [];
    }
    public_preview_schema();
    $prefix = config()['public_preview_url'] ?? '/previews';
    if (!preg_match('~^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $prefix)) {
        throw new RuntimeException('Use an absolute local URL path for public previews');
    }
    $urls = [];
    foreach (query('SELECT artwork_id,filename FROM public_previews') as $row) {
        if (public_preview_filename($row['filename']) && str_starts_with($row['filename'], 'art-' . (int)$row['artwork_id'] . '-preview-')) {
            $urls[(int)$row['artwork_id']] = $prefix . '/' . $row['filename'];
        }
    }

    return $urls;
}

// Publication and moderation must share this lock. Originals are never moved.
function public_preview_change(callable $work): mixed
{
    if (!public_previews_enabled()) {
        return $work();
    }
    public_preview_schema();
    $lock = fopen(private_directory() . '/public-previews.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot lock preview publication');
    }
    try {
        return $work();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Caller holds public-previews.lock. Remove every prior content version first.
function public_preview_revoke(int $id): void
{
    if (!public_previews_enabled()) {
        return;
    }
    $directory = public_preview_directory();
    foreach (glob($directory . '/art-' . $id . '-preview-*') as $file) {
        if (!public_preview_filename(basename($file)) || is_link($file) || !is_file($file) || !unlink($file)) {
            throw new RuntimeException('Cannot remove public preview; moderation was stopped');
        }
    }
    query('DELETE FROM public_previews WHERE artwork_id=?', [$id]);
}

// Re-read current moderation state inside the lock, never trust an older page.
function public_preview_publish(int $id, ?array $archive = null): bool
{
    if (!public_previews_enabled()) {
        return false;
    }

    return public_preview_change(function () use ($id, $archive): bool {
        $item = media_artwork($id, null, $archive);
        if (!$item || !empty($item['pending']) || !empty($item['hidden'])) {
            public_preview_revoke($id);

            return false;
        }
        $file = isset($item['ownerId']) ? uploaded_media($item, false) : media_path($item, false, config()['media_root']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = $file ? (new finfo(FILEINFO_MIME_TYPE))->file($file) : '';
        if (!$file || !isset($extensions[$mime])) {
            public_preview_revoke($id);

            return false;
        }
        $directory = public_preview_directory();
        $digest = hash_file('sha256', $file);
        $name = 'art-' . $id . '-preview-' . $digest . '.' . $extensions[$mime];
        $destination = $directory . '/' . $name;
        if (is_link($destination)) {
            throw new RuntimeException('Unsafe public preview target');
        }
        if (!is_file($destination) || !hash_equals($digest, hash_file('sha256', $destination))) {
            $temporary = $directory . '/.next-' . bin2hex(random_bytes(16));
            $output = fopen($temporary, 'xb');
            if (!$output) {
                throw new RuntimeException('Cannot stage public preview');
            }
            chmod($temporary, 0600);
            $input = fopen($file, 'rb');
            try {
                if (!$input || stream_copy_to_stream($input, $output) !== filesize($file)) {
                    throw new RuntimeException('Incomplete public preview copy');
                }
            } finally {
                if ($input) {
                    fclose($input);
                }
                fclose($output);
            }
            if (!hash_equals($digest, hash_file('sha256', $temporary)) || !chmod($temporary, 0644) || !rename($temporary, $destination)) {
                @unlink($temporary);
                throw new RuntimeException('Cannot publish checked preview');
            }
        }
        query('INSERT INTO public_previews(artwork_id,filename) VALUES (?,?) ON CONFLICT(artwork_id) DO UPDATE SET filename=excluded.filename', [$id, $name]);
        foreach (glob($directory . '/art-' . $id . '-preview-*') as $old) {
            if (basename($old) !== $name && public_preview_filename(basename($old)) && !is_link($old) && !unlink($old)) {
                throw new RuntimeException('Cannot remove outdated public preview');
            }
        }

        return true;
    });
}
