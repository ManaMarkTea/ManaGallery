<?php

declare(strict_types=1);

function start_session(bool $readOnly = false): void
{
    $directory = private_directory() . '/sessions';
    if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create session storage');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '1209600');
    session_save_path($directory);
    session_name('rendergallery_php_session');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start($readOnly ? ['read_and_close' => true] : [])) {
        throw new RuntimeException('Could not start session');
    }
    if (isset($_SESSION['issued']) && (int)$_SESSION['issued'] < time() - 1209600) {
        $_SESSION = [];
    }
    if (!$readOnly) {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}
function verify_csrf(): void
{
    $value = param($_POST, 'csrf');
    if (!$value || !hash_equals($_SESSION['csrf'] ?? '', $value)) {
        http_response_code(403);
        exit('This form expired. Reload the page and try again.');
    }
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $origin = (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off' ? 'http://' : 'https://') . $_SERVER['HTTP_HOST'];
        if ($_SERVER['HTTP_ORIGIN'] !== $origin) {
            http_response_code(403);
            exit('Cross-origin forms are not accepted.');
        }
    }
}
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $user = query('SELECT id,username,role,disabled,auto_approve,created,last_login,last_seen,login_count FROM users WHERE id=?', [(int)$_SESSION['user_id']])->fetch();
    $version = query('SELECT value FROM settings WHERE key=?', ['credential:' . (int)$_SESSION['user_id']])->fetchColumn() ?: '';
    if (!hash_equals($version, $_SESSION['credential'] ?? '')) {
        unset($_SESSION['user_id']);

        return null;
    }
    if (!$user || $user['disabled']) {
        unset($_SESSION['user_id']);

        return null;
    }
    if ((int)($user['last_seen'] ?? 0) < time() - 60) {
        query('UPDATE users SET last_seen=? WHERE id=? AND (last_seen IS NULL OR last_seen < ?)', [time(), $user['id'], time() - 60]);
    }

    return $user;
}
function redirect_to(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}
function require_user(?array $user): array
{
    if (!$user) {
        redirect_to('/login');
    }

    return $user;
}
function require_admin(?array $user): array
{
    $user = require_user($user);
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Administrator access required.');
    }

    return $user;
}
function sign_in(array $user, bool $count = true): void
{
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int)$user['id'], 'issued' => time(), 'csrf' => bin2hex(random_bytes(32)),
        'credential' => query('SELECT value FROM settings WHERE key=?', ['credential:' . $user['id']])->fetchColumn() ?: ''];
    if ($count) {
        query('UPDATE users SET last_login=?,last_seen=?,login_count=login_count+1 WHERE id=?', [time(), time(), $user['id']]);
    }
}
function take_attempt(string $kind, string $identity, int $limit, int $seconds): void
{
    $secret = query("SELECT value FROM settings WHERE key='throttle_secret'")->fetchColumn();
    $key = hash_hmac('sha256', $kind . ':' . $identity, $secret);
    $count = with_write(function () use ($key, $seconds) {
        query('DELETE FROM attempts WHERE expires < ?', [time()]);
        $row = query('SELECT count,expires FROM attempts WHERE key=?', [$key])->fetch();
        if (!$row) {
            query('INSERT INTO attempts(key,count,expires) VALUES (?,1,?)', [$key, time() + $seconds]);

            return 1;
        }
        query('UPDATE attempts SET count=count+1 WHERE key=?', [$key]);

        return (int)$row['count'] + 1;
    });
    if ($count > $limit) {
        throw new DomainException('Too many attempts. Please try again later.');
    }
}
function password_algorithm(): string|int
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}
function validate_password(string $password): void
{
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new DomainException('Use a password between 12 and 72 bytes.');
    }
}
function username_error(string $name, array $archive): ?string
{
    if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9 _.-]{1,30})[A-Za-z0-9]$/D', $name)) {
        return 'Use 3–32 letters, numbers, spaces, dots, underscores or hyphens; start and end with a letter or number.';
    }
    if (query('SELECT 1 FROM imported_names WHERE username_key=?', [strtolower($name)])->fetchColumn()) {
        return 'That name belongs to an existing gallery account. Sign in with its existing password.';
    }
    foreach ($archive['items'] as $item) {
        if (strcasecmp($name, trim($item['author'] ?? '')) === 0) {
            return 'That name belongs to an archived artist. Sign in with your existing community account instead.';
        }
    }

    return null;
}
