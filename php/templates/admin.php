<?php
$settings = site_settings();
$sections = [
    '/admin' => 'Overview',
    '/admin/review' => 'Review uploads',
    '/admin/artworks' => 'Artworks',
    '/admin/users' => 'Members',
    '/admin/comments' => 'Comments',
    '/admin/categories' => 'Categories',
    '/admin/settings' => 'Settings',
    '/admin/activity' => 'Activity',
    '/admin/health' => 'Site health',
];
$overviewPaths = ['/admin', '/admin/review', '/admin/approve', '/admin/trust', '/admin/disable', '/admin/visibility'];
$pageTitle = match ($path) {
    '/admin/users' => 'Community members',
    '/admin/artworks' => 'Artwork library',
    '/admin/categories' => 'Gallery categories',
    '/admin/settings' => 'Gallery settings',
    '/admin/comments' => 'Comment review',
    '/admin/activity' => 'Recent activity',
    '/admin/health' => 'Site health',
    default => 'Administration',
};
?>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a href="/admin" class="admin-logo">
            <span><?php if ($settings['iconHash']): ?><img class="gallery-brand-icon" src="<?= e(gallery_icon_url()) ?>" alt=""><?php else: ?><?= e(mb_substr($settings['siteName'], 0, 1)) ?><?php endif; ?></span>
            <div><?= e($settings['siteName']) ?><small>ADMIN WORKSPACE</small></div>
        </a>
        <div class="admin-nav-label">MANAGE YOUR COMMUNITY</div>
        <nav aria-label="Administration">
            <?php foreach ($sections as $url => $label): ?>
                <a href="<?= $url ?>" <?= $path === $url ? 'class="active" aria-current="page"' : '' ?>>
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-footer">
            <span class="admin-avatar"><?= e(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></span>
            <div><?= e($user['username']) ?><small>Administrator</small></div>
            <a href="/">Gallery ↗</a>
        </div>
    </aside>
    <div class="admin-workspace">
        <header class="admin-topbar">
            <span>Workspace / <?= e($sections[$path] ?? 'Manage') ?></span>
            <a href="/">View gallery ↗</a>
        </header>
        <div class="admin-content">
            <?php if (in_array($path, $overviewPaths, true)): ?>
                <?php require __DIR__ . '/admin-overview.php'; ?>
            <?php else: ?>
                <main class="prototype-admin">
                    <p class="eyebrow">COMMUNITY ADMINISTRATION</p>
                    <h1><?= e($pageTitle) ?></h1>
                    <?php if ($formError): ?>
                        <p class="form-error" role="alert"><?= e($formError) ?></p>
                    <?php endif; ?>
                    <?php if ($notice): ?>
                        <p class="notice" role="status"><?= e($notice) ?></p>
                    <?php endif; ?>
                    <?php
                    $section = match ($path) {
                        '/admin/users', '/admin/member' => 'users',
                        '/admin/artworks' => 'artworks',
                        '/admin/categories', '/admin/category' => 'categories',
                        '/admin/settings' => 'settings',
                        '/admin/comments', '/admin/comment' => 'comments',
                        '/admin/activity' => 'activity',
                        '/admin/health' => 'health',
                        default => 'settings',
                    };
require __DIR__ . '/admin-' . $section . '.php';
?>
                </main>
            <?php endif; ?>
        </div>
    </div>
</div>
