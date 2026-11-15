<?php $update = update_state();
$candidate = $update['candidate'] ?? null;
$installed = $update['installed'] ?? null; ?>
<section class="prototype-panel"><h2>Gallery updates</h2>
<p>Install published ManaGallery releases from GitHub. Your database, artwork and settings stay in place. The current code is saved before replacement, and a failed code switch rolls back automatically.</p>
<p>Installed release: <strong><?= e($installed['tag'] ?? 'Not recorded yet') ?></strong></p>
<form method="post" action="/admin/updates/check"><?= csrf_field() ?><button>Check for updates</button></form>
<?php if ($candidate): ?>
<h3><?= e($candidate['tag']) ?></h3><p>Checked <?php render_time($candidate['checked']); ?> · <?= number_format($candidate['size'] / 1048576, 1) ?> MiB</p>
<p><a href="https://github.com/ManaMarkTea/ManaGallery/releases" target="_blank" rel="noopener noreferrer">Read release notes ↗</a></p>
<?php if (($installed['digest'] ?? '') === $candidate['digest']): ?><p>This release is installed.</p>
<?php else: ?><form class="prototype-form" method="post" action="/admin/updates/install"><?= csrf_field() ?><input type="hidden" name="digest" value="<?= e($candidate['digest']) ?>"><label>Confirm your current password<input type="password" name="password" autocomplete="current-password" required maxlength="1024"></label><p class="help">The gallery may pause briefly while files are replaced. Keep this page open until the update completes.</p><button>Deploy <?= e($candidate['tag']) ?></button></form><?php endif; ?>
<?php endif; ?>
</section>
