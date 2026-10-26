<?php $categoryWebhookConfigured = notification_category_webhook($discordCategoryId) !== ''; ?>
<label>Discord artwork webhook<input type="password" name="discordWebhook" autocomplete="new-password" placeholder="<?= $categoryWebhookConfigured ? 'Leave empty to keep this destination' : 'https://discord.com/api/webhooks/...' ?>"></label>
<p class="help">Category channel: <?= $categoryWebhookConfigured ? 'connected' : 'not connected' ?>. Saved URLs stay private.</p>
<?php if ($categoryWebhookConfigured): ?>
    <label class="checkbox-field"><input type="checkbox" name="clearDiscordWebhook" value="1">Remove category webhook</label>
<?php endif; ?>
<p class="help">Without a webhook: <?= notification_artwork_fallback() && notification_comment_webhook() !== '' ? 'use the comment channel' : 'skip artwork announcements' ?>.</p>
