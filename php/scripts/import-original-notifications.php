<?php
// Private, CLI-only import of the original notification opt-in. No emails printed.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
$app=realpath($argv[1]??'');$original=realpath($argv[2]??'');$apply=($argv[3]??'--check')==='--apply';
if(!$app || !$original || !is_file($original.'/config.php'))throw new RuntimeException('Expected application and original-gallery directories');
require $original.'/config.php';
if(!preg_match('/^[A-Za-z0-9_]+$/D',$table_prefix))throw new RuntimeException('Invalid table prefix');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$source=new mysqli($db_host,$db_user,$db_password,$db_name);$source->set_charset('utf8');
require $app.'/src/gallery.php';require $app.'/src/database.php';require $app.'/src/notifications.php';
notification_schema();
$owners=[];
foreach($source->query('SELECT image_id,user_id FROM '.$table_prefix.'images WHERE user_id>0') as $row){
    $identity=query('SELECT identity FROM imported_artwork WHERE artwork_id=?',[(int)$row['image_id']])->fetchColumn();
    if($identity)$owners[(int)$row['user_id']][$identity]=true;
}
$contacts=[];$skipped=0;
foreach($source->query('SELECT user_id,user_email,user_showemail,user_level FROM '.$table_prefix.'users WHERE user_id>0') as $old){
    $identity='next-legacy:'.(int)$old['user_id'];
    if(!query('SELECT 1 FROM imported_accounts WHERE identity=?',[$identity])->fetchColumn()){
        $matches=array_keys($owners[(int)$old['user_id']]??[]);
        if(count($matches)!==1){$skipped++;continue;}$identity=$matches[0];
    }
    $email=trim($old['user_email']);
    if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254 || preg_match('/[\r\n]/',$email)){$skipped++;continue;}
    if(query('SELECT 1 FROM notification_contacts WHERE identity=?',[$identity])->fetchColumn())continue;
    $enabled=(int)$old['user_showemail']===1 && (int)$old['user_level']>=2 ? 1 : 0;
    $contacts[]=[$identity,$email,1,$enabled,$enabled,time()];
}
if($apply)with_write(function() use($contacts){foreach($contacts as $contact)query('INSERT OR IGNORE INTO notification_contacts(identity,email,confirmed,owner_enabled,thread_enabled,updated) VALUES (?,?,?,?,?,?)',$contact);});
echo ($apply?'Imported ':'Would import ').count($contacts).' private email preferences; '.count(array_filter($contacts,fn($c)=>$c[3]===1)).' opted in; '.$skipped." unmatched/invalid skipped. Existing preferences unchanged. No emails queued.\n";
// Import the existing Discord endpoint as private configuration, never source.
$details=file_get_contents($original.'/details.php');
if(preg_match('~https://(?:discord\.com|discordapp\.com)/api/webhooks/[0-9]+/[A-Za-z0-9._-]+~',$details,$matches)){
    $file=$app.'/config.local.php';$local=require $file;
    if(empty($local['discord_webhook'])){
        if($apply){$local['discord_webhook']=$matches[0];$temp=$file.'.notification-next';file_put_contents($temp,"<?php\nreturn ".var_export($local,true).";\n");chmod($temp,0600);rename($temp,$file);}
        echo $apply ? "Original Discord endpoint copied into private configuration.\n" : "Original Discord endpoint found; ready for private import.\n";
    }else echo "Existing private Discord configuration preserved.\n";
}else echo "No original Discord endpoint found.\n";
