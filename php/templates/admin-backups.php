<?php $backupReport = backup_health(); ?>
<?php if (!$backupReport['configured']): ?>
<section class="prototype-panel"><h2>Backups: not configured</h2><p>Set up a backup job and publish its status to see results here.</p></section>
<?php else: ?>
<section class="prototype-panel"><h2>Independent backups</h2><p><?= $backupReport['attention'] ? 'Some backup checks need attention.' : 'The latest scheduled backup checks are passing.' ?> A passing check describes its most recent run; it does not guarantee recovery of every later change.</p><p class="help">Status published <?php render_time($backupReport['checked']); ?><?= $backupReport['stale'] ? ' · Status is stale; check the backup runner.' : '' ?></p><div class="backup-job-grid">
<?php foreach ($backupReport['jobs'] as $job): ?><article class="backup-job"><div class="backup-job-heading"><h3><?= e($job['label']) ?></h3><span class="backup-state backup-state-<?= e($job['status']) ?>"><?= e(ucfirst($job['status'])) ?></span></div><p class="help"><?= e($job['cadence']) ?></p><dl><dt>Last completed successfully</dt><dd><?php render_time($job['lastSuccess']); ?></dd><dt>Latest attempt</dt><dd><?php render_time($job['lastRun']); ?></dd><dt>Next scheduled run</dt><dd><?php render_time($job['nextRun']); ?></dd></dl><details class="backup-log"><summary>View latest log</summary><pre><?= e($job['log']) ?></pre></details></article><?php endforeach; ?>
</div><p class="help">Check that your backups include the database, artwork, uploads, attachments and configuration. Keep the recovery password separately from the backups.</p></section>
<?php endif; ?>
<?php $backupFolder = backup_directory(); ?>
<?php if ($backupFolder !== null): ?>
<?php $leftovers = backup_leftovers($backupFolder); ?>
<section class="prototype-panel" id="backup-leftovers"><h2>Leftover backup files</h2>
<?php if (!$leftovers): ?>
<p>No staging folders or unfinished archives from failed backup runs.</p>
<?php else: ?>
<p>Failed backup runs left <?= number_format(count($leftovers)) ?> staging <?= count($leftovers) === 1 ? 'item' : 'items' ?> behind. Removing them frees about <strong><?= number_format(array_sum(array_column($leftovers, 'reclaimable')) / 1048576, 1) ?> MiB</strong>. Completed backup archives and gallery files are never removed.</p>
<ul class="backup-leftovers"><?php foreach ($leftovers as $leftover): ?><li><code><?= e($leftover['name']) ?></code> · <?php render_time($leftover['modified']); ?> · <?= number_format($leftover['files']) ?> files · <?= number_format($leftover['reclaimable'] / 1048576, 1) ?> MiB</li><?php endforeach; ?></ul>
<form method="post" action="/admin/backups/cleanup"><?= csrf_field() ?><button type="submit">Remove leftover backup files</button></form>
<p class="help">Staged images are links to the live gallery files, so only the database copies and unfinished archives use extra space. Removal is skipped while a backup is running.</p>
<?php endif; ?>
<p class="help">Folder: <?= e($backupFolder) ?></p></section>
<?php $localArchives = backup_archives($backupFolder);
$removableArchives = array_filter($localArchives, fn (array $archive): bool => $archive['removable']); ?>
<section class="prototype-panel" id="backup-archives"><h2>Local backup archives</h2>
<?php if (!$localArchives): ?>
<p>No encrypted archives are stored on this server. Backups are kept on Google Drive.</p>
<?php else: ?>
<p><?= number_format(count($localArchives)) ?> encrypted <?= count($localArchives) === 1 ? 'archive uses' : 'archives use' ?> <?= number_format(array_sum(array_column($localArchives, 'bytes')) / 1073741824, 2) ?> GiB here. Archives Google Drive has confirmed can be removed from this server; the scheduled backup also does this automatically after each upload.</p>
<ul class="backup-leftovers"><?php foreach ($localArchives as $archive): ?><li><code><?= e($archive['name']) ?></code><?= $archive['full'] ? ' (full)' : '' ?> · <?= number_format($archive['bytes'] / 1048576, 1) ?> MiB · <?= e($archive['status']) ?></li><?php endforeach; ?></ul>
<?php if ($removableArchives): ?>
<form method="post" action="/admin/backups/archives"><?= csrf_field() ?><button type="submit">Remove <?= count($removableArchives) ?> <?= count($removableArchives) === 1 ? 'archive' : 'archives' ?> already on Google Drive (<?= number_format(array_sum(array_column($removableArchives, 'bytes')) / 1073741824, 2) ?> GiB)</button></form>
<?php endif; ?>
<p class="help">Full backups stay here until a downloaded recovery check passes for them. Recovery uses the copies on Google Drive.</p>
<?php endif; ?>
</section>
<?php endif; ?>
