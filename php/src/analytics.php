<?php

declare(strict_types=1);
function analytics_configuration(string $path, ?array $user, bool $notFound = false): ?array
{
    $settings = site_settings();
    if (!$settings['analyticsEnabled'] || !$settings['analyticsId'] || $notFound || ($user['role'] ?? '') === 'admin') {
        return null;
    }
    if (!in_array($path, ['/', '/about', '/privacy'], true) && !preg_match('~^/art/[1-9][0-9]*$~D', $path)) {
        return null;
    }

    return ['id' => $settings['analyticsId'], 'title' => ($path === '/' ? 'Gallery' : (str_starts_with($path, '/art/') ? 'Artwork' : ($path === '/privacy' ? 'Privacy' : 'About'))) . ' | ' . $settings['siteName']];
}
