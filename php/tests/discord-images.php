<?php

declare(strict_types=1);
putenv('RENDERGALLERY_PHP_DATA_DIR=' . dirname(__DIR__) . '/test-results/discord-images-' . bin2hex(random_bytes(6)));
function config(): array
{
    return ['notification_origin' => 'https://example.invalid'];
}
function verify_discord_image(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/uploads.php';
require dirname(__DIR__) . '/src/notifications.php';
require dirname(__DIR__) . '/src/notification-delivery.php';
query('INSERT INTO users(id,username,username_key,password,created) VALUES (1,?,?,?,?)', ['Fictional artist', 'fictional artist', 'fictional', 1]);
$name = str_repeat('a', 40) . '.webp';
$preview = 'preview-' . $name;
$directory = upload_directory();
$picture = imagecreatetruecolor(4, 4);
imagewebp($picture, $directory . '/' . $preview);
file_put_contents($directory . '/' . $name, 'Not the thumbnail');
query('INSERT INTO uploads(id,user_id,title,description,category_id,software,tags,filename,preview,bytes,approved,created) VALUES (1,1,?,?,?,?,?,?,?,?,1,?)', ['Fictional model', '', 1, 'other', '', $name, $preview, 1, '2026-01-01']);
$art = media_artwork(UPLOAD_BASE + 1, null);
foreach ([notification_discord_artwork_payload($art), notification_discord_payload(['username' => 'Reader', 'body' => 'Nice'], $art)] as $payload) {
    $request = notification_discord_request($payload, $art);
    $body = $request['body'];
    verify_discord_image($request['headers'] === [], 'cURL must supply its own multipart boundary');
    verify_discord_image($body['files[0]'] instanceof CURLStringFile, 'Artwork and comments attach their preview');
    $file = $body['files[0]'];
    verify_discord_image($file->data === file_get_contents($directory . '/' . $preview), 'Attach only the thumbnail, never the model or full-size file');
    verify_discord_image($file->mime === 'image/webp', 'Attachment MIME comes from the actual thumbnail');
    $json = json_decode($body['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    verify_discord_image($json['embeds'][0]['image']['url'] === 'attachment://' . $file->postname, 'Embed references the attached file instead of an external URL');
    verify_discord_image($json['attachments'][0]['filename'] === $file->postname && $json['allowed_mentions']['parse'] === [], 'Attachment metadata matches and mentions remain disabled');
}
query('UPDATE uploads SET approved=0 WHERE id=1');
try {
    notification_discord_request(notification_discord_artwork_payload($art), $art);
    throw new RuntimeException('A stale approved record leaked a pending preview');
} catch (DomainException $error) {
}
query('UPDATE uploads SET approved=1 WHERE id=1');
query('INSERT INTO artwork_overrides(artwork_id,title,description,category_id,software,tags,hidden) VALUES (?,?,?,?,?,?,1)', [UPLOAD_BASE + 1, 'Fictional model', '', 1, 'other', '']);
try {
    notification_discord_request(notification_discord_artwork_payload($art), $art);
    throw new RuntimeException('A stale visible record leaked a hidden preview');
} catch (DomainException $error) {
}
query('DELETE FROM artwork_overrides');
file_put_contents($directory . '/' . $preview, 'Not an image');
$request = notification_discord_request(notification_discord_artwork_payload($art), $art);
verify_discord_image(is_string($request['body']), 'Non-image files are never attached');
unlink($directory . '/' . $preview);
$request = notification_discord_request(notification_discord_artwork_payload($art), $art);
verify_discord_image(is_string($request['body']), 'Missing previews preserve text delivery');
echo "Discord thumbnail attachment, multipart metadata, content type and moderation checks passed. No messages sent.\n";
