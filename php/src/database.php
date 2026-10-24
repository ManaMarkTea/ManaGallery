<?php

declare(strict_types=1);

function private_directory(): string
{
    $path = getenv('RENDERGALLERY_PHP_DATA_DIR') ?: dirname(__DIR__) . '/storage';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create private storage');
    }
    $path = realpath($path);
    $public = realpath(dirname(__DIR__) . '/public');
    if ($path === $public || str_starts_with($path, $public . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Private storage cannot be public');
    }

    return $path;
}
function database(): PDO
{
    static $connection;
    if ($connection) {
        return $connection;
    }
    $file = private_directory() . '/php-gallery.sqlite';
    $connection = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $connection->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    $connection->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY, username TEXT NOT NULL, username_key TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'member' CHECK(role IN ('member','admin')),
        disabled INTEGER NOT NULL DEFAULT 0, auto_approve INTEGER NOT NULL DEFAULT 0,
        created INTEGER NOT NULL, last_login INTEGER, last_seen INTEGER, login_count INTEGER NOT NULL DEFAULT 0);
      CREATE TABLE IF NOT EXISTS uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id), title TEXT NOT NULL,
        description TEXT NOT NULL, category_id INTEGER NOT NULL, software TEXT NOT NULL, tags TEXT NOT NULL DEFAULT '',
        filename TEXT NOT NULL UNIQUE, preview TEXT NOT NULL UNIQUE, bytes INTEGER NOT NULL,
        approved INTEGER NOT NULL DEFAULT 0, created TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS artwork_overrides (
        artwork_id INTEGER PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL, category_id INTEGER NOT NULL,
        software TEXT NOT NULL, tags TEXT NOT NULL DEFAULT '', hidden INTEGER NOT NULL DEFAULT 0);
      CREATE TABLE IF NOT EXISTS attempts (key TEXT PRIMARY KEY, count INTEGER NOT NULL, expires INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS activity (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id), action TEXT NOT NULL, target TEXT NOT NULL, created INTEGER NOT NULL);");
    $connection->exec("CREATE TABLE IF NOT EXISTS imported_accounts (
        identity TEXT PRIMARY KEY, username TEXT NOT NULL, username_key TEXT NOT NULL UNIQUE,
        password TEXT, password_kind TEXT NOT NULL, role TEXT NOT NULL CHECK(role IN ('member','admin')),
        disabled INTEGER NOT NULL, auto_approve INTEGER NOT NULL, created INTEGER NOT NULL,
        last_login INTEGER, last_seen INTEGER, login_count INTEGER NOT NULL DEFAULT 0,
        local_user_id INTEGER UNIQUE REFERENCES users(id));
      CREATE TABLE IF NOT EXISTS imported_names (
        username_key TEXT PRIMARY KEY, identity TEXT NOT NULL REFERENCES imported_accounts(identity));
      CREATE TABLE IF NOT EXISTS imported_artwork (
        artwork_id INTEGER PRIMARY KEY, identity TEXT NOT NULL REFERENCES imported_accounts(identity));");
    $connection->exec("CREATE TABLE IF NOT EXISTS publishing_overrides (identity TEXT PRIMARY KEY, auto_approve INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS category_overrides (id INTEGER PRIMARY KEY, name TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', sort_order INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0);
      CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY AUTOINCREMENT, artwork_id INTEGER NOT NULL, user_id INTEGER REFERENCES users(id), import_identity TEXT, username TEXT NOT NULL, body TEXT NOT NULL, created INTEGER NOT NULL, moderation_reason TEXT, archived INTEGER NOT NULL DEFAULT 0, source_key TEXT UNIQUE, deleted INTEGER NOT NULL DEFAULT 0);
      CREATE INDEX IF NOT EXISTS php_comments_artwork ON comments(artwork_id,deleted,moderation_reason);");
    $connection->exec("CREATE TABLE IF NOT EXISTS favorites (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, artwork_id INTEGER NOT NULL, created INTEGER NOT NULL, PRIMARY KEY(user_id,artwork_id));
      CREATE TABLE IF NOT EXISTS imported_favorites (identity TEXT NOT NULL REFERENCES imported_accounts(identity), artwork_id INTEGER NOT NULL, applied INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(identity,artwork_id));
      CREATE TABLE IF NOT EXISTS artwork_viewers (artwork_id INTEGER NOT NULL, viewer_key TEXT NOT NULL, claimed_by TEXT, first_seen INTEGER NOT NULL, PRIMARY KEY(artwork_id,viewer_key));");
    @chmod($file, 0600);
    query("INSERT OR IGNORE INTO settings(key,value) VALUES ('throttle_secret',?)", [bin2hex(random_bytes(32))]);

    return $connection;
}
function query(string $sql, array $values = []): PDOStatement
{
    $statement = database()->prepare($sql);
    $statement->execute($values);

    return $statement;
}
function log_activity(int $userId, string $action, string $target): void
{
    query('INSERT INTO activity(user_id,action,target,created) VALUES (?,?,?,?)', [$userId, $action, $target, time()]);
}
function with_write(callable $work): mixed
{
    database()->exec('BEGIN IMMEDIATE');
    try {
        $result = $work();
        database()->exec('COMMIT');

        return $result;
    } catch (Throwable $error) {
        database()->exec('ROLLBACK');
        throw $error;
    }
}
