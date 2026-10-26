<?php

declare(strict_types=1);

// GLB stays private and is only served as a download. Require embedded resources
// so loading a member's model never fetches arbitrary URLs or local files.
function validate_glb(string $bytes): void
{
    try {
        validate_glb_data($bytes);
    } catch (TypeError|ValueError $error) {
        throw new DomainException('The GLB model structure is invalid.');
    }
}

function validate_glb_data(string $bytes): void
{
    $length = strlen($bytes);
    if ($length < 28 || $length > 10485760 || substr($bytes, 0, 4) !== 'glTF') {
        throw new DomainException('Choose a valid GLB model up to 10 MiB.');
    }
    $header = unpack('Vversion/Vlength/VjsonLength/VjsonType', substr($bytes, 4, 16));
    $jsonLength = $header['jsonLength'];
    if ($header['version'] !== 2 || $header['length'] !== $length || $header['jsonType'] !== 0x4e4f534a || $jsonLength < 2 || $jsonLength > 2097152 || $jsonLength % 4 !== 0 || 20 + $jsonLength + 8 > $length) {
        throw new DomainException('Use a binary glTF 2.0 model with embedded geometry.');
    }
    try {
        $model = json_decode(substr($bytes, 20, $jsonLength), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new DomainException('The GLB model contains invalid JSON.');
    }
    $offset = 20 + $jsonLength;
    $binary = unpack('Vlength/Vtype', substr($bytes, $offset, 8));
    if ($binary['type'] !== 0x004e4942 || $binary['length'] % 4 !== 0 || $offset + 8 + $binary['length'] !== $length || !is_array($model) || ($model['asset']['version'] ?? '') !== '2.0') {
        throw new DomainException('Use a valid GLB 2.0 model with one embedded binary buffer.');
    }
    $checkResources = function (mixed $value) use (&$checkResources): void {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            if ($key === 'uri') {
                throw new DomainException('Embed all textures and geometry when exporting the GLB. Linked files are not supported.');
            }
            $checkResources($child);
        }
    };
    $checkResources($model);
    if (!is_array($model['buffers'] ?? null) || count($model['buffers']) !== 1 || !is_int($model['buffers'][0]['byteLength'] ?? null) || $model['buffers'][0]['byteLength'] < 1 || $model['buffers'][0]['byteLength'] > $binary['length'] || $binary['length'] - $model['buffers'][0]['byteLength'] > 3) {
        throw new DomainException('The GLB binary buffer is invalid.');
    }
    foreach (['meshes' => 4096, 'nodes' => 8192, 'accessors' => 16384, 'bufferViews' => 16384, 'images' => 64] as $key => $limit) {
        if (!is_array($model[$key] ?? []) || count($model[$key] ?? []) > $limit) {
            throw new DomainException('This model has too many objects or textures.');
        }
    }
    foreach ($model['bufferViews'] ?? [] as $view) {
        $start = $view['byteOffset'] ?? 0;
        $size = $view['byteLength'] ?? null;
        if (($view['buffer'] ?? null) !== 0 || !is_int($start) || !is_int($size) || $start < 0 || $size < 1 || $start + $size > $model['buffers'][0]['byteLength']) {
            throw new DomainException('The GLB contains an invalid buffer view.');
        }
    }
    $vertices = 0;
    $meshVertices = [];
    foreach ($model['meshes'] ?? [] as $meshIndex => $mesh) {
        $meshVertices[$meshIndex] = 0;
        foreach ($mesh['primitives'] ?? [] as $primitive) {
            $position = $primitive['attributes']['POSITION'] ?? null;
            $accessor = is_int($position) ? ($model['accessors'][$position] ?? null) : null;
            if (!is_array($accessor) || ($accessor['type'] ?? '') !== 'VEC3' || !is_int($accessor['count'] ?? null) || $accessor['count'] < 1) {
                throw new DomainException('The GLB must contain mesh geometry.');
            }
            $vertices += $accessor['count'];
            $meshVertices[$meshIndex] += $accessor['count'];
        }
    }
    if ($vertices < 1 || $vertices > 1000000) {
        throw new DomainException('Models must contain between 1 and 1,000,000 vertices.');
    }
    $state = [];
    $instances = 0;
    $visit = function (int $index, int $depth) use (&$visit, &$state, &$instances, $model, $meshVertices): void {
        if ($depth > 128 || !isset($model['nodes'][$index]) || ($state[$index] ?? 0) === 1) {
            throw new DomainException('The GLB has an invalid or excessively deep node hierarchy.');
        }
        if (($state[$index] ?? 0) === 2) {
            return;
        }
        $state[$index] = 1;
        $node = $model['nodes'][$index];
        if (isset($node['mesh'])) {
            if (!is_int($node['mesh']) || !isset($meshVertices[$node['mesh']])) {
                throw new DomainException('The GLB refers to an invalid mesh.');
            }
            $instances += $meshVertices[$node['mesh']];
        }
        foreach ($node['children'] ?? [] as $child) {
            if (!is_int($child) || $child < 0) {
                throw new DomainException('The GLB refers to an invalid node.');
            }
            $visit($child, $depth + 1);
        }
        $state[$index] = 2;
    };
    foreach (array_keys($model['nodes'] ?? []) as $index) {
        $visit($index, 0);
    }
    if ($instances < 1 || $instances > 1000000) {
        throw new DomainException('The complete scene must contain between 1 and 1,000,000 vertices.');
    }
    $pixels = 0;
    foreach ($model['images'] ?? [] as $texture) {
        $index = $texture['bufferView'] ?? null;
        $view = is_int($index) ? ($model['bufferViews'][$index] ?? null) : null;
        $info = $view ? @getimagesizefromstring(substr($bytes, $offset + 8 + ($view['byteOffset'] ?? 0), $view['byteLength'])) : false;
        if (!$info || !in_array($texture['mimeType'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true) || ($info['mime'] ?? '') !== $texture['mimeType'] || max($info[0], $info[1]) > 8192 || $info[0] * $info[1] > 16000000) {
            throw new DomainException('Use embedded PNG, JPEG or WebP textures up to 16 megapixels.');
        }
        $pixels += $info[0] * $info[1];
    }
    if ($pixels > 32000000) {
        throw new DomainException('Model textures exceed the 32 megapixel total limit.');
    }
    foreach ($model['extensionsRequired'] ?? [] as $extension) {
        if (in_array($extension, ['KHR_draco_mesh_compression', 'EXT_meshopt_compression', 'KHR_texture_basisu'], true)) {
            throw new DomainException('Export the GLB without Draco, Meshopt or KTX texture compression.');
        }
    }
}
