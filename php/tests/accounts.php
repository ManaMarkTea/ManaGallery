<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/gallery.php';
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/imported-auth.php';
require dirname(__DIR__) . '/src/uploads.php';
require dirname(__DIR__) . '/src/admin.php';
require dirname(__DIR__) . '/src/comments.php';
require dirname(__DIR__) . '/src/community.php';
require dirname(__DIR__) . '/src/downloads.php';
$directory = dirname(__DIR__) . '/test-results/accounts-' . bin2hex(random_bytes(6));
putenv('RENDERGALLERY_PHP_DATA_DIR=' . $directory);
function verify(bool $condition, string $label): void
{
    if (!$condition) {
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
verify(valid_discord_invite(''), 'Discord is optional');
verify(valid_discord_invite('https://discord.gg/fixtureInvite'), 'Discord short invite accepted');
verify(valid_discord_invite('https://discord.com/invite/fixtureInvite'), 'Discord invite accepted');
foreach (['javascript:alert(1)', 'https://discord.gg.evil.invalid/invite', 'https://discord.gg/test?redirect=evil', 'https://discord.com/api/webhooks/' . '123/token'] as $url) {
    verify(!valid_discord_invite($url), 'Only public Discord invite links accepted');
}
verify((int)query('PRAGMA busy_timeout')->fetchColumn() === 5000, 'Bounded SQLite wait');
verify((int)query('PRAGMA foreign_keys')->fetchColumn() === 1, 'Foreign keys enabled');
verify(username_error('???', demo()) !== null, 'Invalid username');
verify(username_error('Demo artist 1', demo()) !== null, 'Archived names reserved');
verify(username_error('prototype_member', demo()) === null, 'Valid username');
verify(rejects(fn () => validate_password('short')), 'Short password rejected');
verify(rejects(fn () => validate_password(str_repeat('x', 73))), 'Oversize password rejected');
validate_password('fictional valid passphrase');
$hash = password_hash('fictional valid passphrase', password_algorithm());
verify(password_verify('fictional valid passphrase', $hash), 'Password matches');
verify(!password_verify('incorrect', $hash), 'Wrong password fails');
$owner = ['id' => 1, 'role' => 'member'];
$other = ['id' => 2, 'role' => 'member'];
$admin = ['id' => 3, 'role' => 'admin'];
$item = ['ownerId' => 1, 'pending' => true, 'id' => 1000000001];
verify(can_view($owner, $item) && can_view($admin, $item), 'Owner/admin pending access');
verify(!can_view(null, $item) && !can_view($other, $item), 'Pending blocked for others');
verify(!can_edit($owner, ['author' => 'prototype_member']), 'Names are not ownership');
verify(can_edit($admin, ['id' => 1]), 'Admin archive editing');
try {
    with_write(function () {
        query("INSERT INTO users(username,username_key,password,created) VALUES ('rollback','rollback','test',1)");
        throw new DomainException('Rollback');
    });
} catch (DomainException) {
}
verify((int)query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'Failed transaction rolls back');
take_attempt('fixture', 'fictional-address', 2, 3600);
take_attempt('fixture', 'fictional-address', 2, 3600);
verify(rejects(fn () => take_attempt('fixture', 'fictional-address', 2, 3600)), 'Throttle enforced');
$key = query('SELECT key FROM attempts')->fetchColumn();
verify(strlen($key) === 64 && !str_contains($key, 'fictional'), 'Throttle identity not stored raw');
query('UPDATE attempts SET expires=1');
take_attempt('fixture', 'fictional-address', 2, 3600);
verify((int)query('SELECT count FROM attempts')->fetchColumn() === 1, 'Throttle expiry resets');
verify(uploaded_media(['filename' => '../../config.local.php'], true) === null, 'Upload traversal rejected');
validate_storage_budget(MEMBER_QUOTA - 1, GALLERY_QUOTA - 1, 1, DISK_RESERVE + 1);
verify(rejects(fn () => validate_storage_budget(MEMBER_QUOTA, 0, 1, DISK_RESERVE + 1)), 'Member quota enforced');
verify(rejects(fn () => validate_storage_budget(0, GALLERY_QUOTA, 1, DISK_RESERVE + 1)), 'Gallery quota enforced');
verify(rejects(fn () => validate_storage_budget(0, 0, 1, DISK_RESERVE)), 'Disk reserve enforced');
verify(rejects(fn () => validate_storage_budget(0, 0, 1, false)), 'Unavailable disk evidence fails closed');
verify(verify_imported_password(' oldpass ', md5('oldpass'), 'bryce-md5'), 'Original PHP whitespace trimming');
verify(verify_imported_password('oldpass', '123456789:' . md5('123456789oldpass'), 'bryce-md5'), 'Original salted password');
verify(verify_imported_password('café', md5(iconv('UTF-8', 'ISO-8859-1', 'café')), 'bryce-md5'), 'Original Latin-1 password');
verify(!verify_imported_password('wrong', md5('oldpass'), 'bryce-md5'), 'Wrong original password fails');
verify(!verify_imported_password('oldpass', 'malformed', 'next-scrypt'), 'Malformed source hash fails');
verify(comment_reason('A classic glass render from Scunthorpe.') === null, 'Profanity avoids fragments');
verify(comment_reason('This is SHIT') === 'Possible profanity', 'Profanity review');
verify(comment_reason('buy viagra now', true) === 'Possible advertising spam', 'Trusted spam still held');
verify(comment_reason('Reference https://example.com') === 'Links need review', 'Untrusted links held');
verify(comment_reason('Reference https://example.com', true) === null, 'Trusted links allowed');
verify(record_view(88, 'fictional-browser-a', null) === 1, 'First anonymous view');
verify(record_view(88, 'fictional-browser-a', null) === 1, 'Browser view deduplicates');
verify(record_view(88, 'fictional-browser-b', null) === 2, 'Second anonymous browser');
verify(record_view(88, 'fictional-browser-a', 1) === 2, 'Account claim merges first guest');
verify(record_view(88, 'fictional-browser-b', 1) === 1, 'One account across browsers merges guests');
verify(record_view(88, 'fictional-browser-c', 1) === 1, 'New device same account deduplicates');
verify(record_view(88, 'fictional-browser-a', 2) === 2, 'Different account shared browser counts separately');
verify(record_view(88, 'fictional-browser-a', null) === 2, 'Logout does not create new view');
verify(login_destination('https://evil.invalid') === '/account', 'External login redirect rejected');
verify(login_destination('//evil.invalid') === '/account', 'Protocol-relative login redirect rejected');
verify(login_destination('/art/5') === '/art/5', 'Local artwork login return accepted');
verify(attachment_info(-1) === null && attachment_info(5, 'remote') === null, 'Invalid attachment selectors rejected');
echo "Passed account, permissions, hashing, throttling, transaction and storage checks.\n";
