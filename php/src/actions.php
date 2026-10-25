<?php

declare(strict_types=1);

function handle_action(string $path, ?array $user, array $all): void
{
    verify_csrf();
    if ($path === '/register') {
        if (!site_settings()['registrationOpen']) {
            throw new DomainException('Registration is currently paused.');
        }
        take_attempt('register', $_SERVER['REMOTE_ADDR'] ?? 'local', 10, 3600);
        $name = param($_POST, 'username');
        $error = username_error($name, $all);
        if ($error) {
            throw new DomainException($error);
        }
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        validate_password($password);
        $hash = password_hash($password, password_algorithm());
        if (query('SELECT 1 FROM users WHERE username_key=?', [strtolower($name)])->fetchColumn()) {
            throw new DomainException('That username is unavailable.');
        }
        $verifyEmail = site_settings()['signupEmailVerification'];
        $email = $verifyEmail ? account_valid_email(param($_POST, 'email')) : '';
        if ($verifyEmail && empty(config()['notification_mail_enabled'])) {
            throw new DomainException('Email delivery is unavailable. Please try again later.');
        }
        if ($verifyEmail) {
            take_attempt('signup-email', strtolower($email), 3, 3600);
        }
        account_security_schema();
        notification_schema();
        try {
            $id = with_write(function () use ($name, $hash, $verifyEmail, $email): int {
                query('INSERT INTO users(username,username_key,password,created) VALUES (?,?,?,?)', [$name, strtolower($name), $hash, time()]);
                $id = (int)database()->lastInsertId();
                if ($verifyEmail) {
                    account_verification_email($id, $email);
                }

                return $id;
            });
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new DomainException('That username is unavailable.');
            } throw $error;
        }
        log_activity($id, 'Account created', (string)$id);
        sign_in(['id' => $id], false);
        redirect_to('/account');
    }
    if ($path === '/login') {
        $name = param($_POST, 'username');
        $address = $_SERVER['REMOTE_ADDR'] ?? 'local';
        take_attempt('login-ip', $address, 30, 900);
        take_attempt('login-account', strtolower($name), 10, 900);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (strlen($password) > 1024) {
            throw new DomainException('Incorrect username or password.');
        }
        $account = query('SELECT * FROM users WHERE username_key=?', [strtolower($name)])->fetch();
        $dummy = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        $valid = $account ? password_verify($password, $account['password']) : false;
        if (!$account) {
            $account = imported_login($name, $password);
            $valid = $account !== null;
        }
        if (!$account) {
            password_verify($password, $dummy);
        }
        if (!$account || !$valid || $account['disabled']) {
            throw new DomainException('Incorrect username or password.');
        }
        if (password_needs_rehash($account['password'], password_algorithm())) {
            query('UPDATE users SET password=? WHERE id=?', [password_hash($password, password_algorithm()), $account['id']]);
        }
        sign_in($account);
        log_activity((int)$account['id'], 'Signed in', (string)$account['id']);
        redirect_to(login_destination(param($_POST, 'next')));
    }
    $user = require_user($user);
    if ($path === '/account/verify-email') {
        account_resend_verification($user);
    }
    if (!in_array($path, ['/logout', '/account/password'], true)) {
        account_require_verified($user);
    }
    if ($path === '/account/notifications') {
        notification_preferences($user);
        redirect_to('/account');
    }
    if (community_action($path, $user, $all)) {
        return;
    }
    if (comment_action($path, $user, $all)) {
        return;
    }
    if ($path === '/logout') {
        $_SESSION = [];
        session_destroy();
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        redirect_to('/');
    }
    if ($path === '/account/password') {
        $account = query('SELECT password FROM users WHERE id=?', [$user['id']])->fetch();
        take_attempt('password-change', (string)$user['id'], 10, 900);
        if (!password_verify(is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '', $account['password'])) {
            throw new DomainException('The current password is incorrect.');
        }
        $new = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        validate_password($new);
        query('UPDATE users SET password=? WHERE id=?', [password_hash($new, password_algorithm()), $user['id']]);
        // Incrementing an account credential version invalidates other sessions.
        $version = bin2hex(random_bytes(16));
        query("INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", ['credential:' . $user['id'], $version]);
        session_regenerate_id(true);
        $_SESSION['credential'] = $version;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        log_activity((int)$user['id'], 'Password changed', (string)$user['id']);
        $_SESSION['notice'] = 'Password changed; other sessions have been signed out.';
        redirect_to('/account');
    }
    if ($path === '/upload' && !site_settings()['uploadsOpen']) {
        throw new DomainException('Uploads are currently paused.');
    }
    if ($path === '/upload') {
        $id = store_upload($user, $all);
        $updated = all_artworks(apply_categories(catalogue()));
        $art = find_artwork($updated, $id);
        if ($art) {
            with_write(fn () => notification_enqueue_artwork($art));
            notification_schedule_delivery($updated);
        }
        redirect_to('/art/' . $id);
    }
    if (preg_match('~^/art/([1-9][0-9]*)/edit$~D', $path, $matches)) {
        $item = find_artwork($all, (int)$matches[1]);
        if (!$item || !can_edit($user, $item)) {
            http_response_code(404);
            exit('Artwork not found');
        }
        $fields = artwork_fields($all, $_POST);
        with_write(function () use ($item, $fields, $user) {
            $hidden = query('SELECT hidden FROM artwork_overrides WHERE artwork_id=?', [$item['id']])->fetchColumn();
            query('INSERT INTO artwork_overrides(artwork_id,title,description,category_id,software,tags,hidden) VALUES (?,?,?,?,?,?,?) ON CONFLICT(artwork_id) DO UPDATE SET title=excluded.title,description=excluded.description,category_id=excluded.category_id,software=excluded.software,tags=excluded.tags', [$item['id'], ...$fields, (int)(bool)$hidden]);
            log_activity((int)$user['id'], 'Artwork edited', (string)$item['id']);
        });
        redirect_to('/art/' . $item['id']);
    }
    if (preg_match('~^/art/([1-9][0-9]*)/delete$~D', $path, $matches)) {
        $item = find_artwork($all, (int)$matches[1]);
        if (!$item || !isset($item['ownerId']) || !can_edit($user, $item)) {
            http_response_code(404);
            exit('Artwork not found');
        }
        $files = [uploaded_media($item, true), uploaded_media($item, false)];
        with_write(function () use ($item, $user) {
            query('DELETE FROM favorites WHERE artwork_id=?', [$item['id']]);
            query('DELETE FROM artwork_viewers WHERE artwork_id=?', [$item['id']]);
            query('DELETE FROM artwork_overrides WHERE artwork_id=?', [$item['id']]);
            query('DELETE FROM uploads WHERE id=?', [$item['id'] - UPLOAD_BASE]);
            log_activity((int)$user['id'], 'Upload deleted', (string)$item['id']);
        });
        foreach ($files as $file) {
            if ($file && !@unlink($file)) {
                error_log('PHP gallery: orphaned upload requires storage cleanup');
            }
        }
        $_SESSION['notice'] = 'Artwork deleted.';
        redirect_to('/account');
    }
    require_admin($user);
    if (admin_action($path, $user, $all)) {
        return;
    }
    $id = filter_var(param($_POST, 'id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        throw new DomainException('Choose a valid record.');
    }
    if ($path === '/admin/approve') {
        with_write(function () use ($id, $user) {
            query('UPDATE uploads SET approved=1 WHERE id=?', [$id]);
            log_activity((int)$user['id'], 'Upload approved', (string)(UPLOAD_BASE + $id));
        });
        $updated = all_artworks(apply_categories(catalogue()));
        $art = find_artwork($updated, UPLOAD_BASE + $id);
        if ($art) {
            with_write(fn () => notification_enqueue_artwork($art));
            notification_schedule_delivery($updated);
        }
        redirect_to('/admin');
    }
    if ($path === '/admin/trust') {
        set_member_policy($user, 'local:' . $id, 'trust');
        redirect_to('/admin');
    }
    if ($path === '/admin/disable') {
        if ($id === (int)$user['id']) {
            throw new DomainException('You cannot disable your own account.');
        }
        set_member_policy($user, 'local:' . $id, 'access');
        redirect_to('/admin');
    }
    if ($path === '/admin/visibility') {
        $item = find_artwork($all, $id);
        if (!$item) {
            throw new DomainException('Artwork not found.');
        }
        with_write(function () use ($item, $user) {
            query('INSERT INTO artwork_overrides(artwork_id,title,description,category_id,software,tags,hidden) VALUES (?,?,?,?,?,?,?) ON CONFLICT(artwork_id) DO UPDATE SET hidden=1-artwork_overrides.hidden', [$item['id'], $item['title'], $item['description'] ?? '', $item['categoryId'], $item['software'] ?? 'bryce', $item['tags'] ?? '', 1]);
            log_activity((int)$user['id'], 'Artwork visibility toggled', (string)$item['id']);
        });
        redirect_to('/art/' . $item['id']);
    }
}
