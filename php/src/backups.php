<?php
declare(strict_types=1);
function backup_health(?array $raw=null,?int $now=null): array {
    $now??=time();
    if($raw===null){
        $file=private_directory().'/backup-status.json';
        if(is_file($file))$raw=json_decode(file_get_contents($file),true);
    }
    if(!is_array($raw) || ($raw['version']??null)!==1 || !is_int($raw['checked']??null) || !is_array($raw['jobs']??null))return ['configured'=>false,'jobs'=>[],'attention'=>true];
    $jobs=[];$attention=false;$stale=$raw['checked']<$now-36*3600 || $raw['checked']>$now+300;
    foreach(['backup'=>['Encrypted VPS backup','Daily',36*3600],'cloud'=>['Google Drive copy','Daily',36*3600],'restore'=>['Recovery checks','Monthly',40*86400],'maintenance'=>['Retention & integrity','Weekly',9*86400]] as $kind=>[$label,$cadence,$limit]){
        $entry=is_array($raw['jobs'][$kind]??null) ? $raw['jobs'][$kind] : [];
        $status=in_array($entry['status']??'', ['success','failed','pending','running'],true) ? $entry['status'] : 'pending';
        $lastSuccess=is_int($entry['lastSuccess']??null) ? $entry['lastSuccess'] : null;
        $lastRun=is_int($entry['lastRun']??null) ? $entry['lastRun'] : null;
        if($stale || ($status==='success' && (!$lastSuccess || $lastSuccess<$now-$limit)) || ($status==='running' && (!$lastRun || $lastRun<$now-3*3600)))$status='stale';
        if(!in_array($status,['success','running'],true))$attention=true;
        $jobs[]=['label'=>$label,'cadence'=>$cadence,'status'=>$status,'lastSuccess'=>$lastSuccess,'lastRun'=>$lastRun,'nextRun'=>is_int($entry['nextRun']??null) ? $entry['nextRun'] : null,'log'=>is_string($entry['log']??null) ? mb_substr($entry['log'],0,18000) : 'No run log yet.'];
    }
    return ['configured'=>true,'checked'=>$raw['checked'],'jobs'=>$jobs,'attention'=>$attention,'stale'=>$stale];
}
