<?php $space = storage_report();
$pageSize = (int)query('PRAGMA page_size')->fetchColumn();
$freePages = (int)query('PRAGMA freelist_count')->fetchColumn(); ?>
<section class="prototype-panel" id="storage"><h2>Storage breakdown</h2>
<p>Gallery database right now: <strong><?= number_format(storage_database_bytes() / 1048576, 2) ?> MiB</strong>, including any SQLite journal files.</p>
<details><summary>Database details</summary><dl class="health-runtime"><dt>SQLite file</dt><dd><?= e(private_directory() . '/php-gallery.sqlite') ?></dd><dt>Page size</dt><dd><?= number_format($pageSize) ?> bytes</dd><dt>Allocated pages</dt><dd><?= number_format((int)query('PRAGMA page_count')->fetchColumn()) ?></dd><dt>Reusable space inside database</dt><dd><?= number_format($freePages * $pageSize / 1048576, 2) ?> MiB</dd></dl><p class="help">Reusable pages can hold new records without growing the file. External MySQL databases are not part of this SQLite figure.</p></details>
<?php if (!$space): ?><p>Run a storage scan to include the archive, backups, sessions, code and other accessible hosting files.</p>
<?php else: ?>
<p><strong><?= number_format(array_sum(array_column($space['buckets'], 'bytes')) / 1073741824, 2) ?> GiB</strong> of unique file contents measured <?= $space['complete'] ? '' : 'so far' ?>. Updated <?php render_time($space['checked']); ?><?= $space['complete'] ? '' : ' · Scan is incomplete; continue below.' ?></p>
<div class="table-scroll"><table class="member-table"><thead><tr><th>Location</th><th>Unique file bytes</th><th>File sizes including links</th><th>Files</th></tr></thead><tbody>
<?php foreach ($space['buckets'] as $bucket): ?><tr><td><?= e($bucket['bucket']) ?></td><td><?= number_format($bucket['bytes'] / 1048576, 2) ?> MiB</td><td><?= number_format($bucket['logical'] / 1048576, 2) ?> MiB</td><td><?= number_format($bucket['files']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<details><summary>Largest files</summary><ul><?php foreach ($space['largest'] as $large): ?><li><?= e($large['path']) ?> · <?= number_format($large['bytes'] / 1048576, 2) ?> MiB</li><?php endforeach; ?></ul></details>
<details><summary>Scan coverage and counting</summary><ul><?php foreach ($space['roots'] as $root): ?><li><?= e($root) ?></li><?php endforeach; ?></ul><p><?= number_format($space['skipped']) ?> symbolic links or inaccessible entries skipped. Hard-linked files count once in the unique total, attributed to the first location scanned. Directory overhead, filesystem allocation, external MySQL databases, mail stored elsewhere and other hosting packages are excluded. Hosting quota figures can therefore differ.</p></details>
<?php endif; ?>
<form method="post" action="/admin/storage/scan"><?= csrf_field() ?><button><?= $space && !$space['complete'] ? 'Continue storage scan' : 'Refresh storage scan' ?></button></form>
<?php if ($space && !$space['complete']): ?><form method="post" action="/admin/storage/scan"><?= csrf_field() ?><input type="hidden" name="restart" value="1"><button class="quiet-button">Restart scan</button></form><?php endif; ?>
<p class="help">Each request scans for a few seconds and saves its progress. These cached figures do not change upload quotas.</p>
</section>
