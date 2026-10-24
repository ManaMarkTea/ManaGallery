<?php

declare(strict_types=1);

function verify_imported_password(string $password, string $stored, string $kind): bool
{
    if (strlen($password) > 1024) {
        return false;
    }
    if ($kind === 'next-scrypt') {
        if (!preg_match('/^([a-f0-9]{32}):([a-f0-9]{128})$/iD', $stored, $parts)) {
            return false;
        }
        if (!function_exists('sodium_crypto_pwhash_scryptsalsa208sha256')) {
            throw new DomainException('Existing-account sign-in requires the PHP Sodium extension. Please contact the administrator.');
        }
        // Next.js uses Node scrypt defaults: N=16384, r=8, p=1, dkLen=64.
        // The salt is the 32-character hex STRING, not its decoded bytes.
        // Sodium selects the same parameters with these fixed resource bounds.
        $actual = sodium_crypto_pwhash_scryptsalsa208sha256(64, $password, $parts[1], 524288, 16777216);

        return hash_equals(hex2bin($parts[2]), $actual);
    }
    if ($kind === 'bryce-md5') {
        if (!preg_match('/^(?:([^:\r\n]{9}):)?([a-f0-9]{32})$/iD', $stored, $parts)) {
            return false;
        }
        $value = ($parts[1] ?? '') . trim($password, "\0\x09\x0a\x0b\x0d ");
        $expected = hex2bin($parts[2]);
        if (hash_equals($expected, md5($value, true))) {
            return true;
        }
        // The original ISO-8859-1 pages also accepted Latin-1 password bytes.
        $latin = @iconv('UTF-8', 'ISO-8859-1', $value);

        return $latin !== false && hash_equals($expected, md5($latin, true));
    }

    return false;
}
function imported_login(string $name, string $password): ?array
{
    $import = query('SELECT a.* FROM imported_accounts a JOIN imported_names n ON n.identity=a.identity WHERE n.username_key=?', [strtolower($name)])->fetch();
    if (!$import) {
        return null;
    }
    // Aliases of already-upgraded accounts must use the PHP password. Never
    // resurrect the old source hash after a password change or re-import.
    if ($import['local_user_id']) {
        $user = query('SELECT * FROM users WHERE id=?', [$import['local_user_id']])->fetch();

        return $user && !$user['disabled'] && password_verify($password, $user['password']) ? $user : null;
    }
    if ($import['disabled'] || !$import['password'] || !verify_imported_password($password, $import['password'], $import['password_kind'])) {
        return null;
    }
    // Argon2 supports passwords from Next.js that exceed bcrypt's 72-byte cap.
    // Fail explicitly rather than silently truncating those passwords.
    if (password_algorithm() === PASSWORD_BCRYPT && (strlen($password) > 72 || str_contains($password, "\0"))) {
        throw new DomainException('Migrating this password requires PHP Argon2 support. Contact the administrator.');
    }
    $hash = password_hash($password, password_algorithm());

    return with_write(function () use ($import, $hash) {
        $fresh = query('SELECT * FROM imported_accounts WHERE identity=?', [$import['identity']])->fetch();
        if (!$fresh || $fresh['disabled'] || $fresh['password'] !== $import['password']) {
            return null;
        }
        if ($fresh['local_user_id']) {
            return null;
        }
        // Existing prototype names never establish a link to a source account.
        if (query('SELECT 1 FROM users WHERE username_key=?', [$fresh['username_key']])->fetchColumn()) {
            throw new DomainException('An unrelated prototype account has this name. An administrator must resolve the collision.');
        }
        query('INSERT INTO users(username,username_key,password,role,disabled,auto_approve,created,last_login,last_seen,login_count) VALUES (?,?,?,?,?,?,?,?,?,?)', [$fresh['username'], $fresh['username_key'], $hash, $fresh['role'], $fresh['disabled'], $fresh['auto_approve'], $fresh['created'], $fresh['last_login'], $fresh['last_seen'], $fresh['login_count']]);
        $id = (int)database()->lastInsertId();
        query('UPDATE imported_accounts SET local_user_id=?,password=NULL WHERE identity=?', [$id, $fresh['identity']]);
        apply_imported_favorites($fresh['identity'], $id);
        log_activity($id, 'Existing account migrated to PHP', $fresh['identity']);

        return query('SELECT * FROM users WHERE id=?', [$id])->fetch();
    });
}
