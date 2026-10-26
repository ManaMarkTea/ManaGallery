<?php

declare(strict_types=1);

const SOFTWARE = ['bryce' => 'Bryce', 'blender' => 'Blender', 'daz-studio' => 'DAZ Studio', 'vue' => 'Vue', 'terragen' => 'Terragen', 'other' => 'Other software'];

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function asset_url(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9._-]+$/D', $name) || str_contains($name, '..')) {
        throw new InvalidArgumentException('Invalid asset name');
    }
    $file = dirname(__DIR__) . '/public/assets/' . $name;

    return '/assets/' . $name . (is_file($file) ? '?v=' . substr(hash_file('sha256', $file), 0, 12) : '');
}
function param(array $input, string $key, string $default = ''): string
{
    return isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : $default;
}
function config(): array
{
    $defaults = require dirname(__DIR__) . '/config.example.php';
    $local = dirname(__DIR__) . '/config.local.php';
    $settings = is_file($local) ? array_replace($defaults, require $local) : $defaults;
    if ($file = getenv('RENDERGALLERY_PHP_CATALOGUE')) {
        $settings['catalogue'] = $file;
    }
    if ($media = getenv('RENDERGALLERY_PHP_MEDIA_ROOT')) {
        $settings['media_root'] = $media;
    }
    if ($previews = getenv('RENDERGALLERY_PHP_PUBLIC_PREVIEW_DIRECTORY')) {
        $settings['public_preview_directory'] = $previews;
    }

    return $settings;
}
function demo(): array
{
    $items = [];
    for ($i = 1; $i <= 302; $i++) {
        $items[] = ['id' => $i, 'title' => ($i % 3 === 0 ? 'Quiet orbit ' : 'Mountain light ') . $i,
            'description' => 'A fictional artwork for trying the PHP gallery. No real accounts or private data are included.',
            'author' => 'Demo artist ' . (($i % 9) + 1), 'categoryId' => $i % 3 === 0 ? 2 : 1,
            'software' => $i % 4 === 0 ? 'blender' : 'bryce', 'date' => '2026-10-01',
            'views' => 0, 'rating' => 0, 'image' => '', 'fullImage' => ''];
    }

    return ['items' => $items, 'categories' => [['id' => 1, 'name' => 'Landscapes'], ['id' => 2, 'name' => 'Space & abstract']]];
}
function catalogue(): array
{
    $file = config()['catalogue'];
    if (!is_file($file)) {
        return demo();
    }
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!isset($data['items'], $data['categories']) || !is_array($data['items']) || !is_array($data['categories'])) {
        throw new RuntimeException('Invalid catalogue');
    }
    $data['items'] = array_values(array_filter($data['items'], fn ($item) => is_array($item) && empty($item['hidden']) && empty($item['pending'])));

    return $data;
}
function browse(array $data, array $input): array
{
    $q = param($input, 'q');
    $category = param($input, 'category');
    $software = param($input, 'software');
    if (!isset(SOFTWARE[$software])) {
        $software = '';
    }
    $artist = param($input, 'artist');
    $sort = param($input, 'sort', 'recent');
    if (!in_array($sort, ['recent', 'popular', 'rated'], true)) {
        $sort = 'recent';
    }
    $size = (int)param($input, 'perPage', '24');
    if (!in_array($size, [24, 48, 96], true)) {
        $size = 24;
    }
    $items = array_values(array_filter($data['items'], function ($item) use ($q, $category, $software, $artist) {
        if ($artist !== '' && mb_strtolower($item['author'] ?? '', 'UTF-8') !== mb_strtolower($artist, 'UTF-8')) {
            return false;
        }
        if ($software && ($item['software'] ?? 'bryce') !== $software) {
            return false;
        }
        if ($category && (string)$item['categoryId'] !== $category) {
            return false;
        }
        $text = implode(' ', array_map(fn ($key) => (string)($item[$key] ?? ''), ['title', 'description', 'author', 'category', 'tags']));

        return !$q || (function_exists('mb_stripos') ? mb_stripos($text, $q, 0, 'UTF-8') !== false : stripos($text, $q) !== false);
    }));
    usort($items, function ($a, $b) use ($sort) {
        if ($sort === 'popular') {
            $order = ($b['views'] ?? 0) <=> ($a['views'] ?? 0);
        } elseif ($sort === 'rated') {
            $order = ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
        } else {
            $order = strcmp($b['date'] ?? '', $a['date'] ?? '');
        }

        return $order ?: (($b['id'] ?? 0) <=> ($a['id'] ?? 0));
    });
    $total = count($items);
    $pages = max(1, (int)ceil($total / $size));
    $page = max(1, min($pages, (int)param($input, 'page', '1')));

    return ['items' => array_slice($items, ($page - 1) * $size, $size), 'total' => $total, 'pages' => $pages, 'page' => $page,
        'q' => $q, 'category' => $category, 'software' => $software, 'artist' => $artist, 'sort' => $sort, 'perPage' => $size];
}
function gallery_url(array $state = [], array $changes = []): string
{
    $allowed = array_intersect_key(array_replace($state, $changes), array_flip(['q', 'category', 'software', 'artist', 'sort', 'perPage', 'page']));
    $allowed = array_filter($allowed, fn ($value) => $value !== '' && $value !== null);

    return '/' . ($allowed ? '?' . http_build_query($allowed, '', '&', PHP_QUERY_RFC3986) : '') . '#gallery';
}
function category_name(array $data, array $item): string
{
    foreach ($data['categories'] as $c) {
        if ((int)$c['id'] === (int)$item['categoryId']) {
            return (string)$c['name'];
        }
    }

    return 'Community art';
}
function media_url(array $item, bool $full = false): string
{
    if (empty($item['image']) && empty($item['fullImage'])) {
        return '/assets/placeholder.svg';
    }

    if (!$full && empty($item['hidden']) && empty($item['pending']) && isset($item['publicPreview']) && preg_match('~^/[A-Za-z0-9_/-]+/art-' . (int)$item['id'] . '-preview-[a-f0-9]{64}\.(jpg|png|webp|gif)$~D', $item['publicPreview'])) {
        return $item['publicPreview'];
    }

    return '/media/' . (int)$item['id'] . ($full ? '/full' : '/preview');
}
function media_path(array $item, bool $full, string $root): ?string
{
    $base = realpath($root);
    if (!$base) {
        return null;
    }
    $candidates = $full ? [$item['fullImage'] ?? '', $item['image'] ?? ''] : [$item['image'] ?? ''];
    foreach ($candidates as $value) {
        if (!is_string($value) || !preg_match('~^/(full|gallery)/[^\x00?#]+\.(jpe?g|png|webp|gif)$~i', $value)
            || preg_match('~(?:^|/)[.]{1,2}(?:/|$)~', $value)) {
            continue;
        }
        $file = realpath($base . str_replace('/', DIRECTORY_SEPARATOR, $value));
        if ($file && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) {
            return $file;
        }
    }

    return null;
}

function render_time(?int $timestamp): void
{
    if (!$timestamp) {
        echo 'Not recorded';

        return;
    }
    $iso = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    echo '<time data-local-time datetime="' . e($iso) . '" title="' . e(gmdate('Y-m-d H:i:s', $timestamp)) . ' UTC">' . e(gmdate('Y-m-d H:i:s', $timestamp)) . ' UTC</time>';
}
