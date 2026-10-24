<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/src/gallery.php';
require dirname(__DIR__).'/src/database.php';
$rows=query('SELECT p.id,p.user_id FROM uploads p JOIN users u ON u.id=p.user_id JOIN imported_accounts i ON i.local_user_id=u.id LEFT JOIN artwork_overrides a ON a.artwork_id=p.id+1000000000 WHERE p.approved=0 AND u.auto_approve=1 AND u.disabled=0 AND COALESCE(a.hidden,0)=0')->fetchAll();
if (!in_array('--apply',$argv,true)) { echo count($rows)." pending uploads from trusted imports; add --apply to repair migration-time approval.\n"; exit; }
with_write(function() use ($rows) { foreach ($rows as $row) { query('UPDATE uploads SET approved=1 WHERE id=?',[$row['id']]); log_activity((int)$row['user_id'],'Approval repaired after trust migration',(string)(1000000000+$row['id'])); } });
echo count($rows)." uploads approved after restoring imported trust. Normal admin trust toggles still affect future uploads only.\n";
