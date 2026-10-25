<?php

declare(strict_types=1);

function notification_schema(): void
{
    query("CREATE TABLE IF NOT EXISTS notification_contacts(identity TEXT PRIMARY KEY,email TEXT NOT NULL,confirmed INTEGER NOT NULL DEFAULT 0,owner_enabled INTEGER NOT NULL DEFAULT 0,thread_enabled INTEGER NOT NULL DEFAULT 0,confirmation_hash TEXT,confirmation_expires INTEGER,updated INTEGER NOT NULL)");
    query("CREATE TABLE IF NOT EXISTS notification_events(comment_id INTEGER PRIMARY KEY REFERENCES comments(id),created INTEGER NOT NULL)");
    query('CREATE TABLE IF NOT EXISTS notification_artwork_events(artwork_key TEXT PRIMARY KEY,created INTEGER NOT NULL)');
    query("CREATE TABLE IF NOT EXISTS notification_queue(id INTEGER PRIMARY KEY,kind TEXT NOT NULL,comment_id INTEGER,identity TEXT NOT NULL,email TEXT NOT NULL DEFAULT '',payload TEXT NOT NULL DEFAULT '',state TEXT NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,next_attempt INTEGER NOT NULL,created INTEGER NOT NULL,last_error TEXT,sent INTEGER,UNIQUE(kind,comment_id,identity))");
}

function valid_discord_webhook(string $url): bool
{
    return $url === '' || (bool)preg_match('~^https://(?:discord\.com|discordapp\.com)/api/webhooks/[0-9]+/[A-Za-z0-9._-]+$~D', $url);
}

function notification_comment_webhook(): string
{
    $saved = query("SELECT value FROM settings WHERE key='discord_comment_webhook'")->fetchColumn();

    return $saved !== false ? $saved : (config()['discord_webhook'] ?? '');
}

function notification_category_webhook(int $category): string
{
    $saved = query('SELECT value FROM settings WHERE key=?', ['discord_upload:' . $category])->fetchColumn();

    return $saved !== false ? $saved : (config()['discord_upload_webhooks'][$category] ?? '');
}

function notification_artwork_fallback(): bool
{
    return query("SELECT value FROM settings WHERE key='discordArtworkFallback'")->fetchColumn() === 'true';
}

function notification_upload_webhook(array $art): string
{
    $url = notification_category_webhook((int)($art['categoryId'] ?? 0));

    return $url !== '' ? $url : (notification_artwork_fallback() ? notification_comment_webhook() : '');
}

function notification_enqueue_artwork(array $art): void
{
    if (!isset($art['ownerId']) || empty($art['filename']) || !empty($art['hidden']) || !empty($art['pending']) || notification_upload_webhook($art) === '') {
        return;
    }
    notification_schema();
    $key = $art['id'] . ':' . $art['filename'];
    query('INSERT OR IGNORE INTO notification_artwork_events(artwork_key,created) VALUES (?,?)', [$key, time()]);
    if ((int)query('SELECT changes()')->fetchColumn() !== 1) {
        return;
    }
    $payload = json_encode(['id' => $art['id'], 'filename' => $art['filename']], JSON_THROW_ON_ERROR);
    query('INSERT INTO notification_queue(kind,identity,payload,next_attempt,created) VALUES (?,?,?,?,?)', ['discord-artwork', $key, $payload, time(), time()]);
}

function notification_run_now(array $all, int $limit = 5): ?array
{
    $lock = fopen(private_directory() . '/notifications-worker.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return null;
    }
    try {
        require_once __DIR__ . '/notification-delivery.php';
        $counts = notification_deliver($all, null, null, $limit);
        query("INSERT INTO settings(key,value) VALUES ('notifications_last_run',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", [(string)time()]);

        return $counts;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function notification_schedule_delivery(array $all): void
{
    static $scheduled = false;
    if ($scheduled || PHP_SAPI === 'cli' || !function_exists('fastcgi_finish_request')) {
        return;
    }
    $scheduled = true;
    register_shutdown_function(static function () use ($all): void {
        session_write_close();
        fastcgi_finish_request();
        try {
            notification_run_now($all, 5);
        } catch (Throwable) {
            error_log('Notification delivery deferred to the scheduled worker.');
        }
    });
}
function notification_identity(int $id): string
{
    return query('SELECT identity FROM imported_accounts WHERE local_user_id=?', [$id])->fetchColumn() ?: 'local:' . $id;
}
function notification_available(string $identity): bool
{
    if (str_starts_with($identity, 'local:')) {
        return (bool)query('SELECT 1 FROM users WHERE id=? AND disabled=0', [(int)substr($identity, 6)])->fetchColumn();
    }

    return (bool)query('SELECT 1 FROM imported_accounts i LEFT JOIN users u ON u.id=i.local_user_id WHERE i.identity=? AND i.disabled=0 AND (u.id IS NULL OR u.disabled=0)', [$identity])->fetchColumn();
}
function notification_origin(): string
{
    $origin = rtrim(config()['notification_origin'] ?? 'https://gallery.example.com', '/');
    if (!preg_match('~^https://[a-z0-9.-]+$~D', $origin)) {
        throw new RuntimeException('Invalid notification origin');
    }

    return $origin;
}
function notification_token(string $identity): string
{
    $secret = query("SELECT value FROM settings WHERE key='throttle_secret'")->fetchColumn();

    return hash_hmac('sha256', 'unsubscribe:' . $identity, $secret);
}
function notification_unsubscribe(string $identity): string
{
    return notification_origin() . '/notifications/unsubscribe?account=' . rawurlencode($identity) . '&token=' . notification_token($identity);
}
function notification_enqueue(int $id, array $all): void
{
    notification_schema();
    $c = query('SELECT * FROM comments WHERE id=?', [$id])->fetch();
    if (!$c || $c['deleted'] || $c['moderation_reason'] !== null || $c['archived'] || $c['source_key'] !== null) {
        return;
    }
    $art = find_artwork($all, (int)$c['artwork_id']);
    if (!$art || !empty($art['hidden']) || !empty($art['pending'])) {
        return;
    }
    query('INSERT OR IGNORE INTO notification_events(comment_id,created) VALUES (?,?)', [$id, time()]);
    if ((int)query('SELECT changes()')->fetchColumn() !== 1) {
        return;
    }
    $author = $c['user_id'] ? notification_identity((int)$c['user_id']) : $c['import_identity'];
    $authorEmail = query('SELECT lower(email) FROM notification_contacts WHERE identity=?', [$author])->fetchColumn();
    $recipients = [];
    $owner = isset($art['ownerId']) ? notification_identity((int)$art['ownerId']) : query('SELECT identity FROM imported_artwork WHERE artwork_id=?', [$art['id']])->fetchColumn();
    if ($owner) {
        $recipients[$owner] = 'owner_enabled';
    }
    foreach (query('SELECT DISTINCT user_id,import_identity FROM comments WHERE artwork_id=? AND id<>? AND created<=? AND deleted=0 AND moderation_reason IS NULL', [$art['id'], $id, $c['created']])->fetchAll() as $previous) {
        $identity = $previous['user_id'] ? notification_identity((int)$previous['user_id']) : $previous['import_identity'];
        if ($identity && !isset($recipients[$identity])) {
            $recipients[$identity] = 'thread_enabled';
        }
    }
    $emails = [];
    foreach ($recipients as $identity => $preference) {
        $contact = query('SELECT * FROM notification_contacts WHERE identity=?', [$identity])->fetch();
        if (!$contact || !$contact['confirmed'] || !$contact[$preference] || $identity === $author || !notification_available($identity)) {
            continue;
        }
        $email = strtolower($contact['email']);
        if ($email === $authorEmail || isset($emails[$email])) {
            continue;
        }
        $emails[$email] = true;
        query('INSERT OR IGNORE INTO notification_queue(kind,comment_id,identity,email,payload,next_attempt,created) VALUES (?,?,?,?,?,?,?)', ['comment', $id, $identity, $contact['email'], $preference, time(), time()]);
    }
    if (notification_comment_webhook() !== '') {
        query('INSERT OR IGNORE INTO notification_queue(kind,comment_id,identity,next_attempt,created) VALUES (?,?,?,?,?)', ['discord', $id, 'discord', time(), time()]);
    }
    notification_schedule_delivery($all);
}
function notification_preferences(array $user): void
{
    notification_schema();
    $identity = notification_identity((int)$user['id']);
    $email = param($_POST, 'email');
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
        throw new DomainException('Enter a valid email address.');
    }
    $old = query('SELECT * FROM notification_contacts WHERE identity=?', [$identity])->fetch();
    $owner = isset($_POST['notify_owner']) ? 1 : 0;
    $thread = isset($_POST['notify_thread']) ? 1 : 0;
    if ($old && $old['confirmed'] && strcasecmp($old['email'], $email) === 0) {
        query('UPDATE notification_contacts SET owner_enabled=?,thread_enabled=?,updated=? WHERE identity=?', [$owner, $thread, time(), $identity]);
        $_SESSION['notice'] = 'Email preferences saved.';

        return;
    }
    take_attempt('email-confirmation', $identity, 3, 3600);
    $token = bin2hex(random_bytes(32));
    $now = time();
    query('INSERT INTO notification_contacts(identity,email,owner_enabled,thread_enabled,confirmation_hash,confirmation_expires,updated) VALUES (?,?,?,?,?,?,?) ON CONFLICT(identity) DO UPDATE SET email=excluded.email,confirmed=0,owner_enabled=excluded.owner_enabled,thread_enabled=excluded.thread_enabled,confirmation_hash=excluded.confirmation_hash,confirmation_expires=excluded.confirmation_expires,updated=excluded.updated', [$identity, $email, $owner, $thread, hash('sha256', $token), $now + 86400, $now]);
    query("UPDATE notification_queue SET state='cancelled',payload='' WHERE identity=? AND kind='confirmation' AND state='pending'", [$identity]);
    query('INSERT INTO notification_queue(kind,identity,email,payload,next_attempt,created) VALUES (?,?,?,?,?,?)', ['confirmation', $identity, $email, $token, $now, $now]);
    $_SESSION['notice'] = 'Check your email to confirm this address. The link expires in 24 hours.';
}
function notification_public_action(string $path, string $method): bool
{
    if (!in_array($path, ['/notifications/confirm', '/notifications/unsubscribe'], true)) {
        return false;
    }
    notification_schema();
    $input = $method === 'POST' ? $_POST : $_GET;
    $identity = param($input, 'account');
    $token = param($input, 'token');
    $contact = query('SELECT * FROM notification_contacts WHERE identity=?', [$identity])->fetch();
    $confirm = $path === '/notifications/confirm';
    $valid = $contact && preg_match('/^[a-f0-9]{64}$/D', $token) && ($confirm ? ($contact['confirmation_hash'] && $contact['confirmation_expires'] >= time() && hash_equals($contact['confirmation_hash'], hash('sha256', $token))) : hash_equals(notification_token($identity), $token));
    header('Content-Type: text/html; charset=utf-8');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    if (!$valid) {
        http_response_code(400);
        echo '<h1>This link is invalid or has expired.</h1><p>Manage email notifications from your account.</p>';

        return true;
    }
    if ($method === 'POST') {
        verify_csrf();
        if ($confirm) {
            query('UPDATE notification_contacts SET confirmed=1,confirmation_hash=NULL,confirmation_expires=NULL,updated=? WHERE identity=?', [time(), $identity]);
        } else {
            query('UPDATE notification_contacts SET owner_enabled=0,thread_enabled=0,updated=? WHERE identity=?', [time(), $identity]);
        }
        echo '<h1>' . ($confirm ? 'Email address confirmed.' : 'Comment notification emails turned off.') . '</h1><p><a href="/account">Manage your preferences</a></p>';

        return true;
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Email preferences</title><h1>' . ($confirm ? 'Confirm your email address' : 'Turn off comment emails?') . '</h1><form method="post">' . csrf_field() . '<input type="hidden" name="account" value="' . e($identity) . '"><input type="hidden" name="token" value="' . e($token) . '"><button>' . ($confirm ? 'Confirm email address' : 'Unsubscribe') . '</button></form><p><a href="/">Back to the gallery</a></p></html>';

    return true;
}
