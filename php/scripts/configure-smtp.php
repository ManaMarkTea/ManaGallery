<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
$default = is_file(__DIR__ . '/app/src/notification-delivery.php') ? __DIR__ . '/app' : dirname(__DIR__);
$app = realpath($argv[1] ?? $default);
if (!$app || !is_file($app . '/config.local.php')) {
    throw new RuntimeException('Expected private application directory');
}
require $app . '/src/gallery.php';
require $app . '/src/notification-delivery.php';
if (!stream_isatty(STDIN)) {
    fwrite(STDERR, "Run this command in an interactive SSH terminal so the password can be entered privately.\n");
    exit(1);
}
$file = $app . '/config.local.php';
$before = file_get_contents($file);
$config = require $file;
$from = $config['notification_from'] ?? 'notifications@gallery.example.com';
$existing = $config['notification_smtp'] ?? [];
$smtp = [
    'host' => $argv[2] ?? $existing['host'] ?? '',
    'port' => (int)($argv[3] ?? $existing['port'] ?? 587),
    'username' => $argv[4] ?? $existing['username'] ?? $from,
];
if (!preg_match('/^[A-Za-z0-9.-]+$/D', $smtp['host']) || !in_array($smtp['port'], [465, 587], true)
    || !filter_var($smtp['username'], FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('Usage: configure-smtp.php PRIVATE_APP SMTP_HOST 587 MAILBOX_ADDRESS (or port 465)');
}
echo 'Configure authenticated mail for ' . $from . ' using ' . $smtp['host'] . ':' . $smtp['port'] . ".\n";
$terminal = trim((string)shell_exec('stty -g 2>/dev/null'));
if (!preg_match('/^[a-fA-F0-9:]+$/D', $terminal)) {
    fwrite(STDERR, "Cannot securely disable terminal echo. No password requested or configuration changed.\n");
    exit(1);
}
fwrite(STDOUT, 'Mailbox password (hidden): ');
exec('stty -echo', $ignored, $status);
if ($status !== 0) {
    throw new RuntimeException('Cannot hide password entry');
}
try {
    $password = fgets(STDIN);
    if ($password === false) {
        throw new RuntimeException('No password entered');
    }$smtp['password'] = rtrim($password, "\r\n");
} finally {
    exec('stty ' . escapeshellarg($terminal));
    echo "\n";
}
$mail = null;
try {
    $mail = notification_smtp_client($smtp, $from);
    if (!$mail->smtpConnect()) {
        throw new RuntimeException('SMTP authentication failed');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "SMTP connection/authentication failed. Check the new mailbox and password. No configuration changed; no email sent.\n");
    exit(1);
} finally {
    if ($mail) {
        $mail->smtpClose();
    }
}
$lock = fopen($app . '/storage/site.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
    throw new RuntimeException('Cannot acquire configuration lock');
}
if (file_get_contents($file) !== $before) {
    throw new RuntimeException('Configuration changed during setup. Please run again.');
}
// Retain a private rollback copy without the new SMTP password.
$backup = dirname($app) . '/config-before-smtp-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3)) . '.php';
if (file_put_contents($backup, $before) !== strlen($before)) {
    throw new RuntimeException('Cannot retain previous configuration');
}chmod($backup, 0600);
$config['notification_smtp'] = $smtp;
$next = "<?php\nreturn " . var_export($config, true) . ";\n";
$temp = $file . '.smtp-' . bin2hex(random_bytes(6));
if (file_put_contents($temp, $next) !== strlen($next)) {
    throw new RuntimeException('Cannot stage private SMTP configuration');
}chmod($temp, 0600);
if (!rename($temp, $file)) {
    throw new RuntimeException('Cannot install SMTP configuration');
}
flock($lock, LOCK_UN);
echo "SMTP authentication passed. Private configuration saved; notifications now use authenticated SMTP. No email sent during setup.\n";
