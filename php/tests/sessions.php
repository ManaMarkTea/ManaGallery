<?php

declare(strict_types=1);

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/auth.php';
$data = dirname(__DIR__) . '/test-results/sessions-' . bin2hex(random_bytes(6));
putenv('RENDERGALLERY_PHP_DATA_DIR=' . $data);
mkdir($data . '/sessions', 0700, true);
$expired = $data . '/sessions/sess_' . str_repeat('a', 32);
$recent = $data . '/sessions/sess_' . str_repeat('b', 32);
file_put_contents($expired, '');
file_put_contents($recent, '');
touch($expired, time() - 15 * 86400);
touch($recent, time() - 13 * 86400);
// Simulate a host that disables PHP's own session cleanup.
ini_set('session.gc_probability', '0');
start_session();
check((int)ini_get('session.gc_probability') > 0, 'Session cleanup is enabled even when the host disables it');
check((int)ini_get('session.gc_divisor') === 100, 'Session cleanup runs on a small share of requests');
check(session_save_path() === realpath($data) . '/sessions', 'Sessions use the private directory');
check(session_gc() >= 1, 'Cleanup removes expired session files');
check(!file_exists($expired), 'Sessions older than 14 days are deleted');
check(file_exists($recent), 'Sessions within 14 days are kept');
echo "Session cleanup checks passed.\n";
