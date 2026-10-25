<?php
// Copy to config.local.php; all paths are private and outside public/.
return [
    // Canonical HTTPS address for passkeys; use the same hostname permanently.
    // Local development on http://localhost works without this setting.
    'passkey_origin' => '',
    // Use WAL only when the database is on local storage, never NFS.
    'sqlite_journal_mode' => 'DELETE',
    // Enable only on the production installation after mail transport is configured.
    'notification_mail_enabled' => false,
    'notification_origin' => 'https://gallery.example.com',
    'notification_from' => 'notifications@gallery.example.com',
    // 'discord_webhook' belongs only in config.local.php, never in Git.
    // Optional starting value; editable in Admin > Settings.
    'discord_invite' => '',
    'catalogue' => __DIR__ . '/storage/gallery.json',
    'media_root' => __DIR__ . '/storage/archive',
];
