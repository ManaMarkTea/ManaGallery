<?php $categoryWebhookConfigured = notification_category_webhook($discordCategoryId) !== ''; ?>
<label>Discord artwork webhook<input type="password" name="discordWebhook" autocomplete="new-password" placeholder="<?= $categoryWebhookConfigured ? 'Leave empty to keep this destination' : 'https://discord.com/api/webhooks/...' ?>"></label>
<p class="help">Category channel: <?= $categoryWebhookConfigured ? 'connected' : 'not connected' ?>. In Discord, create a webhook under Edit Channel > Integrations > Webhooks. Saved URLs are never displayed here.</p>
<?php if ($categoryWebhookConfigured): ?>
    <label class="checkbox-field"><input type="checkbox" name="clearDiscordWebhook" value="1">Remove category webhook</label>
<?php endif; ?>
<p class="help">Without a category webhook, <?= notification_artwork_fallback() && notification_comment_webhook() !== '' ? 'new artwork goes to the comment channel' : 'artwork announcements are skipped' ?>. Change the default under <a href="/admin/settings">Discord notification settings</a>.</p>
