<?php $disk=disk_free_space(private_directory()); $settings=site_settings(); $imports=(int)query('SELECT COUNT(*) FROM imported_accounts')->fetchColumn(); $held=(int)query('SELECT COUNT(*) FROM comments WHERE deleted=0 AND moderation_reason IS NOT NULL')->fetchColumn(); ?>
<div class="admin-metrics"><div><strong><?= $disk===false ? 'Unavailable' : number_format($disk/1073741824,1).' GiB' ?></strong><span>disk available · 2 GiB reserved</span></div><div><strong><?= number_format(upload_usage()/1048576,1) ?> MiB</strong><span>uploaded / <?= number_format($settings['galleryQuotaMiB']) ?> MiB allowance</span></div><div><strong><?= number_format($held) ?></strong><span>comments held for review</span></div></div>
<section class="prototype-panel"><h2>Runtime &amp; data</h2>
<?php $runtimeDetails=[
    'PHP version'=>PHP_VERSION,
    'PHP interface'=>PHP_SAPI,
    'Web server'=>$_SERVER['SERVER_SOFTWARE']??'Unavailable',
    'Operating system'=>PHP_OS_FAMILY,
    'SQLite version'=>(string)query('SELECT sqlite_version()')->fetchColumn(),
    'Memory limit'=>(string)ini_get('memory_limit'),
    'Upload limit'=>(string)ini_get('upload_max_filesize'),
    'POST limit'=>(string)ini_get('post_max_size'),
    'Execution time limit'=>ini_get('max_execution_time')==='0' ? 'Unlimited' : ini_get('max_execution_time').' seconds',
    'Server timezone'=>date_default_timezone_get(),
]; ?>
<dl class="health-runtime"><?php foreach($runtimeDetails as $label=>$value): ?><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd><?php endforeach; ?></dl>
<p class="help">Values come from the PHP process serving this page. Terminal PHP settings may differ.</p>
<p><?= number_format($imports) ?> imported identities &middot; <?= number_format((int)query('SELECT COUNT(*) FROM imported_accounts WHERE local_user_id IS NOT NULL')->fetchColumn()) ?> activated accounts</p>
<p>Extensions: <?php foreach(['pdo_sqlite','gd','mbstring','fileinfo','sodium','openssl','curl','zip'] as $extension): ?><span class="status-pill"><?= e($extension) ?>: <?= extension_loaded($extension) ? 'Available' : 'Missing' ?></span> <?php endforeach; ?></p></section>
<section class="prototype-panel"><h2>Community features</h2><p><?= number_format((int)query('SELECT COUNT(*) FROM artwork_viewers WHERE claimed_by IS NULL')->fetchColumn()) ?> unique artwork/viewer pairs · <?= number_format((int)query('SELECT COUNT(*) FROM favorites')->fetchColumn()) ?> saved favorites</p><p><?= number_format(count(array_filter(attachment_catalogue('materials')))) ?> material entries · <?= number_format(count(array_filter(attachment_catalogue('models')))) ?> original model entries · <?= number_format(count(attachment_catalogue('models',true))) ?> GLB entries</p><p class="help">Catalog counts describe configured downloads. Each download verifies the actual file size and SHA-256 before sending bytes.</p></section>
<?php require __DIR__.'/admin-backups.php'; ?>
<?php notification_schema();$notificationCounts=query('SELECT kind,state,COUNT(*) AS total FROM notification_queue GROUP BY kind,state ORDER BY kind,state')->fetchAll();$notificationRun=query("SELECT value FROM settings WHERE key='notifications_last_run'")->fetchColumn(); ?>
<section class="prototype-panel"><h2>Comment notifications</h2><p>Email: <?= !empty(config()['notification_mail_enabled']) ? 'Enabled' : 'Disabled' ?> · Discord: <?= !empty(config()['discord_webhook']) ? 'Configured' : 'Not configured' ?></p>
<p>Last worker run: <?php render_time($notificationRun ? (int)$notificationRun : null); ?></p>
<?php foreach($notificationCounts as $notificationCount): ?><p><?= e(ucfirst($notificationCount['kind'])) ?> · <?= e($notificationCount['state']) ?>: <?= (int)$notificationCount['total'] ?></p><?php endforeach; ?>
<?php foreach(query("SELECT id,kind,last_error FROM notification_queue WHERE last_error IS NOT NULL ORDER BY id DESC LIMIT 5")->fetchAll() as $deliveryError): ?><p class="help">Job <?= (int)$deliveryError['id'] ?> · <?= e($deliveryError['kind']) ?>: <?= e($deliveryError['last_error']) ?></p><?php endforeach; ?>
<p class="help">Email “sent” means accepted by the hosting mail service; it does not prove inbox delivery. Failed jobs need attention. Addresses and webhook credentials stay private.</p></section>
