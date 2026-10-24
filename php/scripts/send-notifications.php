<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
$defaultApp=is_file(__DIR__.'/app/src/notifications.php') ? __DIR__.'/app' : dirname(__DIR__);
$app=realpath($argv[1]??$defaultApp);
if(!$app || !is_file($app.'/src/notifications.php'))throw new RuntimeException('Expected private application directory');
foreach(['gallery','database','uploads','admin','notifications','notification-delivery'] as $module)require $app.'/src/'.$module.'.php';
$lock=fopen(private_directory().'/notifications-worker.lock','c');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){echo "Another notification worker is running.\n";exit;}
$siteLock=fopen(private_directory().'/site.lock','c');if(!$siteLock || !flock($siteLock,LOCK_SH))throw new RuntimeException('Cannot acquire site lock');
notification_schema();
if(($argv[2]??'')==='--status'){
    echo json_encode(query('SELECT kind,state,COUNT(*) AS count FROM notification_queue GROUP BY kind,state')->fetchAll(),JSON_PRETTY_PRINT)."\n";exit;
}
$counts=notification_deliver(all_artworks(apply_categories(catalogue())));
query("INSERT INTO settings(key,value) VALUES ('notifications_last_run',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[(string)time()]);
echo gmdate('c').' '.json_encode($counts)."\n";
