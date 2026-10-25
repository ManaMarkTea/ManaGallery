<?php

declare(strict_types=1);

function account_security_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    query('CREATE TABLE IF NOT EXISTS account_emails (user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE, email TEXT NOT NULL, verified INTEGER NOT NULL DEFAULT 0, token_hash TEXT, expires INTEGER, updated INTEGER NOT NULL)');
    query('CREATE TABLE IF NOT EXISTS passkey_handles (user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE, handle TEXT NOT NULL UNIQUE)');
    query('CREATE TABLE IF NOT EXISTS passkeys (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, credential_id TEXT NOT NULL UNIQUE, public_key TEXT NOT NULL, counter INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL, created INTEGER NOT NULL, last_used INTEGER)');
    $ready = true;
}

function account_email(?array $user): ?array
{
    if (!$user) {
        return null;
    }
    account_security_schema();

    return query('SELECT * FROM account_emails WHERE user_id=?', [$user['id']])->fetch() ?: null;
}

// Accounts created before verification was enabled have no row and retain access.
function account_needs_verification(?array $user): bool
{
    $email = account_email($user);

    return $email !== null && !$email['verified'];
}

function account_require_verified(array $user): void
{
    if (account_needs_verification($user)) {
        throw new DomainException('Confirm your email address from Your account before posting or downloading.');
    }
}

function account_check_password(array $user): void
{
    take_attempt('security-password', (string)$user['id'], 10, 900);
    $password = $_POST['current_password'] ?? '';
    $hash = query('SELECT password FROM users WHERE id=?', [$user['id']])->fetchColumn();
    if (!is_string($password) || strlen($password) > 1024 || !password_verify($password, $hash)) {
        throw new DomainException('The current password is incorrect.');
    }
}

function account_verification_email(int $userId, string $email): void
{
    $token = bin2hex(random_bytes(32));
    query('INSERT INTO account_emails(user_id,email,token_hash,expires,updated) VALUES (?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET email=excluded.email,verified=0,token_hash=excluded.token_hash,expires=excluded.expires,updated=excluded.updated', [$userId, $email, hash('sha256', $token), time() + 86400, time()]);
    query("UPDATE notification_queue SET state='cancelled',payload='' WHERE kind='signup-confirmation' AND identity=? AND state='pending'", [(string)$userId]);
    query('INSERT INTO notification_queue(kind,identity,email,payload,next_attempt,created) VALUES (?,?,?,?,?,?)', ['signup-confirmation', (string)$userId, $email, $token, time(), time()]);
}

function account_valid_email(string $email): string
{
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
        throw new DomainException('Enter a valid email address.');
    }

    return $email;
}

function account_resend_verification(array $user): never
{
    account_check_password($user);
    take_attempt('verify-user', (string)$user['id'], 3, 3600);
    take_attempt('verify-ip', $_SERVER['REMOTE_ADDR'] ?? 'local', 10, 3600);
    $email = account_valid_email(param($_POST, 'email'));
    if (!account_needs_verification($user)) {
        throw new DomainException('This account does not need email confirmation.');
    }
    notification_schema();
    with_write(fn () => account_verification_email((int)$user['id'], $email));
    $_SESSION['notice'] = 'A new confirmation email is queued. Check your inbox and spam folder.';
    redirect_to('/account');
}

function account_confirm_email(int $id, string $token): bool
{
    account_security_schema();
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        return false;
    }

    return with_write(function () use ($id, $token): bool {
        $changed = query('UPDATE account_emails SET verified=1,token_hash=NULL,expires=NULL,updated=? WHERE user_id=? AND verified=0 AND token_hash=? AND expires>=? AND EXISTS (SELECT 1 FROM users WHERE id=? AND disabled=0)', [time(), $id, hash('sha256', $token), time(), $id])->rowCount();

        return $changed === 1;
    });
}

// A GET never consumes a token: email scanners often open links automatically.
function account_verification_page(string $path, string $method): bool
{
    if ($path !== '/verify-email') {
        return false;
    }
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Type: text/html; charset=utf-8');
    $input = $method === 'POST' ? $_POST : $_GET;
    $id = filter_var(param($input, 'account'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $token = param($input, 'token');
    $message = '';
    if ($method === 'POST') {
        verify_csrf();
        take_attempt('verify-link', $_SERVER['REMOTE_ADDR'] ?? 'local', 30, 900);
        $ok = $id && account_confirm_email($id, $token);
        $message = $ok ? 'Email confirmed. You can now use your account.' : 'This link has expired or was already used. Request another from Your account.';
        if (!$ok) {
            http_response_code(422);
        }
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirm your email</title><link rel="stylesheet" href="' . e(asset_url('prototype.css')) . '"><main class="prototype-form-page"><h1>Confirm your email</h1>';
    if ($message !== '') {
        echo '<p role="status">' . e($message) . '</p><a href="/account">Your account →</a>';
    } else {
        echo '<p>Confirm this email address for your gallery account.</p><form method="post" action="/verify-email">' . csrf_field() . '<input type="hidden" name="account" value="' . e($id ?: '') . '"><input type="hidden" name="token" value="' . e($token) . '"><button>Confirm email address</button></form>';
    }
    echo '</main></html>';

    return true;
}
