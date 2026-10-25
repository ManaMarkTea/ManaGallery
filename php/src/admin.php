<?php

declare(strict_types=1);
function valid_discord_invite(string $url): bool
{
    return $url === '' || (bool)preg_match(
        '~^https://(?:discord\.gg/|discord\.com/invite/)[A-Za-z0-9_-]{2,100}$~D',
        $url
    );
}

function recent_application_errors(): array
{
    $file = private_directory() . '/error_log';
    if (!is_file($file)) {
        return [];
    }
    $handle = fopen($file, 'rb');
    if (!$handle) {
        return [];
    }
    fseek($handle, max(0, filesize($file) - 65536));
    $contents = stream_get_contents($handle);
    fclose($handle);
    $errors = [];
    foreach (explode("\n", $contents) as $line) {
        $start = strpos($line, '{');
        $entry = $start === false ? null : json_decode(substr($line, $start), true);
        if (is_array($entry) && preg_match('/^[a-f0-9]{16}$/D', $entry['requestId'] ?? '')) {
            $errors[] = $entry;
        }
    }

    return array_reverse(array_slice($errors, -10));
}

function site_settings(): array
{
    $values = query("SELECT key,value FROM settings WHERE key <> 'galleryIcon'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $discordInvite = $values['discordInvite'] ?? config()['discord_invite'] ?? '';

    return [
        'siteName' => $values['siteName'] ?? 'ManaGallery',
        'iconHash' => preg_match('/^[a-f0-9]{64}$/D', $values['iconHash'] ?? '') ? $values['iconHash'] : '',
        'description' => $values['description'] ?? 'A community art gallery.',
        'registrationOpen' => ($values['registrationOpen'] ?? 'true') === 'true',
        'uploadsOpen' => ($values['uploadsOpen'] ?? 'true') === 'true',
        'memberQuotaMiB' => (int)($values['memberQuotaMiB'] ?? 1024),
        'galleryQuotaMiB' => (int)($values['galleryQuotaMiB'] ?? 2048),
        'analyticsId' => preg_match('/^G-[A-Z0-9]{6,20}$/D', $values['analyticsId'] ?? '') ? $values['analyticsId'] : '',
        'analyticsEnabled' => ($values['analyticsEnabled'] ?? 'false') === 'true',
        'discordInvite' => valid_discord_invite($discordInvite) ? $discordInvite : '',
    ];
}
function apply_categories(array $data): array
{
    $map = [];
    foreach ($data['categories'] as $i => $c) {
        $map[$c['id']] = array_replace(['description' => '', 'sort_order' => $i, 'deleted' => 0], $c);
    }
    foreach (query('SELECT * FROM category_overrides')->fetchAll() as $c) {
        $map[$c['id']] = $c;
    }
    $data['categories'] = array_values(array_filter($map, fn ($c) => !$c['deleted']));
    usort($data['categories'], fn ($a, $b) => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['name'], $b['name']));

    return $data;
}
function managed_accounts(): array
{
    $accounts = [];
    $uploads = query('SELECT user_id,COUNT(*) FROM uploads GROUP BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $archive = query('SELECT identity,COUNT(*) FROM imported_artwork GROUP BY identity')->fetchAll(PDO::FETCH_KEY_PAIR);
    $linked = query('SELECT local_user_id,identity FROM imported_accounts WHERE local_user_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (query('SELECT id,username,role,disabled,auto_approve,created,last_login,last_seen,login_count FROM users')->fetchAll() as $u) {
        $u['key'] = 'local:' . $u['id'];
        $u['source'] = isset($linked[$u['id']]) ? 'Migrated gallery account' : 'Gallery account';
        $u['uploads'] = (int)($uploads[$u['id']] ?? 0);
        $u['archive'] = (int)($archive[$linked[$u['id']] ?? ''] ?? 0);
        $accounts[] = $u;
    }
    foreach (query('SELECT identity,username,role,disabled,auto_approve,created,last_login,last_seen,login_count FROM imported_accounts WHERE local_user_id IS NULL')->fetchAll() as $u) {
        $u['id'] = null;
        $u['uploads'] = 0;
        $u['archive'] = (int)($archive[$u['identity']] ?? 0);
        $u['key'] = 'imported:' . $u['identity'];
        $u['source'] = 'Imported · not signed in yet';
        $accounts[] = $u;
    }

    return $accounts;
}
function member_target(string $key): ?array
{
    if (preg_match('/^local:([1-9][0-9]*)$/D', $key, $m)) {
        $u = query('SELECT id,role,disabled,auto_approve FROM users WHERE id=?', [(int)$m[1]])->fetch();
        if (!$u) {
            return null;
        }
        $u['identity'] = query('SELECT identity FROM imported_accounts WHERE local_user_id=?', [$u['id']])->fetchColumn() ?: 'php-local:' . $u['id'];
        $u['table'] = 'users';
        $u['where'] = 'id';
        $u['value'] = $u['id'];

        return $u;
    }
    if (preg_match('/^imported:(next-(?:local|legacy):[1-9][0-9]*)$/D', $key, $m)) {
        $u = query('SELECT * FROM imported_accounts WHERE identity=?', [$m[1]])->fetch();
        if (!$u) {
            return null;
        }
        if ($u['local_user_id']) {
            return member_target('local:' . $u['local_user_id']);
        }
        $u['id'] = null;
        $u['table'] = 'imported_accounts';
        $u['where'] = 'identity';
        $u['value'] = $u['identity'];

        return $u;
    }

    return null;
}
function set_member_policy(array $user, string $key, string $action): void
{
    with_write(function () use ($user, $key, $action) {
        $target = member_target($key);
        if (!$target) {
            throw new DomainException('Member not found.');
        }
        if ($action !== 'trust' && $target['id'] === (int)$user['id']) {
            throw new DomainException('You cannot change your own administrator access.');
        }
        $column = match($action) {
            'trust' => 'auto_approve','access' => 'disabled','role' => 'role',default => throw new DomainException('Invalid member action.')
        };
        $value = $action === 'role' ? ($target['role'] === 'admin' ? 'member' : 'admin') : 1 - (int)$target[$column];
        query('UPDATE ' . $target['table'] . ' SET ' . $column . '=? WHERE ' . $target['where'] . '=?', [$value, $target['value']]);
        if ($target['table'] === 'users') {
            query('UPDATE imported_accounts SET ' . $column . '=? WHERE local_user_id=?', [$value, $target['id']]);
        }
        if ($action === 'trust') {
            query('INSERT INTO publishing_overrides(identity,auto_approve) VALUES (?,?) ON CONFLICT(identity) DO UPDATE SET auto_approve=excluded.auto_approve', [$target['identity'], $value]);
        }
        log_activity((int)$user['id'], 'Member ' . $action . ' updated', $key);
    });
}
function admin_action(string $path, array $user, array $all): bool
{
    if (!in_array($path, ['/admin/member', '/admin/settings', '/admin/category', '/admin/comment', '/admin/notifications/send', '/admin/icon', '/admin/discord'], true)) {
        return false;
    }
    require_admin($user);
    if ($path === '/admin/discord') {
        $values = [];
        $submitted = is_array($_POST['webhook'] ?? null) ? $_POST['webhook'] : [];
        $clear = is_array($_POST['clearWebhook'] ?? null) ? $_POST['clearWebhook'] : [];
        foreach ($all['categories'] as $category) {
            $id = (int)$category['id'];
            $url = is_string($submitted[$id] ?? null) ? trim($submitted[$id]) : '';
            if ($url !== '' && !preg_match('~^https://(?:discord\.com|discordapp\.com)/api/webhooks/[0-9]+/[A-Za-z0-9._-]+$~D', $url)) {
                throw new DomainException('Enter a Discord webhook URL, or leave it empty to keep the current destination.');
            }
            if ($url !== '' || in_array((string)$id, $clear, true)) {
                $values['discord_upload:' . $id] = $url;
            }
        }
        with_write(function () use ($values, $user): void {
            foreach ($values as $key => $value) {
                query('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key, $value]);
            }
            log_activity((int)$user['id'], 'Discord destinations updated', 'site');
        });
        $_SESSION['notice'] = 'Discord destinations saved.';
        redirect_to('/admin/settings');
    }
    if ($path === '/admin/icon') {
        $values = isset($_POST['reset']) ? ['galleryIcon' => '', 'iconHash' => ''] : gallery_icon_upload($_FILES['galleryIcon'] ?? []);
        with_write(function () use ($values, $user): void {
            foreach ($values as $key => $value) {
                query('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key, $value]);
            }
            log_activity((int)$user['id'], 'Gallery icon updated', 'site');
        });
        $_SESSION['notice'] = 'Gallery icon saved.';
        redirect_to('/admin/settings');
    }
    if ($path === '/admin/notifications/send') {
        $counts = notification_run_now($all);
        $_SESSION['notice'] = $counts === null
            ? 'A notification worker is already running.'
            : 'Notification batch completed: ' . $counts['sent'] . ' sent, ' . $counts['deferred'] . ' deferred, ' . $counts['failed'] . ' failed.';
        redirect_to('/admin/health');
    }
    if ($path === '/admin/member') {
        set_member_policy($user, param($_POST, 'account'), param($_POST, 'action'));
        redirect_to('/admin/users');
    }
    if ($path === '/admin/settings') {
        if (param($_POST, 'section') === 'analytics') {
            $id = strtoupper(param($_POST, 'analyticsId'));
            if ($id !== '' && !preg_match('/^G-[A-Z0-9]{6,20}$/D', $id)) {
                throw new DomainException('Enter a Google Analytics measurement ID such as G-XXXXXXXXXX.');
            }
            $enabled = isset($_POST['analyticsEnabled']);
            if ($enabled && $id === '') {
                throw new DomainException('Enter a measurement ID before enabling analytics.');
            }
            with_write(function () use ($id, $enabled, $user) {
                foreach (['analyticsId' => $id, 'analyticsEnabled' => $enabled ? 'true' : 'false'] as $key => $value) {
                    query('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key, $value]);
                }
                log_activity((int)$user['id'], 'Analytics settings updated', 'site');
            });
            $_SESSION['notice'] = 'Analytics settings saved.';
            redirect_to('/admin/settings');
        }
        $name = param($_POST, 'siteName');
        $description = param($_POST, 'description');
        $discordInvite = param($_POST, 'discordInvite');
        if (!valid_discord_invite($discordInvite)) {
            throw new DomainException('Use an HTTPS discord.gg or discord.com/invite link, or leave it empty.');
        }
        if (!$name || mb_strlen($name) > 80 || mb_strlen($description) > 1000) {
            throw new DomainException('Use a site name up to 80 characters and description up to 1,000.');
        }
        $values = ['siteName' => $name, 'description' => $description, 'registrationOpen' => isset($_POST['registrationOpen']) ? 'true' : 'false', 'uploadsOpen' => isset($_POST['uploadsOpen']) ? 'true' : 'false'];
        $values['discordInvite'] = $discordInvite;
        foreach (['memberQuotaMiB', 'galleryQuotaMiB'] as $key) {
            $value = filter_var(param($_POST, $key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1048576]]);
            if ($value === false) {
                throw new DomainException('Storage limits must be whole MiB between 1 and 1,048,576.');
            }
            $values[$key] = (string)$value;
        }
        with_write(function () use ($values, $user) {
            foreach ($values as $key => $value) {
                query('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key, $value]);
            } log_activity((int)$user['id'], 'Site settings updated', 'site');
        });
        $_SESSION['notice'] = 'Settings saved.';
        redirect_to('/admin/settings');
    }
    if ($path === '/admin/category') {
        $id = (int)param($_POST, 'id');
        $action = param($_POST, 'action', 'save');
        with_write(function () use ($id, $action, $all, $user) {
            if ($action === 'delete') {
                if (count(array_filter($all['items'], fn ($i) => (int)$i['categoryId'] === $id))) {
                    throw new DomainException('Move all artwork out of this category before deleting it.');
                }
                $category = null;
                foreach ($all['categories'] as $c) {
                    if ((int)$c['id'] === $id) {
                        $category = $c;
                    }
                }
                if (!$category) {
                    throw new DomainException('Category not found.');
                }
                query('INSERT INTO category_overrides(id,name,deleted) VALUES (?,?,1) ON CONFLICT(id) DO UPDATE SET deleted=1', [$id, $category['name']]);
            } else {
                $name = param($_POST, 'name');
                $description = param($_POST, 'description');
                $order = filter_var(param($_POST, 'sort_order', '0'), FILTER_VALIDATE_INT);
                if (!$name || mb_strlen($name) > 100 || mb_strlen($description) > 2000 || $order === false || abs($order) > 100000) {
                    throw new DomainException('Enter a valid name, description and sort order.');
                }
                $actualId = $id;
                if (!$actualId) {
                    $actualId = max([1000, ...array_map(fn ($c) => (int)$c['id'], $all['categories']), (int)query('SELECT COALESCE(MAX(id),0) FROM category_overrides')->fetchColumn()]) + 1;
                } elseif (!in_array($actualId, array_map(fn ($c) => (int)$c['id'], $all['categories']), true)) {
                    throw new DomainException('Category not found.');
                }
                query('INSERT INTO category_overrides(id,name,description,sort_order,deleted) VALUES (?,?,?,?,0) ON CONFLICT(id) DO UPDATE SET name=excluded.name,description=excluded.description,sort_order=excluded.sort_order,deleted=0', [$actualId, $name, $description, $order]);
            }
            log_activity((int)$user['id'], 'Category ' . $action, (string)$id);
        });
        redirect_to('/admin/categories');
    }
    if ($path === '/admin/comment') {
        $id = (int)param($_POST, 'id');
        $action = param($_POST, 'action');
        if (!in_array($action, ['approve', 'hold', 'delete'], true)) {
            throw new DomainException('Invalid review action.');
        }
        with_write(function () use ($action, $id, $all) {
            query('UPDATE comments SET ' . match($action) {
                'approve' => 'moderation_reason=NULL','hold' => "moderation_reason='Held by administrator'",'delete' => 'deleted=1'
            } . ' WHERE id=?', [$id]);
            if ($action === 'approve') {
                notification_enqueue((int)$id, $all);
            }
        });
        log_activity((int)$user['id'], 'Comment ' . $action, (string)$id);
        redirect_to('/admin/comments');
    }

    return false;
}
function admin_page(array $records, int $size = 25): array
{
    $pages = max(1, (int)ceil(count($records) / $size));
    $page = max(1, min($pages, (int)param($_GET, 'page', '1')));

    return [array_slice(array_values($records), ($page - 1) * $size, $size), $page, $pages, count($records)];
}
function admin_pagination(string $path, int $page, int $pages, string $label = 'Admin pages'): void
{
    if ($pages < 2) {
        return;
    }
    echo '<nav class="page-links" aria-label="' . e($label) . '">';
    foreach (['← Previous' => $page - 1, 'Next →' => $page + 1] as $label => $target) {
        if ($target >= 1 && $target <= $pages) {
            $params = $_GET;
            $params['page'] = $target;
            echo '<a href="' . e($path . '?' . http_build_query($params)) . '">' . e($label) . '</a>';
        }
    }
    echo '<span>Page ' . $page . ' of ' . $pages . '</span></nav>';
}
