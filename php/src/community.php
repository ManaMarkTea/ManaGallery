<?php

declare(strict_types=1);

function unique_views(int $artwork): int
{
    return (int)query('SELECT COUNT(*) FROM artwork_viewers WHERE artwork_id=? AND claimed_by IS NULL', [$artwork])->fetchColumn();
}
function record_view(int $artwork, string $browser, ?int $user): int
{
    $browserKey = 'b:' . hash('sha256', $browser);
    $accountKey = $user !== null ? 'u:' . hash('sha256', 'account:' . $user) : null;

    return with_write(function () use ($artwork, $browserKey, $accountKey) {
        if ($accountKey) {
            query('INSERT OR IGNORE INTO artwork_viewers(artwork_id,viewer_key,first_seen) VALUES (?,?,?)', [$artwork, $accountKey, time()]);
            query('INSERT INTO artwork_viewers(artwork_id,viewer_key,claimed_by,first_seen) VALUES (?,?,?,?) ON CONFLICT(artwork_id,viewer_key) DO UPDATE SET claimed_by=COALESCE(artwork_viewers.claimed_by,excluded.claimed_by)', [$artwork, $browserKey, $accountKey, time()]);
        } else {
            query('INSERT OR IGNORE INTO artwork_viewers(artwork_id,viewer_key,first_seen) VALUES (?,?,?)', [$artwork, $browserKey, time()]);
        }

        return unique_views($artwork);
    });
}
function track_page_view(int $artwork, ?array $user): int
{
    $browser = $_COOKIE['rendergallery_php_viewer'] ?? '';
    if (!is_string($browser) || !preg_match('/^[a-f0-9]{64}$/D', $browser)) {
        $browser = bin2hex(random_bytes(32));
        setcookie('rendergallery_php_viewer', $browser, ['expires' => time() + 31536000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
    }

    return record_view($artwork, $browser, $user ? (int)$user['id'] : null);
}
function apply_imported_favorites(string $identity, int $user): void
{
    $rows = query('SELECT artwork_id FROM imported_favorites WHERE identity=? AND applied=0', [$identity])->fetchAll();
    foreach ($rows as $row) {
        query('INSERT OR IGNORE INTO favorites(user_id,artwork_id,created) VALUES (?,?,?)', [$user, $row['artwork_id'], time()]);
    }
    query('UPDATE imported_favorites SET applied=1 WHERE identity=?', [$identity]);
}
function community_action(string $path, array $user, array $all): bool
{
    if ($path !== '/favorite') {
        return false;
    }
    $item = find_artwork($all, (int)param($_POST, 'artwork'));
    if (!$item || !can_view($user, $item)) {
        http_response_code(404);
        exit('Artwork not found');
    }
    with_write(function () use ($item, $user) {
        if (query('SELECT 1 FROM favorites WHERE user_id=? AND artwork_id=?', [$user['id'], $item['id']])->fetchColumn()) {
            query('DELETE FROM favorites WHERE user_id=? AND artwork_id=?', [$user['id'], $item['id']]);
        } else {
            query('INSERT INTO favorites(user_id,artwork_id,created) VALUES (?,?,?)', [$user['id'], $item['id'], time()]);
        }
    });
    redirect_to(param($_POST, 'return') === 'account' ? '/account?tab=favorites' : '/art/' . $item['id']);
}
function login_destination(string $value): string
{
    return preg_match('~^/(?:art/[1-9][0-9]*|upload|account)$~D', $value) ? $value : '/account';
}
