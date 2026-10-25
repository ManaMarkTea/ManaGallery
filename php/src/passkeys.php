<?php

declare(strict_types=1);

function passkey_origin(): ?string
{
    $configured = rtrim(config()['passkey_origin'] ?? '', '/');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    // Local development is the only exception to an explicitly pinned HTTPS origin.
    if ($configured === '' && preg_match('/^localhost(?::[1-9][0-9]{0,4})?$/D', $host)) {
        return 'http://' . $host;
    }
    $parts = parse_url($configured);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])) {
        return null;
    }
    $actual = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $host;

    return $actual === $configured ? $configured : null;
}

function passkey_library(string $origin): \lbuchs\WebAuthn\WebAuthn
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    return new \lbuchs\WebAuthn\WebAuthn(site_settings()['siteName'], parse_url($origin, PHP_URL_HOST), ['none'], true);
}

function passkey_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function passkey_decode(string $value): string
{
    if ($value === '' || strlen($value) > 65536 || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
        throw new DomainException('Invalid passkey response.');
    }
    $bytes = base64_decode(strtr($value, '-_', '+/'), true);
    if ($bytes === false || passkey_encode($bytes) !== $value) {
        throw new DomainException('Invalid passkey response.');
    }

    return $bytes;
}

function passkey_challenge(string $operation, string $origin, ?array $user, string $bytes): void
{
    $_SESSION['passkey_challenge'] = ['operation' => $operation, 'origin' => $origin, 'user' => $user['id'] ?? null, 'expires' => time() + 300, 'bytes' => passkey_encode($bytes)];
}

function passkey_consume(string $operation, string $origin, ?array $user): string
{
    $challenge = $_SESSION['passkey_challenge'] ?? null;
    unset($_SESSION['passkey_challenge']);
    if (!$challenge || $challenge['operation'] !== $operation || $challenge['origin'] !== $origin || $challenge['user'] !== ($user['id'] ?? null) || $challenge['expires'] < time()) {
        throw new DomainException('This passkey request expired. Please try again.');
    }

    return passkey_decode($challenge['bytes']);
}

function passkey_client_data(string $encoded, string $origin, string $type): string
{
    $bytes = passkey_decode($encoded);
    $data = json_decode($bytes, true);
    if (!is_array($data) || ($data['origin'] ?? '') !== $origin || ($data['type'] ?? '') !== $type || !empty($data['crossOrigin'])) {
        throw new DomainException('The passkey response came from a different site.');
    }

    return $bytes;
}

function passkey_handle(array $user): string
{
    query('INSERT OR IGNORE INTO passkey_handles(user_id,handle) VALUES (?,?)', [$user['id'], passkey_encode(random_bytes(32))]);

    return query('SELECT handle FROM passkey_handles WHERE user_id=?', [$user['id']])->fetchColumn();
}

function passkey_request(string $path, ?array $user): array
{
    $origin = passkey_origin();
    if ($origin === null) {
        throw new DomainException('Passkeys are unavailable at this address. Use your password.');
    }
    account_security_schema();
    $webAuthn = passkey_library($origin);
    take_attempt('passkey-ip', $_SERVER['REMOTE_ADDR'] ?? 'local', 60, 900);
    if (str_starts_with($path, '/passkeys/register/') || $path === '/passkeys/delete') {
        if (!$user) {
            throw new DomainException('Sign in before managing passkeys.');
        }
        account_require_verified($user);
    }
    if ($path === '/passkeys/register/options') {
        account_check_password($user);
        $existing = query('SELECT credential_id FROM passkeys WHERE user_id=?', [$user['id']])->fetchAll(PDO::FETCH_COLUMN);
        if (count($existing) >= 10) {
            throw new DomainException('Remove an old passkey before adding another (10 maximum).');
        }
        $args = $webAuthn->getCreateArgs(passkey_decode(passkey_handle($user)), $user['username'], $user['username'], 60, 'required', true, null, array_map(passkey_decode(...), $existing));
        passkey_challenge('register', $origin, $user, $webAuthn->getChallenge()->getBinaryString());

        return ['options' => $args];
    }
    if ($path === '/passkeys/register/finish') {
        $challenge = passkey_consume('register', $origin, $user);
        $client = passkey_client_data(param($_POST, 'clientData'), $origin, 'webauthn.create');
        $credential = $webAuthn->processCreate($client, passkey_decode(param($_POST, 'attestation')), $challenge, true, true, false);
        $name = mb_substr(param($_POST, 'name', 'My passkey'), 0, 80) ?: 'My passkey';
        with_write(function () use ($user, $credential, $name): void {
            if ((int)query('SELECT count(*) FROM passkeys WHERE user_id=?', [$user['id']])->fetchColumn() >= 10) {
                throw new DomainException('Remove an old passkey first.');
            }
            query('INSERT INTO passkeys(user_id,credential_id,public_key,counter,name,created) VALUES (?,?,?,?,?,?)', [$user['id'], passkey_encode($credential->credentialId), $credential->credentialPublicKey, $credential->signatureCounter ?? 0, $name, time()]);
            log_activity((int)$user['id'], 'Passkey added', (string)$user['id']);
        });

        return ['redirect' => '/account#passkeys'];
    }
    if ($path === '/passkeys/delete') {
        account_check_password($user);
        query('DELETE FROM passkeys WHERE id=? AND user_id=?', [(int)param($_POST, 'id'), $user['id']]);
        log_activity((int)$user['id'], 'Passkey removed', (string)$user['id']);

        return ['redirect' => '/account#passkeys'];
    }
    if ($path === '/passkeys/login/options') {
        if ($user) {
            throw new DomainException('You are already signed in.');
        }
        $args = $webAuthn->getGetArgs([], 60, true, true, true, true, true, true);
        passkey_challenge('login', $origin, null, $webAuthn->getChallenge()->getBinaryString());

        return ['options' => $args];
    }
    if ($path === '/passkeys/login/finish') {
        $challenge = passkey_consume('login', $origin, $user);
        $id = passkey_encode(passkey_decode(param($_POST, 'id')));
        $stored = query('SELECT p.*,h.handle,u.disabled FROM passkeys p JOIN passkey_handles h ON h.user_id=p.user_id JOIN users u ON u.id=p.user_id WHERE p.credential_id=?', [$id])->fetch();
        if (!$stored || $stored['disabled'] || !hash_equals($stored['handle'], param($_POST, 'userHandle'))) {
            throw new DomainException('This passkey cannot sign in to this account.');
        }
        $account = ['id' => (int)$stored['user_id']];
        account_require_verified($account);
        $client = passkey_client_data(param($_POST, 'clientData'), $origin, 'webauthn.get');
        $webAuthn->processGet($client, passkey_decode(param($_POST, 'authenticatorData')), passkey_decode(param($_POST, 'signature')), $stored['public_key'], $challenge, (int)$stored['counter'], true, true);
        $updated = query('UPDATE passkeys SET counter=?,last_used=? WHERE id=? AND counter=?', [$webAuthn->getSignatureCounter() ?? 0, time(), $stored['id'], $stored['counter']])->rowCount();
        if ($updated !== 1) {
            throw new DomainException('This passkey was changed. Please try again.');
        }
        sign_in($account);
        log_activity($account['id'], 'Signed in with passkey', (string)$account['id']);

        return ['redirect' => login_destination(param($_POST, 'next'))];
    }
    throw new DomainException('Unknown passkey action.');
}

function passkey_action(string $path, string $method, ?array $user): bool
{
    if (!str_starts_with($path, '/passkeys/')) {
        return false;
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Use POST for passkeys.']);

        return true;
    }
    verify_csrf();
    try {
        $result = passkey_request($path, $user);
    } catch (DomainException $error) {
        http_response_code(422);
        $result = ['error' => $error->getMessage()];
    } catch (\lbuchs\WebAuthn\WebAuthnException | SodiumException $error) {
        http_response_code(422);
        $result = ['error' => 'Passkey verification failed. Please try again or use your password.'];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);

    return true;
}
