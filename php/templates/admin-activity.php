<?php
$records = query('SELECT a.*,u.username FROM activity a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 2000')->fetchAll();
if (param($_GET, 'q')) {
    $records = array_filter($records, fn ($a) => mb_stripos(($a['username'] ?? '') . ' ' . $a['action'] . ' ' . $a['target'], param($_GET, 'q')) !== false);
}
[$records,$page,$pages,$total] = admin_page($records, 50);
?><form class="admin-filter-form" action="/admin/activity"><input type="search" name="q" aria-label="Search activity" value="<?= e(param($_GET, 'q')) ?>" placeholder="Search actor, action or target"><button>Search activity</button></form><section class="prototype-panel activity-list"><?php foreach ($records as $event): ?><p><strong><?= e($event['username'] ?? 'Local administrator') ?></strong> · <?= e($event['action']) ?><span class="help">#<?= e($event['target']) ?> · <?php render_time((int)$event['created']); ?></span></p><?php endforeach; ?></section><?php admin_pagination('/admin/activity', $page, $pages); ?>
