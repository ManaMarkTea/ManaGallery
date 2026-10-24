<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/backups.php';
function verify_backup_state(bool $ok):void{if(!$ok)throw new RuntimeException('Backup health status test failed');}
$now=1800000000;$raw=['version'=>1,'checked'=>$now,'jobs'=>[]];
foreach(['backup','cloud','restore','maintenance'] as $kind)$raw['jobs'][$kind]=['status'=>'success','lastRun'=>$now-60,'lastSuccess'=>$now-30,'nextRun'=>$now+100,'log'=>'fictional log'];
$result=backup_health($raw,$now);verify_backup_state($result['configured'] && !$result['attention']);
$raw['jobs']['cloud']['status']='failed';verify_backup_state(backup_health($raw,$now)['attention']);
$raw['jobs']['cloud']['status']='success';$raw['jobs']['cloud']['lastSuccess']=$now-40*3600;verify_backup_state(backup_health($raw,$now)['jobs'][1]['status']==='stale');
$raw['checked']=$now-40*3600;verify_backup_state(backup_health($raw,$now)['stale']);
verify_backup_state(!backup_health(['version'=>99],$now)['configured']);
echo "Passed backup health, failure and stale-status checks.\n";
