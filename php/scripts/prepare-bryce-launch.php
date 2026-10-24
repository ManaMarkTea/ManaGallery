<?php
// CLI preparation only: never changes the old document root or original data.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
$home=rtrim(getenv('HOME'),'/');$app=$home.'/rendergallery-php/app';$original=$home.'/public_html_sub/bryce5';
require $original.'/config.php';
if(!preg_match('/^[A-Za-z0-9_]+$/D',$table_prefix))throw new RuntimeException('Invalid source prefix');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$source=new mysqli($db_host,$db_user,$db_password,$db_name);$source->set_charset('utf8');
$catalogue=json_decode(file_get_contents($app.'/storage/gallery.json'),true,512,JSON_THROW_ON_ERROR);
$ids=array_fill_keys(array_column($catalogue['items'],'id'),true);$routes=['media'=>[],'artists'=>[],'downloads'=>[]];$missing=[];
foreach($source->query('SELECT image_id,cat_id,user_id,image_media_file,image_thumb_file FROM '.$table_prefix.'images WHERE image_active=1') as $row){
    $id=(int)$row['image_id'];if(!isset($ids[$id])){$missing[]=$id;continue;}
    $isImage=in_array(strtolower(pathinfo($row['image_media_file'],PATHINFO_EXTENSION)),['jpg','jpeg','png','gif','webp'],true);
    $routes['downloads'][$id]=$isImage ? '/media/'.$id.'/full' : '/download/'.$id;
    foreach(['media'=>['image_media_file','full'],'thumbnails'=>['image_thumb_file','preview']] as $folder=>[$field,$size]){
        $name=$row[$field];if(!$name || basename($name)!==$name || str_contains($name,'\\'))continue;
        $routes['media']['/data/'.$folder.'/'.$row['cat_id'].'/'.$name]=$folder==='media' && !$isImage ? '/download/'.$id : '/media/'.$id.'/'.$size;
    }
}
if($missing)throw new RuntimeException('Import these approved artworks before launch: '.implode(',',$missing));
foreach($source->query('SELECT DISTINCT u.user_id,u.user_name FROM '.$table_prefix.'users u JOIN '.$table_prefix.'images i ON i.user_id=u.user_id WHERE i.image_active=1') as $row)$routes['artists'][(int)$row['user_id']]=$row['user_name'];
$lock=fopen($app.'/storage/site.lock','c');if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock gallery');
$config=require $app.'/config.local.php';$config['index_hosts']=['gallery.example.com','www.gallery.example.com'];
foreach([$app.'/config.local.php'=>"<?php\nreturn ".var_export($config,true).";\n",$app.'/storage/legacy-routes.json'=>json_encode($routes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)] as $file=>$bytes){
    $temp=$file.'.next';if(file_put_contents($temp,$bytes)!==strlen($bytes))throw new RuntimeException('Cannot stage launch configuration');chmod($temp,0600);if(!rename($temp,$file))throw new RuntimeException('Cannot publish launch configuration');
}
$db=new PDO('sqlite:'.$app.'/storage/php-gallery.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=5000');
$statement=$db->prepare('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
$statement->execute(['siteName','RenderGallery']);
$statement->execute(['description','The RenderGallery community gallery. Share Bryce artwork, landscapes and creations made with other rendering tools.']);
flock($lock,LOCK_UN);
echo 'Ready: '.count($ids).' archived artworks, '.count($routes['media']).' legacy image links and '.count($routes['artists'])." artist links.\n";
echo "RenderGallery branding configured; search indexing remains disabled on the test hostname.\nOriginal files, original database and domain folder settings unchanged.\n";
