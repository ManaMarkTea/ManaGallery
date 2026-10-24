<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/gallery.php';
require dirname(__DIR__) . '/src/database.php';
$name = $argv[1] ?? '';
$user = query('SELECT id FROM users WHERE username_key=?', [strtolower($name)])->fetch();
if (!$user) {
    fwrite(STDERR, "Register an account first, then pass its username.\n");
    exit(1);
}
query("UPDATE users SET role='admin',disabled=0,auto_approve=1 WHERE id=?", [$user['id']]);
log_activity((int)$user['id'], 'Administrator granted by local CLI', (string)$user['id']);
echo "Administrator enabled. Refresh your account page.\n";
