<?php

declare(strict_types=1);
const UPLOAD_BASE = 1000000000;
const MEMBER_QUOTA = 1073741824;
const GALLERY_QUOTA = 2147483648;
const DISK_RESERVE = 2147483648;

function media_timing_headers(int $requestStarted, float $sessionMs, float $lookupMs, int $fileStarted): void
{
    header(sprintf(
        'Server-Timing: session;dur=%.2F, lookup;dur=%.2F, file;dur=%.2F, app;dur=%.2F',
        $sessionMs,
        $lookupMs,
        (hrtime(true) - $fileStarted) / 1e6,
        (hrtime(true) - $requestStarted) / 1e6
    ));
}

function uploaded_artwork(array $p): array
{
    return [
            'id' => UPLOAD_BASE + (int)$p['id'], 'ownerId' => (int)$p['user_id'], 'title' => $p['title'], 'description' => $p['description'],
            'categoryId' => (int)$p['category_id'], 'author' => $p['username'], 'date' => $p['created'], 'software' => $p['software'],
            'tags' => $p['tags'], 'pending' => !(bool)$p['approved'], 'hidden' => false, 'image' => 'upload', 'fullImage' => 'upload',
            'filename' => $p['filename'], 'preview' => $p['preview'], 'views' => 0, 'rating' => 0];
}

// Image requests need one record and its visibility, not gallery-wide view counts.
function media_artwork(int $id, ?array $user): ?array
{
    if ($id >= UPLOAD_BASE) {
        $row = query('SELECT p.*,u.username FROM uploads p JOIN users u ON u.id=p.user_id WHERE p.id=?', [$id - UPLOAD_BASE])->fetch();
        $item = $row ? uploaded_artwork($row) : null;
    } else {
        $item = find_artwork(catalogue(), $id);
    }
    if (!$item) {
        return null;
    }
    $hidden = query('SELECT hidden FROM artwork_overrides WHERE artwork_id=?', [$id])->fetchColumn();
    if ($hidden !== false) {
        $item['hidden'] = (bool)$hidden;
    }
    if (!empty($item['hidden']) && !isset($item['ownerId']) && $user && $user['role'] !== 'admin') {
        $owner = query('SELECT u.id FROM imported_artwork a JOIN imported_accounts i ON i.identity=a.identity JOIN users u ON u.id=i.local_user_id WHERE a.artwork_id=? AND u.disabled=0', [$id])->fetchColumn();
        if ($owner !== false) {
            $item['archiveOwnerId'] = (int)$owner;
        }
    }

    return $item;
}

function all_artworks(array $archive): array
{
    $owners = query('SELECT a.artwork_id,u.id FROM imported_artwork a JOIN imported_accounts i ON i.identity=a.identity JOIN users u ON u.id=i.local_user_id WHERE u.disabled=0')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($archive['items'] as &$original) {
        if (isset($owners[$original['id']])) {
            $original['archiveOwnerId'] = (int)$owners[$original['id']];
        }
    }
    unset($original);
    $uploads = query('SELECT p.*,u.username FROM uploads p JOIN users u ON u.id=p.user_id')->fetchAll();
    foreach ($uploads as $p) {
        $archive['items'][] = uploaded_artwork($p);
    }
    $views = query('SELECT artwork_id,COUNT(*) FROM artwork_viewers WHERE claimed_by IS NULL GROUP BY artwork_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $edits = query('SELECT * FROM artwork_overrides')->fetchAll(PDO::FETCH_UNIQUE);
    foreach ($archive['items'] as &$item) {
        if (!isset($item['ownerId'])) {
            $item['archivedViews'] = $item['views'] ?? 0;
        }
        $item['views'] = (int)($views[$item['id']] ?? 0);
        if (!isset($edits[$item['id']])) {
            continue;
        }
        $edit = $edits[$item['id']];
        foreach (['title', 'description', 'software', 'tags'] as $key) {
            $item[$key] = $edit[$key];
        }
        $item['categoryId'] = (int)$edit['category_id'];
        $item['hidden'] = (bool)$edit['hidden'];
    }
    unset($item);

    return $archive;
}
function can_edit(?array $user, array $item): bool
{
    return $user && ($user['role'] === 'admin' || ($item['ownerId'] ?? $item['archiveOwnerId'] ?? null) === (int)$user['id']);
}
function can_view(?array $user, array $item): bool
{
    return (empty($item['hidden']) && empty($item['pending'])) || can_edit($user, $item);
}
function find_artwork(array $data, int $id): ?array
{
    foreach ($data['items'] as $item) {
        if ((int)$item['id'] === $id) {
            return $item;
        }
    }

    return null;
}
function artwork_fields(array $data, array $input): array
{
    $title = param($input, 'title');
    $description = param($input, 'description');
    $tags = param($input, 'tags');
    if (strlen($title) < 1 || mb_strlen($title) > 160) {
        throw new DomainException('Enter a title up to 160 characters.');
    }
    if (mb_strlen($description) > 10000 || mb_strlen($tags) > 500) {
        throw new DomainException('Description or tags are too long.');
    }
    $category = (int)param($input, 'category');
    if (!in_array($category, array_map(fn ($c) => (int)$c['id'], $data['categories']), true)) {
        throw new DomainException('Choose a valid category.');
    }
    $software = param($input, 'software', 'bryce');
    if (!isset(SOFTWARE[$software])) {
        throw new DomainException('Choose valid software.');
    }

    return [$title, $description, $category, $software, $tags];
}
function upload_directory(): string
{
    $path = private_directory() . '/uploads';
    if (!is_dir($path) && !mkdir($path, 0700) && !is_dir($path)) {
        throw new RuntimeException('Cannot create upload storage');
    }

    return $path;
}
function upload_usage(?int $userId = null): int
{
    return (int)query('SELECT COALESCE(SUM(bytes),0) FROM uploads' . ($userId !== null ? ' WHERE user_id=?' : ''), $userId !== null ? [$userId] : [])->fetchColumn();
}
function validate_storage_budget(int $memberUsed, int $galleryUsed, int $incoming, int|float|false $available): void
{
    $settings = function_exists('site_settings') ? site_settings() : ['memberQuotaMiB' => 1024, 'galleryQuotaMiB' => 2048];
    if ($memberUsed + $incoming > $settings['memberQuotaMiB'] * 1048576) {
        throw new DomainException('Your upload allowance is full.');
    }
    if ($galleryUsed + $incoming > $settings['galleryQuotaMiB'] * 1048576 || $available === false || $available - $incoming < DISK_RESERVE) {
        throw new DomainException('Gallery storage is full. Please contact an administrator.');
    }
}
function store_upload(array $user, array $data): int
{
    $fields = artwork_fields($data, $_POST);
    $upload = $_FILES['image'] ?? null;
    if (!$upload || !is_int($upload['error'] ?? null) || $upload['error'] !== UPLOAD_ERR_OK) {
        throw new DomainException('Choose an image up to 10 MiB. Check the server upload limit if the file is smaller.');
    }
    if (!is_string($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
        throw new DomainException('Invalid uploaded file.');
    }
    $size = filesize($upload['tmp_name']);
    if (!$size || $size > 10 * 1024 * 1024) {
        throw new DomainException('Images must be no larger than 10 MiB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $info = @getimagesize($upload['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 16000000 || max($info[0], $info[1]) > 12000) {
        throw new DomainException('Use a valid JPEG, PNG or WebP, up to 16 megapixels and 12,000 pixels on either side.');
    }
    $image = @imagecreatefromstring(file_get_contents($upload['tmp_name']));
    if (!$image) {
        throw new DomainException('This image could not be decoded.');
    }
    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image);
    }
    imagesavealpha($image, true);
    $dir = upload_directory();
    $filename = bin2hex(random_bytes(20)) . '.webp';
    $preview = 'preview-' . $filename;
    $written = [];
    try {
        $full = tmpfile();
        $thumb = tmpfile();
        if (!$full || !$thumb) {
            throw new RuntimeException('Cannot create image buffers');
        }
        if (!imagewebp($image, $full, 90)) {
            throw new RuntimeException('Cannot encode image');
        }
        $ratio = min(1, 640 / $info[0], 640 / $info[1]);
        $small = imagescale($image, max(1, (int)round($info[0] * $ratio)), max(1, (int)round($info[1] * $ratio)));
        if (!$small || !imagewebp($small, $thumb, 80)) {
            throw new RuntimeException('Cannot encode preview');
        }
        $bytes = fstat($full)['size'] + fstat($thumb)['size'];

        return with_write(function () use ($user, $fields, $dir, $filename, $preview, $full, $thumb, $bytes, &$written) {
            $fresh = query('SELECT disabled,auto_approve FROM users WHERE id=?', [$user['id']])->fetch();
            if (!$fresh || $fresh['disabled']) {
                throw new DomainException('This account is inactive.');
            }
            $stored = 0;
            foreach (new DirectoryIterator($dir) as $file) {
                if (!$file->isDot()) {
                    if ($file->isLink() || !$file->isFile()) {
                        throw new RuntimeException('Unexpected upload storage entry');
                    }
                    $stored += $file->getSize();
                }
            }
            validate_storage_budget(upload_usage((int)$user['id']), $stored, $bytes, disk_free_space($dir));
            foreach ([$filename => $full, $preview => $thumb] as $name => $buffer) {
                $target = $dir . '/' . $name;
                $handle = fopen($target, 'xb');
                if (!$handle) {
                    throw new RuntimeException('Cannot save image');
                }
                $written[] = $target;
                @chmod($target, 0600);
                rewind($buffer);
                try {
                    if (stream_copy_to_stream($buffer, $handle) !== fstat($buffer)['size']) {
                        throw new RuntimeException('Image write incomplete');
                    }
                } finally {
                    fclose($handle);
                }
            }
            query('INSERT INTO uploads(user_id,title,description,category_id,software,tags,filename,preview,bytes,approved,created) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$user['id'], ...$fields, $filename, $preview, $bytes, (int)(bool)$fresh['auto_approve'], gmdate('Y-m-d H:i:s')]);
            $id = (int)database()->lastInsertId();
            log_activity((int)$user['id'], 'Upload submitted', (string)(UPLOAD_BASE + $id));

            return UPLOAD_BASE + $id;
        });
    } catch (Throwable $error) {
        foreach ($written as $file) {
            @unlink($file);
        } throw $error;
    } finally {
        if (isset($full) && is_resource($full)) {
            fclose($full);
        } if (isset($thumb) && is_resource($thumb)) {
            fclose($thumb);
        }
    }
}
function uploaded_media(array $item, bool $full): ?string
{
    $filename = $item[$full ? 'filename' : 'preview'] ?? '';
    if (!preg_match('/^(?:preview-)?[a-f0-9]{40}\.webp$/D', $filename)) {
        return null;
    }
    $path = upload_directory() . '/' . $filename;

    return is_file($path) && !is_link($path) ? $path : null;
}
