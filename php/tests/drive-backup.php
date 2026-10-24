<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/drive-backup.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function response(int $status, array $body = [], array $headers = []): array
{
    return ['status' => $status, 'body' => json_encode($body), 'headers' => $headers];
}

function rejects(callable $work): void
{
    try {
        $work();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Expected backup rejection');
}

$directory = sys_get_temp_dir() . '/managallery-drive-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$file = $directory . '/managallery-fictional.zip';
file_put_contents($file, str_repeat('Fictional backup bytes', 450000));
$size = filesize($file);
$md5 = hash_file('md5', $file);
$settings = ['clientId' => 'fixture', 'clientSecret' => 'fixture', 'refreshToken' => 'fixture', 'folderId' => 'fixture-folder'];
$token = response(200, ['access_token' => 'fixture', 'scope' => 'https://www.googleapis.com/auth/drive.file']);
$result = ['id' => 'fixture-archive', 'size' => (string)$size, 'md5Checksum' => $md5];
$calls = [];
$responses = [
    $token,
    response(200, ['files' => []]),
    response(200, [], ['location' => 'https://www.googleapis.com/upload/fixture']),
    response(308, [], ['range' => 'bytes=0-8388607']),
    response(200, $result),
];
$transport = static function ($method, $url, $headers, $body) use (&$responses, &$calls) {
    $calls[] = [$method, $headers, strlen($body)];
    check(count($responses) > 0, 'No unexpected HTTP calls');

    return array_shift($responses);
};
try {
    $client = new DriveBackupClient($settings, $transport);
    check($client->upload($file, $directory . '/session.json') === $result, 'Chunked upload verifies checksum');
    check($calls[3][2] === 8 * 1024 * 1024, 'Bounded chunk memory');
    check(!file_exists($directory . '/session.json'), 'Completed session removed');

    $responses = [$token, response(200, ['files' => [$result]])];
    $client = new DriveBackupClient($settings, $transport);
    check($client->upload($file, $directory . '/session.json') === $result, 'Existing matching archive is not reuploaded');
    check(count($responses) === 0, 'No upload when remote checksum matches');

    $responses = [response(200, ['access_token' => 'fixture', 'scope' => 'https://www.googleapis.com/auth/drive'])];
    rejects(fn () => new DriveBackupClient($settings, $transport));

    $responses = [$token, response(200, ['files' => [['id' => 'fixture', 'size' => $size, 'md5Checksum' => 'wrong']]])];
    $client = new DriveBackupClient($settings, $transport);
    rejects(fn () => $client->upload($file, $directory . '/session.json'));

    file_put_contents($directory . '/session.json', json_encode(['md5' => $md5, 'url' => 'https://evil.invalid/upload']));
    $responses = [$token, response(200, ['files' => []])];
    $client = new DriveBackupClient($settings, $transport);
    rejects(fn () => $client->upload($file, $directory . '/session.json'));
    check(count($responses) === 0, 'Credentials never sent to unexpected upload host');

    echo "Drive chunking, deduplication, checksum and authorization checks passed without network requests.\n";
} finally {
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
