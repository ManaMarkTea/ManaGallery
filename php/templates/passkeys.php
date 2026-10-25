<?php
$keys = query('SELECT id,name,created,last_used FROM passkeys WHERE user_id=? ORDER BY created,id', [$user['id']])->fetchAll();
?>
<section class="prototype-panel" id="passkeys"><h2>Passkeys</h2>
<p>Sign in with your device’s fingerprint, face recognition, PIN or security key. Your password still works.</p>
<?php foreach ($keys as $key): ?>
<details><summary><?= e($key['name']) ?> · Added <?= e(gmdate('Y-m-d', (int)$key['created'])) ?><?= $key['last_used'] ? ' · Last used ' . e(gmdate('Y-m-d', (int)$key['last_used'])) : '' ?></summary>
<form data-passkey="delete" class="prototype-form" hidden><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$key['id'] ?>"><label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label><button>Remove passkey</button><p class="help" role="status" data-passkey-status></p></form></details>
<?php endforeach; ?>
<?php if (passkey_origin() !== null && !account_needs_verification($user)): ?>
<form data-passkey="register" class="prototype-form" hidden><?= csrf_field() ?><label>Passkey name<input name="name" placeholder="My phone or security key" maxlength="80" required></label><label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label><button>Add passkey</button><p class="help" role="status" data-passkey-status></p></form>
<p class="help" data-passkey-unsupported>Passkeys need a supported browser and a secure connection.</p>
<?php elseif (account_needs_verification($user)): ?><p class="help">Confirm your email before adding a passkey.</p>
<?php else: ?><p class="help">Passkeys are not configured for this address.</p><?php endif; ?>
</section>
