<?php
// Complete encrypted recovery point; no public endpoint or embedded password.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
$app=realpath($argv[1]??'');$base=realpath($argv[2]??'');$key=realpath($argv[3]??'');
if(!$app || !$base || !$key || !is_file($app.'/storage/php-gallery.sqlite') || str_contains($base,'/public_html') || str_starts_with($base,$app.'/'))throw new RuntimeException('Usage: snapshot-midphase.php PRIVATE_APP PRIVATE_BACKUP_DIR PASSWORD_FILE');
$password=rtrim(file_get_contents($key),"\r\n");if(strlen($password)<32)throw new RuntimeException('A strong independent recovery password is required');
if(!ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256,true))throw new RuntimeException('AES-256 encrypted ZIP support is required');
$job=fopen($base.'/snapshot.lock','c');if(!$job || !flock($job,LOCK_EX|LOCK_NB))throw new RuntimeException('A snapshot is already running');
$name='bryce5-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3));$stage=$base.'/'.$name.'.stage';mkdir($stage,0700);
$files=[];
$add=function(string $source,string $relative)use($stage,&$files):void {
    if(!is_file($source) || is_link($source))throw new RuntimeException('Unsafe snapshot source: '.$relative);
    $target=$stage.'/'.$relative;if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
    if(!link($source,$target))throw new RuntimeException('Cannot freeze snapshot file: '.$relative);
    $files[$relative]=$target;
};
$catalogueLock=fopen($app.'/storage/catalogue-import.lock','c');if(!$catalogueLock || !flock($catalogueLock,LOCK_SH))throw new RuntimeException('Cannot lock archive imports');
foreach(['storage/archive','storage/attachments'] as $directory){
    $root=$app.'/'.$directory;if(!is_dir($root))continue;
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){
        if($file->isLink())throw new RuntimeException('Symlink in archive source');
        if($file->isFile())$add($file->getPathname(),substr($file->getPathname(),strlen($app)+1));
    }
}
$lock=fopen($app.'/storage/site.lock','c');if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock gallery');
try{
    foreach(['src','templates','public','vendor','storage/uploads'] as $directory){
        $root=$app.'/'.$directory;if(!is_dir($root))continue;
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isLink())throw new RuntimeException('Symlink in snapshot source');
            if($file->isFile())$add($file->getPathname(),substr($file->getPathname(),strlen($app)+1));
        }
    }
    foreach(['config.example.php','config.local.php','composer.json','composer.lock','storage/gallery.json','storage/legacy-routes.json'] as $file)if(is_file($app.'/'.$file))$add($app.'/'.$file,$file);
    $db=new PDO('sqlite:'.$app.'/storage/php-gallery.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=30000');
    $snapshot=$stage.'/storage/php-gallery.sqlite';$db->prepare('VACUUM INTO ?')->execute([$snapshot]);
    $verify=new PDO('sqlite:'.$snapshot);if($verify->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Snapshot database failed integrity check');
    foreach($verify->query('SELECT filename,preview FROM uploads') as $upload)foreach(['filename','preview'] as $field){
        $relative='storage/uploads/'.$upload[$field];if(!isset($files[$relative]))throw new RuntimeException('Snapshot is missing a referenced upload');
    }
    $verify=null;$db=null;$files['storage/php-gallery.sqlite']=$snapshot;
}finally{flock($lock,LOCK_UN);flock($catalogueLock,LOCK_UN);}
echo 'Frozen consistent database and '.count($files)." files; gallery requests resumed.\n";flush();
$manifest=['format'=>'Bryce5 encrypted recovery v1','created'=>gmdate('c'),'files'=>[]];
$zip=new ZipArchive();$output=$base.'/'.$name.'.zip';$temporary=$output.'.partial';
if($zip->open($temporary,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create encrypted snapshot');
$zip->setPassword($password);
foreach($files as $relative=>$file){
    $manifest['files'][$relative]=['bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file)];
    if(!$zip->addFile($file,$relative) || !$zip->setCompressionName($relative,ZipArchive::CM_STORE) || !$zip->setEncryptionName($relative,ZipArchive::EM_AES_256))throw new RuntimeException('Cannot encrypt snapshot file');
}
$zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));$zip->setEncryptionName('manifest.json',ZipArchive::EM_AES_256);
if(!$zip->close())throw new RuntimeException('Cannot finish encrypted archive');
echo "Encrypted archive created; checking every restored file.\n";flush();
if($zip->open($temporary)!==true)throw new RuntimeException('Cannot reopen snapshot');$zip->setPassword($password);
if(json_decode($zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR)!==$manifest)throw new RuntimeException('Restored recovery manifest mismatch');
foreach($manifest['files'] as $relative=>$entry){
    $stream=$zip->getStream($relative);if(!$stream)throw new RuntimeException('Cannot decrypt snapshot entry');
    $hash=hash_init('sha256');$bytes=hash_update_stream($hash,$stream);fclose($stream);
    if($bytes!==$entry['bytes'] || hash_final($hash)!==$entry['sha256'])throw new RuntimeException('Restored snapshot checksum mismatch');
}
$zip->close();if(!rename($temporary,$output))throw new RuntimeException('Cannot publish checked snapshot');chmod($output,0600);
$status=['created'=>gmdate('c'),'archive'=>basename($output),'bytes'=>filesize($output),'sha256'=>hash_file('sha256',$output),'files'=>count($files),'verified'=>true];
file_put_contents($base.'/latest.json',json_encode($status,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
// Only remove the exact staging files we created, after successful verification.
foreach($files as $file)unlink($file);
$directories=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file)if($file->isDir())$directories[]=$file->getPathname();
foreach($directories as $directory)rmdir($directory);rmdir($stage);
echo 'Verified encrypted recovery point: '.$output."\n";
