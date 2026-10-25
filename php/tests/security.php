<?php

declare(strict_types=1);

function config(): array
{
    return ['notification_mail_enabled' => true, 'notification_origin' => 'https://gallery.example.com', 'passkey_origin' => 'https://gallery.example.com'];
}
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}
function rejects(callable $work): bool
{
    try {
        $work();

        return false;
    } catch (DomainException) {
        return true;
    }
}
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/account-security.php';
require dirname(__DIR__) . '/src/passkeys.php';
require dirname(__DIR__) . '/src/notifications.php';
require dirname(__DIR__) . '/src/notification-delivery.php';
putenv('RENDERGALLERY_PHP_DATA_DIR=' . dirname(__DIR__) . '/test-results/security-' . bin2hex(random_bytes(6)));
account_security_schema();
notification_schema();
query("INSERT INTO users(id,username,username_key,password,created) VALUES (1,'Old member','old','fixture',1),(2,'New member','new','fixture',1),(3,'Other member','other','fixture',1)");
check(!account_needs_verification(['id' => 1]), 'Old accounts retain access');
with_write(fn () => account_verification_email(2, 'new@example.invalid'));
check(account_needs_verification(['id' => 2]), 'New account starts unverified');
check(rejects(fn () => account_require_verified(['id' => 2])), 'Unverified accounts cannot post');
$job = query('SELECT * FROM notification_queue')->fetch();
check(!hash_equals($job['payload'], account_email(['id' => 2])['token_hash']), 'Token stored hashed in account record');
check(!account_confirm_email(3, $job['payload']), 'Token cannot verify another account');
check(!account_confirm_email(2, str_repeat('a', 64)), 'Incorrect token rejected');
$mails = [];
$result = notification_deliver([], function ($to, $subject, $body) use (&$mails): bool {
    $mails[] = [$to, $subject, $body];

    return true;
});
check($result['sent'] === 1 && count($mails) === 1, 'Confirmation delivered through injected transport');
check(str_contains($mails[0][2], '/verify-email?account=2&token=' . $job['payload']), 'Message carries confirmation link');
check(query('SELECT payload FROM notification_queue')->fetchColumn() === '', 'Queue erases delivered bearer token');
check(account_confirm_email(2, $job['payload']), 'Valid confirmation accepted');
check(!account_needs_verification(['id' => 2]), 'Confirmed account gains access');
check(!account_confirm_email(2, $job['payload']), 'Replayed confirmation rejected');
with_write(fn () => account_verification_email(3, 'other@example.invalid'));
$old = query("SELECT payload FROM notification_queue WHERE state='pending'")->fetchColumn();
with_write(fn () => account_verification_email(3, 'changed@example.invalid'));
check(!account_confirm_email(3, $old), 'Changing address revokes old link');
query('UPDATE account_emails SET expires=? WHERE user_id=3', [time() - 1]);
$new = query("SELECT payload FROM notification_queue WHERE state='pending'")->fetchColumn();
check(!account_confirm_email(3, $new), 'Expired confirmation rejected');
$result = notification_deliver([], function (): never {
    throw new RuntimeException('Expired mail must not send');
});
check($result['cancelled'] === 1, 'Stale confirmation cancelled before delivery');
check(rejects(fn () => account_valid_email("name@example.com\r\nBcc: other@example.com")), 'Mail header injection rejected');
$_SESSION = [];
passkey_challenge('register', 'https://gallery.example.com', ['id' => 1], 'random challenge');
check(rejects(fn () => passkey_consume('register', 'https://gallery.example.com', ['id' => 2])), 'Challenge bound to account');
check(rejects(fn () => passkey_consume('register', 'https://gallery.example.com', ['id' => 1])), 'Failed challenge is consumed');
passkey_challenge('login', 'https://gallery.example.com', null, 'random challenge');
$_SESSION['passkey_challenge']['expires'] = time() - 1;
check(rejects(fn () => passkey_consume('login', 'https://gallery.example.com', null)), 'Expired passkey challenge rejected');
$client = passkey_encode(json_encode(['type' => 'webauthn.get', 'origin' => 'https://evil.gallery.example.com']));
check(rejects(fn () => passkey_client_data($client, 'https://gallery.example.com', 'webauthn.get')), 'Sibling origins rejected');
$client = passkey_encode(json_encode(['type' => 'webauthn.get', 'origin' => 'https://gallery.example.com', 'crossOrigin' => true]));
check(rejects(fn () => passkey_client_data($client, 'https://gallery.example.com', 'webauthn.get')), 'Cross-origin iframe assertions rejected');
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'gallery.example.com';
check(passkey_origin() === 'https://gallery.example.com', 'Configured origin accepted');
$_SERVER['HTTP_HOST'] = 'evil.example.com';
check(passkey_origin() === null, 'Untrusted Host cannot choose RP identity');
echo "Account security checks passed\n";
