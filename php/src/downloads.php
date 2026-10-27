<?php

declare(strict_types=1);

function attachment_catalogue(string $folder, bool $preview = false): array
{
    if (!in_array($folder, ['materials', 'models'], true)) {
        return [];
    }
    $file = private_directory() . '/attachments/' . $folder . '/' . ($preview ? 'previews.json' : 'catalogue.json');
    if (!is_file($file) || is_link($file) || filesize($file) > 4194304) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (($data['version'] ?? 0) !== 1 || !is_array($data['entries'] ?? null) || ($preview && ($data['geometryOnly'] ?? false) !== true)) {
        return [];
    }

    return $data['entries'];
}
function model_preview_ids(array $items): array
{
    $previews = attachment_catalogue('models', true);
    $uploads = [];
    foreach ($items as $item) {
        if ((int)$item['id'] >= UPLOAD_BASE) {
            $uploads[] = (int)$item['id'] - UPLOAD_BASE;
        }
    }
    if ($uploads) {
        $placeholders = implode(',', array_fill(0, count($uploads), '?'));
        foreach (query('SELECT upload_id FROM upload_models WHERE upload_id IN (' . $placeholders . ')', $uploads) as $row) {
            $previews[(string)(UPLOAD_BASE + (int)$row['upload_id'])] = true;
        }
    }

    return $previews;
}

function attachment_info(int $id, string $format = 'original'): ?array
{
    if ($id >= UPLOAD_BASE && in_array($format, ['original', 'glb'], true)) {
        $model = query('SELECT * FROM upload_models WHERE upload_id=?', [$id - UPLOAD_BASE])->fetch();
        if (!$model || !preg_match('/^[a-f0-9]{40}\.glb$/D', $model['filename'])) {
            return null;
        }

        return ['name' => $model['original_name'], 'bytes' => (int)$model['bytes'], 'sha256' => $model['sha256'], 'file' => upload_directory() . '/' . $model['filename'], 'extension' => '.glb', 'model' => true, 'nativeGlb' => true];
    }
    if ($id < 1 || $id >= UPLOAD_BASE || !in_array($format, ['original', 'glb'], true)) {
        return null;
    }
    $models = attachment_catalogue('models');
    $materials = attachment_catalogue('materials');
    $model = $models[(string)$id] ?? null;
    $material = $materials[(string)$id] ?? null;
    $meta = $model ?: $material;
    if (!is_array($meta)) {
        return null;
    }
    $extension = $model ? '.3ds' : '.mat';
    $folder = $model ? 'models' : 'materials';
    if ($format === 'glb') {
        $preview = attachment_catalogue('models', true)[(string)$id] ?? null;
        if (!$model || !is_array($preview) || ($preview['sourceSha256'] ?? '') !== ($model['sha256'] ?? '')) {
            return null;
        }
        $preview['name'] = preg_replace('/\.3ds$/i', '.glb', $model['name'] ?? 'model.glb');
        $meta = $preview;
        $extension = '.glb';
    }
    if (!is_string($meta['name'] ?? null) || str_contains($meta['name'], "\0") || !is_int($meta['bytes'] ?? null) || $meta['bytes'] < 1 || $meta['bytes'] > 134217728 || !preg_match('/^[a-f0-9]{64}$/D', $meta['sha256'] ?? '')) {
        return null;
    }

    return ['name' => $meta['name'], 'bytes' => $meta['bytes'], 'sha256' => $meta['sha256'], 'file' => private_directory() . '/attachments/' . $folder . '/' . $id . $extension, 'extension' => $extension, 'model' => (bool)$model];
}
function serve_attachment(array $item, ?array $user, string $format, string $method): never
{
    $info = attachment_info((int)$item['id'], $format);
    if (!$info) {
        http_response_code(404);
        exit('Download not available');
    }
    if (!can_view($user, $item)) {
        http_response_code(404);
        exit('Artwork not found');
    }
    $file = $info['file'];
    if (!is_file($file) || is_link($file)) {
        http_response_code(404);
        exit('Download not available');
    }
    $handle = fopen($file, 'rb');
    if (!$handle) {
        throw new RuntimeException('Cannot open attachment');
    }
    $hash = hash_init('sha256');
    hash_update_stream($hash, $handle);
    $actual = hash_final($hash);
    $size = fstat($handle)['size'];
    if ($size !== $info['bytes'] || !hash_equals($info['sha256'], $actual)) {
        fclose($handle);
        http_response_code(503);
        exit('Download failed its integrity check. Please contact the administrator.');
    }
    rewind($handle);
    session_write_close();
    header('Content-Type: ' . ($info['extension'] === '.glb' ? 'model/gltf-binary' : 'application/octet-stream'));
    header('Content-Length: ' . $size);
    header("Content-Disposition: attachment; filename=\"" . ($info['model'] ? 'model' : 'material') . '-' . $item['id'] . $info['extension'] . "\"; filename*=UTF-8''" . rawurlencode($info['name']));
    header('Cache-Control: private, no-store');
    if ($method !== 'HEAD') {
        fpassthru($handle);
    }
    fclose($handle);
    exit;
}
