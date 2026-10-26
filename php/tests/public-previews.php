<?php

declare(strict_types=1);
foreach (['gallery', 'database', 'auth', 'uploads', 'admin', 'public-previews'] as $module) {
    require dirname(__DIR__) . '/src/' . $module . '.php';
}
function check_preview(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}
$root = dirname(__DIR__) . '/test-results/public-previews-' . bin2hex(random_bytes(6));
$storage = $root . '/storage';
$public = $root . '/public/previews';
mkdir($storage . '/archive/gallery', 0700, true);
mkdir($storage . '/uploads', 0700);
mkdir(dirname($public), 0755, true);
putenv('RENDERGALLERY_PHP_DATA_DIR=' . $storage);
putenv('RENDERGALLERY_PHP_CATALOGUE=' . $storage . '/gallery.json');
putenv('RENDERGALLERY_PHP_MEDIA_ROOT=' . $storage . '/archive');
putenv('RENDERGALLERY_PHP_PUBLIC_PREVIEW_DIRECTORY=' . $public);
$image = imagecreatetruecolor(20, 20);
$items = [];
foreach ([1, 2, 3] as $id) {
    imagejpeg($image, $storage . '/archive/gallery/' . $id . '.jpg');
    $items[] = ['id' => $id, 'title' => 'Fictional artwork', 'author' => 'Fixture', 'categoryId' => 1, 'image' => '/gallery/' . $id . '.jpg', 'fullImage' => '/gallery/' . $id . '.jpg'];
}
file_put_contents($storage . '/gallery.json', json_encode(['items' => $items, 'categories' => [['id' => 1, 'name' => 'Fixture']]], JSON_THROW_ON_ERROR));
query("INSERT INTO users(id,username,username_key,password,created) VALUES (1,'Fixture','fixture','fixture',1)");
query("INSERT INTO artwork_overrides(artwork_id,title,description,category_id,software,hidden) VALUES (2,'Hidden','',1,'bryce',1)");
$filename = str_repeat('a', 40) . '.webp';
$preview = 'preview-' . $filename;
imagewebp($image, $storage . '/uploads/' . $filename);
imagewebp($image, $storage . '/uploads/' . $preview);
query('INSERT INTO uploads(user_id,title,description,category_id,software,filename,preview,bytes,approved,created) VALUES (1,?,?,?,?,?,?,?,0,?)', ['Pending', '', 1, 'bryce', $filename, $preview, 100, '2026-01-01']);
$upload = UPLOAD_BASE + (int)database()->lastInsertId();
$before = hash_file('sha256', $storage . '/archive/gallery/1.jpg');
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/publish-previews.php');
exec($command, $lines, $exit);
check_preview($exit === 0, 'Initial publisher completes');
check_preview(count(glob($public . '/*')) === 2, 'Only the two published archive images are exported');
check_preview(!public_preview_publish(2), 'Hidden artwork is never published');
check_preview(!public_preview_publish($upload), 'Pending upload is never published');
$all = all_artworks(apply_categories(catalogue()));
$item = find_artwork($all, 1);
$url = media_url($item);
check_preview((bool)preg_match('~^/previews/art-1-preview-[a-f0-9]{64}\.jpg$~D', $url), 'Artwork ID and image extension appear in static URL');
check_preview(media_url($item, true) === '/media/1/full', 'Full-size originals retain protected route');
check_preview(hash_file('sha256', $public . '/' . basename($url)) === $before, 'Export preserves image bytes');
check_preview(hash_file('sha256', $storage . '/archive/gallery/1.jpg') === $before, 'Private original is unchanged');
query('UPDATE artwork_overrides SET hidden=0 WHERE artwork_id=2');
check_preview(public_preview_publish(2), 'Unhidden artwork can be republished');
query('UPDATE artwork_overrides SET hidden=1 WHERE artwork_id=2');
check_preview(!public_preview_publish(2), 'Publisher rechecks visibility instead of trusting old metadata');
check_preview(!glob($public . '/art-2-preview-*'), 'Stale public copy is revoked');
query('UPDATE uploads SET approved=1');
check_preview(public_preview_publish($upload), 'Approved upload receives a public copy');
$name = query('SELECT filename FROM public_previews WHERE artwork_id=?', [$upload])->fetchColumn();
check_preview(str_ends_with($name, '.webp'), 'Uploaded preview keeps its actual MIME extension');
public_preview_change(fn () => public_preview_revoke($upload));
check_preview(!is_file($public . '/' . $name) && is_file($storage . '/uploads/' . $preview), 'Revocation removes copy while retaining private source');
file_put_contents($storage . '/archive/gallery/3.jpg', '<?php echo "Must never execute";');
check_preview(!public_preview_publish(3), 'MIME-spoofed files are not exported');
$unsafe = $public . '/art-1-preview-' . str_repeat('0', 64) . '.jpg';
mkdir($unsafe);
try {
    public_preview_change(fn () => public_preview_revoke(1));
    throw new LogicException('Expected refusal');
} catch (RuntimeException) {
    check_preview(is_dir($unsafe), 'Unexpected target stops moderation instead of traversing/deleting it');
}
rmdir($unsafe);
copy($storage . '/archive/gallery/1.jpg', $public . '/art-999-preview-' . str_repeat('b', 64) . '.jpg');
exec($command, $lines, $exit);
check_preview($exit === 0 && !glob($public . '/art-999-preview-*'), 'Rebuild removes orphaned files without metadata');
echo "Static publication, pending/hidden protection, revocation, MIME and original preservation checks passed.\n";
