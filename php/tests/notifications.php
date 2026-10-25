<?php

declare(strict_types=1);
$directory = dirname(__DIR__) . '/test-results/notifications-' . bin2hex(random_bytes(6));
putenv('RENDERGALLERY_PHP_DATA_DIR=' . $directory);
function config(): array
{
    return ['notification_mail_enabled' => true, 'discord_webhook' => 'fixture-webhook-configured', 'notification_origin' => 'https://example.invalid', 'discord_upload_webhooks' => [2 => 'fixture-category-two']];
}
function find_artwork(array $all, int $id): ?array
{
    foreach ($all['items'] as $item) {
        if ($item['id'] === $id) {
            return $item;
        }
    }

    return null;
}
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/notifications.php';
require dirname(__DIR__) . '/src/notification-delivery.php';
$payload = notification_discord_payload(['username' => 'Fictional artist', 'body' => '@everyone Nice work'], ['id' => 42, 'title' => 'Fictional artwork']);
check($payload['embeds'][0]['image']['url'] === 'https://example.invalid/media/42/preview', 'Discord embeds the public artwork preview');
check($payload['allowed_mentions']['parse'] === [], 'Discord comments cannot ping everyone');
notification_schema();
for ($i = 1;$i <= 5;$i++) {
    query('INSERT INTO users(id,username,username_key,password,created) VALUES (?,?,?,?,?)', [$i, 'user' . $i, 'user' . $i, 'fictional', 1]);
    query('INSERT INTO notification_contacts(identity,email,confirmed,owner_enabled,thread_enabled,updated) VALUES (?,?,1,1,1,1)', ['local:' . $i, 'user' . $i . '@example.invalid']);
}
$all = ['items' => [['id' => 7, 'ownerId' => 1, 'title' => 'Fictional artwork']]];
function add_comment(int $user, ?string $held = null, int $archived = 0): int
{
    query('INSERT INTO comments(artwork_id,user_id,username,body,created,moderation_reason,archived) VALUES (7,?,?,?,?,?,?)', [$user, 'user' . $user, 'Fictional comment', time(), $held, $archived]);

    return (int)database()->lastInsertId();
}
add_comment(2, null, 1);
add_comment(4, null, 1);
query("UPDATE notification_contacts SET thread_enabled=0 WHERE identity='local:4'");
$held = add_comment(3, 'Review');
with_write(fn () => notification_enqueue($held, $all));
check((int)query('SELECT COUNT(*) FROM notification_queue')->fetchColumn() === 0, 'Held comments do not queue notifications');
query('UPDATE comments SET moderation_reason=NULL WHERE id=?', [$held]);
with_write(fn () => notification_enqueue($held, $all));
with_write(fn () => notification_enqueue($held, $all));
check((int)query('SELECT COUNT(*) FROM notification_queue')->fetchColumn() === 3, 'One owner, one prior commenter, one Discord; no self/opt-out/duplicate');
$mails = [];
$posts = [];
$mailer = function (...$args) use (&$mails) {
    $mails[] = $args;

    return true;
};
$discord = function (...$args) use (&$posts) {
    $posts[] = $args;

    return true;
};
notification_deliver($all, $mailer, $discord);
check(count($mails) === 2 && count($posts) === 1, 'Approved comment delivered to both email recipients and Discord');
check(str_contains($mails[0][2], '/notifications/unsubscribe?') && !str_contains($mails[0][2], 'user2@'), 'Private, individual emails include unsubscribe');
notification_deliver($all, $mailer, $discord);
check(count($mails) === 2 && count($posts) === 1, 'Sent notifications do not repeat');
$archive = add_comment(5, null, 1);
notification_enqueue($archive, $all);
check((int)query('SELECT COUNT(*) FROM notification_events')->fetchColumn() === 1, 'Historical import never queues');
query("UPDATE notification_contacts SET thread_enabled=0 WHERE identity='local:5'");
$cancel = add_comment(3);
notification_enqueue($cancel, $all);
query('UPDATE comments SET deleted=1 WHERE id=?', [$cancel]);
notification_deliver($all, $mailer, $discord);
check(count($mails) === 2 && count($posts) === 1, 'Deletion before dispatch cancels notifications');
$stop = add_comment(3);
notification_enqueue($stop, $all);
query("UPDATE notification_contacts SET owner_enabled=0 WHERE identity='local:1'");
query("UPDATE users SET disabled=1 WHERE id=2");
notification_deliver($all, $mailer, $discord);
check(count($mails) === 2 && count($posts) === 2, 'Unsubscribe and disabled account rechecked at send time');
$failure = add_comment(3);
notification_enqueue($failure, $all);
notification_deliver($all, $mailer, fn () => false);
check((int)query("SELECT attempts FROM notification_queue WHERE comment_id=? AND kind='discord'", [$failure])->fetchColumn() === 1, 'Failed transport schedules bounded retry');
check(notification_token('local:1') !== notification_token('local:2'), 'Unsubscribe credentials bound to account');
query("UPDATE notification_contacts SET email='user3@example.invalid',owner_enabled=1 WHERE identity='local:1'");
query('UPDATE users SET disabled=0 WHERE id=2');
$same = add_comment(3);
notification_enqueue($same, $all);
check(!(bool)query("SELECT 1 FROM notification_queue WHERE comment_id=? AND identity='local:1'", [$same])->fetchColumn(), 'Same email as author excluded across identities');
$art = ['id' => 1000000007, 'ownerId' => 1, 'categoryId' => 2, 'title' => 'New artwork', 'author' => 'Fictional artist', 'filename' => 'fictional-original.webp'];
notification_enqueue_artwork($art + ['pending' => true]);
notification_enqueue_artwork($art + ['hidden' => true]);
notification_enqueue_artwork(array_diff_key($art, ['ownerId' => true]));
check((int)query('SELECT COUNT(*) FROM notification_artwork_events')->fetchColumn() === 0, 'Pending, hidden and imported artwork never announces');
notification_enqueue_artwork($art);
notification_enqueue_artwork($art);
check((int)query('SELECT COUNT(*) FROM notification_artwork_events')->fetchColumn() === 1, 'Artwork announcements deduplicate publication');
$announcements = [];
$announcer = function (array $item) use (&$announcements): bool {
    $announcements[] = $item;

    return true;
};
notification_deliver(['items' => [$art]], $mailer, $discord, 20, $announcer);
check(count($announcements) === 1 && notification_upload_webhook($announcements[0]) === 'fixture-category-two', 'New artwork uses its category destination');
$other = array_replace($art, ['id' => 1000000008, 'categoryId' => 3]);
notification_enqueue_artwork($other);
check((int)query('SELECT COUNT(*) FROM notification_artwork_events')->fetchColumn() === 1, 'Unmapped categories do not send to the comment channel');
$hidden = array_replace($art, ['id' => 1000000009]);
notification_enqueue_artwork($hidden);
notification_deliver(['items' => [$hidden + ['hidden' => true]]], $mailer, $discord, 20, $announcer);
check(count($announcements) === 1, 'Hidden artwork is rechecked before dispatch');
$reused = array_replace($art, ['id' => 1000000010]);
notification_enqueue_artwork($reused);
$replacement = array_replace($reused, ['filename' => 'fictional-replacement.webp']);
notification_enqueue_artwork($replacement);
notification_deliver(['items' => [$replacement]], $mailer, $discord, 20, $announcer);
check(count($announcements) === 2 && $announcements[1]['filename'] === 'fictional-replacement.webp', 'Reused upload IDs cancel the old notification and allow a new publication');
$embed = notification_discord_artwork_payload($art);
check($embed['allowed_mentions']['parse'] === [] && str_contains($embed['embeds'][0]['image']['url'], '/preview'), 'Announcements have previews and cannot ping members');
echo "Notification recipient, moderation, archive, deduplication, unsubscribe, disabled-account, retry, artwork routing and privacy checks passed.\n";
