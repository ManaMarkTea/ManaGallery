<?php
// Copy to config.local.php; all paths are private and outside public/.
return [
    // Enable only on the production installation after mail transport is configured.
    'notification_mail_enabled' => false,
    'notification_origin' => 'https://gallery.example.com',
    'notification_from' => 'notifications@gallery.example.com',
    // 'discord_webhook' belongs only in config.local.php, never in Git.
    'catalogue' => __DIR__ . '/storage/gallery.json',
    'media_root' => __DIR__ . '/storage/archive',
];
