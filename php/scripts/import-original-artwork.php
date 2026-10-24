<?php
// Add one approved original-gallery artwork. CLI only; never replace PHP edits.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
$app=realpath($argv[1]??'');$original=realpath($argv[2]??'');$id=filter_var($argv[3]??'',FILTER_VALIDATE_INT);
$apply=($argv[4]??'--check')==='--apply';
if(!$app || !$original || !$id || $id<1 || $id>=1000000000 || !is_file($app.'/storage/gallery.json') || !is_file($app.'/storage/php-gallery.sqlite'))throw new RuntimeException('Usage: php import-original-artwork.php APP_ROOT ORIGINAL_ROOT IMAGE_ID [--check|--apply]');
require $original.'/config.php';
if(!preg_match('/^[A-Za-z0-9_]+$/D',$table_prefix))throw new RuntimeException('Invalid source prefix.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$source=new mysqli($db_host,$db_user,$db_password,$db_name);$source->set_charset('utf8');
$statement=$source->prepare('SELECT i.image_id,i.cat_id,i.user_id,i.image_name,i.image_description,i.image_keywords,i.image_date,i.image_active,i.image_media_file,i.image_thumb_file,i.image_hits,i.image_rating,c.cat_name,u.user_name FROM '.$table_prefix.'images i JOIN '.$table_prefix.'categories c ON c.cat_id=i.cat_id JOIN '.$table_prefix.'users u ON u.user_id=i.user_id WHERE i.image_id=?');
$statement->bind_param('i',$id);$statement->execute();$row=$statement->get_result()->fetch_assoc();
if(!$row || (int)$row['image_active']!==1)throw new RuntimeException('Source artwork is missing or not approved.');
$storage=$app.'/storage';$catalogueFile=$storage.'/gallery.json';
$lock=fopen($storage.'/catalogue-import.lock','c');if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another catalogue import is running.');
$before=file_get_contents($catalogueFile);$catalogue=json_decode($before,true,512,JSON_THROW_ON_ERROR);
foreach($catalogue['items'] as $existing)if((int)$existing['id']===$id){echo "Artwork $id already exists; existing metadata and ownership preserved.\n";exit;}
if(!in_array((int)$row['cat_id'],array_map(fn($c)=>(int)$c['id'],$catalogue['categories']),true))throw new RuntimeException('Import the source category first.');
$db=new PDO('sqlite:'.$storage.'/php-gallery.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
// Use verified previous artwork IDs for this original user, never a name match.
$ownerIds=$source->prepare('SELECT image_id FROM '.$table_prefix.'images WHERE user_id=? AND image_id<>? ORDER BY image_id DESC');
$sourceUser=(int)$row['user_id'];$ownerIds->bind_param('ii',$sourceUser,$id);$ownerIds->execute();$owners=[];
$lookup=$db->prepare('SELECT identity FROM imported_artwork WHERE artwork_id=?');
foreach($ownerIds->get_result() as $old){$lookup->execute([(int)$old['image_id']]);$identity=$lookup->fetchColumn();if($identity!==false)$owners[$identity]=true;}
if(count($owners)!==1)throw new RuntimeException('Cannot establish one verified imported owner; no name-based linking allowed.');
$identity=array_key_first($owners);
$existingOwner=$db->prepare('SELECT identity FROM imported_artwork WHERE artwork_id=?');$existingOwner->execute([$id]);$oldOwner=$existingOwner->fetchColumn();
$lookup->closeCursor();$existingOwner->closeCursor();
if($oldOwner!==false && $oldOwner!==$identity)throw new RuntimeException('Artwork ID conflicts with an existing ownership mapping.');
$copies=[];
foreach(['image'=>'thumbnails','fullImage'=>'media'] as $field=>$folder){
    $name=$row[$field==='image'?'image_thumb_file':'image_media_file'];
    if(!$name || basename($name)!==$name || str_contains($name,'\\'))throw new RuntimeException('Unsupported source filename.');
    $file=realpath($original.'/data/'.$folder.'/'.$row['cat_id'].'/'.$name);
    $root=realpath($original.'/data/'.$folder);
    if(!$root || !$file || !str_starts_with($file,$root.'/') || !is_file($file))throw new RuntimeException('Missing source media.');
    $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
    if(!in_array($ext,['jpg','jpeg','png','webp','gif'],true) || !in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true) || !getimagesize($file))throw new RuntimeException('Source image is invalid.');
    $relative=($field==='image'?'gallery':'full').'/'.$id.'.'.$ext;
    $copies[]=['field'=>$field,'source'=>$file,'relative'=>$relative,'target'=>$storage.'/archive/'.$relative,'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file)];
}
$item=['id'=>$id,'title'=>$row['image_name']?:'Untitled artwork','description'=>$row['image_description']??'','author'=>$row['user_name'],'categoryId'=>(int)$row['cat_id'],'category'=>$row['cat_name'],'date'=>gmdate('Y-m-d',(int)$row['image_date']),'views'=>(int)$row['image_hits'],'rating'=>(float)$row['image_rating'],'software'=>'bryce','tags'=>$row['image_keywords']??''];
foreach($copies as $copy)$item[$copy['field']]='/'.$copy['relative'];
echo 'Approved source artwork: '.$id.' — '.$item['title'].' by '.$item['author']."\n";
echo 'Verified existing owner mapping and '.count($copies)." image files.\n";
if(!$apply){echo "Dry run passed. No gallery data changed.\n";exit;}
$backup=$storage.'/import-backups/'.gmdate('Ymd\THis\Z').'-art-'.$id.'-'.bin2hex(random_bytes(3));if(!mkdir($backup,0700,true))throw new RuntimeException('Cannot create private backup.');
if(file_put_contents($backup.'/gallery.json',$before)!==strlen($before))throw new RuntimeException('Catalogue backup failed.');
$snapshot=$db->prepare('VACUUM INTO ?');$snapshot->execute([$backup.'/php-gallery.sqlite']);
foreach($copies as $copy){
    if(is_link($copy['target']))throw new RuntimeException('Unexpected target symlink.');
    if(is_file($copy['target'])){if(hash_file('sha256',$copy['target'])!==$copy['sha256'])throw new RuntimeException('Existing image bytes conflict.');continue;}
    if(!is_dir(dirname($copy['target'])))mkdir(dirname($copy['target']),0700,true);
    $temp=$copy['target'].'.tmp-'.bin2hex(random_bytes(4));
    if(!copy($copy['source'],$temp) || hash_file('sha256',$temp)!==$copy['sha256'])throw new RuntimeException('Image copy verification failed.');
    chmod($temp,0600);if(!rename($temp,$copy['target']))throw new RuntimeException('Cannot publish image file.');
}
$catalogue['items'][]=$item;
$json=json_encode($catalogue,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$temp=$catalogueFile.'.tmp-'.bin2hex(random_bytes(4));
if(file_put_contents($temp,$json)!==strlen($json))throw new RuntimeException('Cannot stage catalogue.');
$published=false;$db->exec('BEGIN IMMEDIATE');
try{
    if(hash_file('sha256',$catalogueFile)!==hash('sha256',$before))throw new RuntimeException('Catalogue changed during import.');
    $insert=$db->prepare('INSERT OR IGNORE INTO imported_artwork(artwork_id,identity) VALUES (?,?)');$insert->execute([$id,$identity]);
    if(!rename($temp,$catalogueFile))throw new RuntimeException('Cannot publish catalogue.');$published=true;
    $db->exec('COMMIT');
}catch(Throwable $error){
    if($published){$restore=$catalogueFile.'.restore-'.bin2hex(random_bytes(4));file_put_contents($restore,$before);rename($restore,$catalogueFile);}
    $db->exec('ROLLBACK');if(is_file($temp))unlink($temp);throw $error;
}
$export=['item'=>$item,'identity'=>$identity,'media'=>array_map(fn($copy)=>['field'=>$copy['field'],'relative'=>$copy['relative'],'sourceRelative'=>substr($copy['source'],strlen($original)+1),'bytes'=>$copy['bytes'],'sha256'=>$copy['sha256']],$copies)];
file_put_contents($backup.'/imported-artwork.json',json_encode($export,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
file_put_contents($storage.'/last-artwork-import.json',json_encode($export,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'Imported one artwork; existing PHP edits, accounts, comments, favorites and unique views preserved. Private backups: '.$backup."\n";
