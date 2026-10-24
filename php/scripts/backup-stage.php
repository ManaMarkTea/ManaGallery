<?php
// Fixed-site, consistent source for the off-server backup pull. CLI only.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
$home=rtrim(getenv('HOME'),'/');$app=$home.'/rendergallery-php/app';$base=$home.'/rendergallery-php/backup-export';
if(!is_dir($base))mkdir($base,0700);
if(is_link($base))throw new RuntimeException('Unsafe export directory');
$exportLock=fopen($base.'/export.lock','c');if(!$exportLock || !flock($exportLock,LOCK_EX))throw new RuntimeException('Cannot lock export');
$stage=$base.'/building-'.bin2hex(random_bytes(8));mkdir($stage,0700);
file_put_contents($stage.'/.bryce-backup-stage',"Bryce5 backup stage v1\n");
$files=[];
$link=function(string $source,string $relative)use($stage,&$files):void{
    if(is_link($source) || !is_file($source))throw new RuntimeException('Unsafe backup source: '.$relative);
    $target=$stage.'/'.$relative;if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
    if(!link($source,$target))throw new RuntimeException('Cannot freeze '.$relative);
    $files[$relative]=$target;
};
$catalogueLock=fopen($app.'/storage/catalogue-import.lock','c');if(!$catalogueLock || !flock($catalogueLock,LOCK_SH))throw new RuntimeException('Cannot lock archive imports');
// Original archive/download bytes are immutable during normal gallery use.
// Link their large inventory without blocking web requests.
foreach(['storage/archive','storage/attachments'] as $directory){
    if(!is_dir($app.'/'.$directory))continue;
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file){
        if($file->isLink())throw new RuntimeException('Symlink in archive backup source');
        if($file->isFile())$link($file->getPathname(),substr($file->getPathname(),strlen($app)+1));
    }
}
$siteLock=fopen($app.'/storage/site.lock','c');if(!$siteLock || !flock($siteLock,LOCK_EX))throw new RuntimeException('Cannot lock gallery');
try{
    foreach(['src','templates','public','vendor','storage/uploads'] as $directory){
        if(!is_dir($app.'/'.$directory))continue;
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isLink())throw new RuntimeException('Symlink in backup source');
            if($file->isFile())$link($file->getPathname(),substr($file->getPathname(),strlen($app)+1));
        }
    }
    foreach(['config.example.php','config.local.php','composer.json','composer.lock','storage/gallery.json','storage/legacy-routes.json'] as $relative)if(is_file($app.'/'.$relative))$link($app.'/'.$relative,$relative);
    foreach(['index.php','.htaccess','.user.ini'] as $name)$link($home.'/public_html_sub/rendergallery-php/'.$name,'deployment/'.$name);
    $db=new PDO('sqlite:'.$app.'/storage/php-gallery.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=30000');
    $snapshot=$stage.'/storage/php-gallery.sqlite';$db->prepare('VACUUM INTO ?')->execute([$snapshot]);$db=null;
    $verify=new PDO('sqlite:'.$snapshot);if($verify->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Database integrity failed');
    foreach($verify->query('SELECT filename,preview FROM uploads') as $upload)foreach(['filename','preview'] as $field){
        if(!isset($files['storage/uploads/'.$upload[$field]]))throw new RuntimeException('Missing referenced upload');
    }
    $verify=null;$files['storage/php-gallery.sqlite']=$snapshot;
}finally{flock($siteLock,LOCK_UN);flock($catalogueLock,LOCK_UN);}
echo 'Frozen '.count($files)." files and a consistent database; gallery requests resumed.\n";flush();
$manifest=['version'=>1,'created'=>gmdate('c'),'files'=>[]];
foreach($files as $relative=>$file)$manifest['files'][$relative]=['bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file)];
file_put_contents($stage.'/manifest.json',json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
// Replace only tool-owned stages, never application or original-gallery paths.
$current=$base.'/current';$old=$base.'/previous';
$remove=function(string $directory)use($base):void{
    if(dirname($directory)!==$base || !in_array(basename($directory),['previous'],true) || is_link($directory) || !is_file($directory.'/.bryce-backup-stage') || file_get_contents($directory.'/.bryce-backup-stage')!=="Bryce5 backup stage v1\n")throw new RuntimeException('Refusing unknown staging directory');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){
        if($file->isLink())throw new RuntimeException('Symlink in old staging');
        if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());
    }
    rmdir($directory);
};
if(is_dir($old))$remove($old);
if(file_exists($current)){
    if(is_link($current) || !is_file($current.'/.bryce-backup-stage') || file_get_contents($current.'/.bryce-backup-stage')!=="Bryce5 backup stage v1\n")throw new RuntimeException('Unknown current staging');
    if(!rename($current,$old))throw new RuntimeException('Cannot retain previous stage');
}
if(!rename($stage,$current)){if(is_dir($old))rename($old,$current);throw new RuntimeException('Cannot publish consistent export');}
if(is_dir($old))$remove($old);
echo 'Published verified export: '.count($files)." files.\n";
