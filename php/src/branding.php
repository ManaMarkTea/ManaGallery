<?php

declare(strict_types=1);

function gallery_icon_url(): string
{
    $hash = site_settings()['iconHash'];

    return $hash !== '' ? '/site-icon/' . $hash . '.png' : asset_url('icon.svg');
}

function gallery_icon_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new DomainException('Choose a PNG, JPEG or WebP image.');
    }
    $path = $file['tmp_name'];
    $info = @getimagesize($path);
    if (filesize($path) > 2 * 1024 * 1024 || !$info || $info[0] > 2048 || $info[1] > 2048 || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
        throw new DomainException('Use a PNG, JPEG or WebP up to 2 MiB and 2048 pixels per side.');
    }
    $source = imagecreatefromstring(file_get_contents($path));
    if (!$source) {
        throw new DomainException('This image could not be read.');
    }
    $icon = imagecreatetruecolor(128, 128);
    imagealphablending($icon, false);
    imagesavealpha($icon, true);
    imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
    $scale = min(128 / $info[0], 128 / $info[1]);
    $width = max(1, (int)round($info[0] * $scale));
    $height = max(1, (int)round($info[1] * $scale));
    imagecopyresampled($icon, $source, (int)((128 - $width) / 2), (int)((128 - $height) / 2), 0, 0, $width, $height, $info[0], $info[1]);
    ob_start();
    imagepng($icon);
    $png = ob_get_clean();
    imagedestroy($source);
    imagedestroy($icon);

    return ['galleryIcon' => base64_encode($png), 'iconHash' => hash('sha256', $png)];
}

function serve_gallery_icon(string $hash, string $method): never
{
    $values = query("SELECT key,value FROM settings WHERE key IN ('galleryIcon','iconHash')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $png = base64_decode($values['galleryIcon'] ?? '', true);
    if (!$png || !hash_equals($values['iconHash'] ?? '', $hash) || !hash_equals(hash('sha256', $png), $hash)) {
        http_response_code(404);
        exit('Icon not found');
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400, immutable');
    header('Content-Length: ' . strlen($png));
    if ($method !== 'HEAD') {
        echo $png;
    }
    exit;
}
