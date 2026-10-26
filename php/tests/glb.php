<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/glb.php';

function fixture_glb(array $changes = []): string
{
    $model = array_replace([
        'asset' => ['version' => '2.0'],
        'nodes' => [['mesh' => 0]],
        'buffers' => [['byteLength' => 36]],
        'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 36]],
        'accessors' => [['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3']],
        'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]],
    ], $changes);
    $json = json_encode($model, JSON_THROW_ON_ERROR);
    $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);

    return 'glTF' . pack('VVVV', 2, 28 + strlen($json) + 36, strlen($json), 0x4e4f534a) . $json . pack('VV', 36, 0x004e4942) . str_repeat("\0", 36);
}
validate_glb(fixture_glb());
$invalid = [
    'not a model',
    fixture_glb() . 'trailing data',
    substr(fixture_glb(), 0, -1),
    fixture_glb(['buffers' => [['byteLength' => 36, 'uri' => 'https://example.invalid/model.bin']]]),
    fixture_glb(['images' => [['uri' => 'file:///etc/passwd']]]),
    fixture_glb(['images' => [['uri' => '../Textures/image.png']]]),
    fixture_glb(['images' => [['uri' => 'blob:https://example.invalid/private']]]),
    fixture_glb(['buffers' => [['byteLength' => 4000]]]),
    fixture_glb(['buffers' => 'invalid structure']),
    fixture_glb(['bufferViews' => [['buffer' => 0, 'byteLength' => 4000]]]),
    fixture_glb(['meshes' => []]),
    fixture_glb(['nodes' => [['mesh' => 0, 'children' => [0]]]]),
    fixture_glb(['nodes' => [['mesh' => 0, 'children' => [100]]]]),
    fixture_glb(['accessors' => [['type' => 'VEC3', 'count' => 1000001]]]),
    fixture_glb(['images' => [['bufferView' => 0, 'mimeType' => 'image/svg+xml']]]),
    fixture_glb(['extensionsRequired' => ['KHR_draco_mesh_compression']]),
];
foreach ($invalid as $index => $bytes) {
    try {
        validate_glb($bytes);
    } catch (DomainException $error) {
        continue;
    }
    throw new RuntimeException('Invalid model accepted: ' . $index);
}
echo "GLB framing, resource isolation, geometry limits and texture validation passed.\n";
