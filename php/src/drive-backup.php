<?php

declare(strict_types=1);

final class DriveBackupClient
{
    private string $accessToken;
    private ?Closure $transport;

    public function __construct(private array $settings, ?callable $transport = null)
    {
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
        $response = $this->request('POST', 'https://oauth2.googleapis.com/token', [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $settings['clientId'],
            'client_secret' => $settings['clientSecret'],
            'refresh_token' => $settings['refreshToken'],
        ]), false);
        $token = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if ($response['status'] !== 200 || empty($token['access_token'])) {
            throw new RuntimeException('Google authorization failed; reconnect the backup account');
        }
        // Refreshing cannot expand the authorization originally granted by the user.
        if (isset($token['scope']) && $token['scope'] !== 'https://www.googleapis.com/auth/drive.file') {
            throw new RuntimeException('Backup authorization must use only drive.file');
        }
        $this->accessToken = $token['access_token'];
    }

    private function request(string $method, string $url, array $headers = [], string $body = '', bool $authorized = true): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https'
            || !in_array($parts['host'] ?? '', ['www.googleapis.com', 'oauth2.googleapis.com'], true)
            || isset($parts['user']) || isset($parts['port'])) {
            throw new RuntimeException('Unexpected Google API endpoint');
        }
        if ($authorized) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $responseHeaders = [];
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    if (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }

                    return strlen($line);
                },
            ]);
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($response !== false && $status !== 429 && $status < 500) {
                return ['status' => $status, 'headers' => $responseHeaders, 'body' => $response];
            }
            if ($attempt < 3) {
                sleep(2 ** $attempt);
            }
        }
        // Do not log request URLs, tokens or Google's echoed request bodies.
        throw new RuntimeException('Google Drive request failed after retries');
    }

    public function upload(string $file, string $sessionFile): array
    {
        $size = filesize($file);
        $md5 = hash_file('md5', $file);
        $name = basename($file);
        if (!preg_match('/^[A-Za-z0-9._-]+\.zip$/D', $name)
            || !preg_match('/^[A-Za-z0-9_-]+$/D', $this->settings['folderId'])) {
            throw new RuntimeException('Invalid backup filename or Drive folder');
        }
        $query = "'" . $this->settings['folderId'] . "' in parents and name='" . $name . "' and trashed=false";
        $lookup = $this->request('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $query,
            'fields' => 'files(id,size,md5Checksum)',
        ]));
        if ($lookup['status'] !== 200) {
            throw new RuntimeException('Cannot check destination backup folder');
        }
        foreach (json_decode($lookup['body'], true, 512, JSON_THROW_ON_ERROR)['files'] ?? [] as $existing) {
            if (($existing['md5Checksum'] ?? '') === $md5 && (int)$existing['size'] === $size) {
                return $existing;
            }
            throw new RuntimeException('A different archive already has this backup name');
        }
        $session = is_file($sessionFile) ? json_decode(file_get_contents($sessionFile), true, 512, JSON_THROW_ON_ERROR) : null;
        if (($session['md5'] ?? '') !== $md5) {
            $session = null;
        }
        $offset = 0;
        if ($session) {
            $status = $this->request('PUT', $session['url'], ['Content-Range: bytes */' . $size, 'Content-Length: 0']);
            if (in_array($status['status'], [200, 201], true)) {
                return $this->checkedResult($status, $size, $md5);
            }
            if ($status['status'] === 308) {
                $offset = $this->nextOffset($status, $size);
            } elseif (in_array($status['status'], [404, 410], true)) {
                $session = null;
            } else {
                throw new RuntimeException('Cannot resume Drive backup upload');
            }
        }
        if (!$session) {
            $response = $this->request('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,size,md5Checksum', [
                'Content-Type: application/json',
                'X-Upload-Content-Type: application/zip',
                'X-Upload-Content-Length: ' . $size,
            ], json_encode(['name' => $name, 'parents' => [$this->settings['folderId']]], JSON_THROW_ON_ERROR));
            if ($response['status'] !== 200 || empty($response['headers']['location'])) {
                throw new RuntimeException('Cannot start Drive backup upload');
            }
            $session = ['url' => $response['headers']['location'], 'md5' => $md5];
            if (file_put_contents($sessionFile, json_encode($session, JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Cannot save resumable upload state');
            }
            chmod($sessionFile, 0600);
        }
        $input = fopen($file, 'rb');
        try {
            while ($offset < $size) {
                fseek($input, $offset);
                $chunk = fread($input, 8 * 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Cannot read backup archive');
                }
                $end = $offset + strlen($chunk) - 1;
                $response = $this->request('PUT', $session['url'], [
                    'Content-Type: application/zip',
                    'Content-Range: bytes ' . $offset . '-' . $end . '/' . $size,
                ], $chunk);
                if (in_array($response['status'], [200, 201], true)) {
                    $result = $this->checkedResult($response, $size, $md5);
                    unlink($sessionFile);

                    return $result;
                }
                if ($response['status'] !== 308) {
                    throw new RuntimeException('Drive upload interrupted; saved session can resume next run');
                }
                $next = $this->nextOffset($response, $size);
                if ($next <= $offset) {
                    throw new RuntimeException('Drive upload made no progress');
                }
                $offset = $next;
                echo 'Uploaded ' . $offset . ' / ' . $size . " bytes.\n";
                flush();
            }
        } finally {
            fclose($input);
        }
        throw new RuntimeException('Upload ended without Drive checksum confirmation');
    }

    public function backupFolder(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9 _-]{1,80}$/D', $name)
            || !preg_match('/^[A-Za-z0-9_-]+$/D', $this->settings['folderId'])) {
            throw new RuntimeException('Invalid backup folder');
        }
        $query = "'" . $this->settings['folderId'] . "' in parents and name='" . $name
            . "' and mimeType='application/vnd.google-apps.folder' and trashed=false";
        $response = $this->request('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $query,
            'fields' => 'files(id)',
        ]));
        if ($response['status'] !== 200) {
            throw new RuntimeException('Cannot locate backup folder (HTTP ' . $response['status'] . ')');
        }
        $folders = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['files'] ?? [];
        if (count($folders) > 1) {
            throw new RuntimeException('Ambiguous backup folder name');
        }
        if ($folders) {
            return $folders[0]['id'];
        }
        $response = $this->request('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', [
            'Content-Type: application/json',
        ], json_encode([
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$this->settings['folderId']],
        ], JSON_THROW_ON_ERROR));
        $folder = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if ($response['status'] !== 200 || empty($folder['id'])) {
            throw new RuntimeException('Cannot create backup folder');
        }

        return $folder['id'];
    }

    private function nextOffset(array $response, int $size): int
    {
        if (!isset($response['headers']['range'])) {
            return 0;
        }
        if (!preg_match('/^bytes=0-([0-9]+)$/D', $response['headers']['range'], $matches)
            || (int)$matches[1] >= $size) {
            throw new RuntimeException('Invalid upload acknowledgement');
        }

        return (int)$matches[1] + 1;
    }

    private function checkedResult(array $response, int $size, string $md5): array
    {
        $result = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (empty($result['id']) || (int)($result['size'] ?? -1) !== $size || ($result['md5Checksum'] ?? '') !== $md5) {
            throw new RuntimeException('Drive backup checksum mismatch');
        }

        return $result;
    }
}
