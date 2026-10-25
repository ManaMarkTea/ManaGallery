<?php $settings = site_settings(); ?>
<section class="prototype-panel"><h2>Identity & publishing</h2><p>Settings take effect immediately. Lowering a quota does not remove existing files.</p><form class="prototype-form" action="/admin/settings" method="post"><?= csrf_field() ?><label>Site name<input name="siteName" value="<?= e($settings['siteName']) ?>" maxlength="80" required></label><label>Site description<textarea name="description" aria-label="Site description" rows="3" maxlength="1000"><?= e($settings['description']) ?></textarea></label><label>Discord invite link<input type="url" name="discordInvite" value="<?= e($settings['discordInvite']) ?>" maxlength="150" placeholder="https://discord.gg/..."></label><p class="help">Leave empty to hide Discord links.</p><label class="checkbox-field"><input type="checkbox" name="registrationOpen" <?= $settings['registrationOpen'] ? 'checked' : '' ?>>Allow new registrations</label><label class="checkbox-field"><input type="checkbox" name="signupEmailVerification" <?= $settings['signupEmailVerification'] ? 'checked' : '' ?>>Require email confirmation for new accounts</label><p class="help">Requires configured email delivery. Existing accounts keep their access.</p><label class="checkbox-field"><input type="checkbox" name="uploadsOpen" <?= $settings['uploadsOpen'] ? 'checked' : '' ?>>Allow uploads</label><div class="form-pair"><label>Member storage limit (MiB)<input name="memberQuotaMiB" type="number" min="1" max="1048576" value="<?= $settings['memberQuotaMiB'] ?>" required></label><label>Gallery storage limit (MiB)<input name="galleryQuotaMiB" type="number" min="1" max="1048576" value="<?= $settings['galleryQuotaMiB'] ?>" required></label></div><p class="help">Imported archive images are excluded from these upload quotas. A separate 2 GiB free-disk reserve always applies.</p><button>Save settings</button></form></section><section class="prototype-panel"><h2>Advertising</h2><p>AdSense is not enabled yet. No advertising scripts are loaded.</p></section>

<section class="prototype-panel"><h2>Visitor analytics</h2><p>Google Analytics works independently of advertising. Reports are available in <a href="https://analytics.google.com/" target="_blank" rel="noopener noreferrer">Google Analytics ↗</a>.</p><form class="prototype-form" action="/admin/settings" method="post"><?= csrf_field() ?><input type="hidden" name="section" value="analytics"><label>Google Analytics measurement ID<input name="analyticsId" value="<?= e($settings['analyticsId']) ?>" placeholder="G-XXXXXXXXXX" pattern="G-[A-Za-z0-9]{6,20}" maxlength="22"></label><label class="checkbox-field"><input type="checkbox" name="analyticsEnabled" <?= $settings['analyticsEnabled'] ? 'checked' : '' ?>>Enable visitor analytics</label><p class="help">Google’s tag loads only after a visitor allows analytics. Account pages, admin pages and administrators are excluded. Advertising features remain disabled.</p><button>Save analytics settings</button></form></section>

<section class="prototype-panel">
    <h2>Gallery icon</h2>
    <p>Used in the header, admin sidebar and browser tab.</p>
    <form class="prototype-form" action="/admin/icon" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label>Icon image<input type="file" name="galleryIcon" accept="image/png,image/jpeg,image/webp" required></label>
        <p class="help">PNG, JPEG or WebP, up to 2 MiB and 2048 pixels per side. Square images work best.</p>
        <button>Save icon</button>
    </form>
    <?php if ($settings['iconHash']): ?>
        <form action="/admin/icon" method="post"><?= csrf_field() ?><button name="reset" value="1">Use default icon</button></form>
    <?php endif; ?>
</section>
<section class="prototype-panel">
    <h2>Discord notifications</h2>
    <?php $commentConfigured = notification_comment_webhook() !== ''; ?>
    <p>Comment channel: <?= $commentConfigured ? 'connected' : 'not connected' ?>.</p>
    <form class="prototype-form" action="/admin/discord" method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label>Comment channel webhook<input type="password" name="commentWebhook" autocomplete="new-password" placeholder="<?= $commentConfigured ? 'Leave empty to keep this destination' : 'https://discord.com/api/webhooks/...' ?>"></label>
        <?php if ($commentConfigured): ?>
            <label class="checkbox-field"><input type="checkbox" name="clearCommentWebhook" value="1">Disconnect comment channel</label>
        <?php endif; ?>
        <label class="checkbox-field"><input type="checkbox" name="artworkFallback" <?= notification_artwork_fallback() ? 'checked' : '' ?>>Send artwork announcements to the comment channel when a category has no webhook</label>
        <p class="help">When this is off, categories without a webhook skip artwork announcements. Configure category destinations under <a href="/admin/categories">Categories</a>.</p>
        <p class="help">Webhook URLs are credentials. Saved URLs stay private and are never displayed here.</p>
        <button>Save Discord settings</button>
    </form>
</section>
