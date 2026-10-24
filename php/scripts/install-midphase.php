<?php
// Initial shared-host deployment only. Never overwrite live runtime data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$app=__DIR__; $home=rtrim(getenv('HOME'),'/');
$public=$home.'/public_html_sub/rendergallery-php';
$original=realpath($home.'/public_html_sub/bryce5');
if (!$original || realpath($app)!==$home.'/rendergallery-php/app') throw new RuntimeException('Unexpected installation paths.');
foreach(['pdo_sqlite','sodium','gd','mbstring','fileinfo'] as $extension) if(!extension_loaded($extension)) throw new RuntimeException('Missing '.$extension);
$storage=$app.'/storage';
if(!is_dir($storage.'/archive')) mkdir($storage.'/archive',0700,true);
foreach(['full','gallery'] as $dir) if(!is_dir($storage.'/archive/'.$dir)) mkdir($storage.'/archive/'.$dir,0700);
$manifest=json_decode(file_get_contents($app.'/media-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$missing=[];$count=0;
foreach($manifest['files'] as $entry) {
    if(!preg_match('~^data/(?:media|thumbnails)/[0-9]+/[^/\\\\\x00]+$~D',$entry['source']) || !preg_match('~^(?:full|gallery)/[1-9][0-9]*\.(?:jpg|jpeg|png|gif|webp|bmp)$~D',$entry['target'])) throw new RuntimeException('Invalid media manifest.');
    $from=realpath($original.'/'.$entry['source']);$to=$storage.'/archive/'.$entry['target'];
    if(is_file($to) && filesize($to)===$entry['bytes'] && hash_file('sha256',$to)===$entry['sha256']) { $count++;continue; }
    if(!$from || !str_starts_with($from,$original.'/data/') || !is_file($from) || filesize($from)!==$entry['bytes'] || hash_file('sha256',$from)!==$entry['sha256']) { $missing[]=$entry['target'];continue; }
    if(is_link($to)) throw new RuntimeException('Unexpected media symlink.');
    if(!copy($from,$to) || hash_file('sha256',$to)!==$entry['sha256']) throw new RuntimeException('Image copy verification failed.');
    chmod($to,0600);$count++;
    if($count%500===0) echo 'Verified/copied '.$count." archive images\n";
}
file_put_contents($app.'/missing-media.json',json_encode($missing));
if($missing) { echo count($missing)." archive images need private upload fallback. Not publishing.\n";exit(2); }
$db=new PDO('sqlite:'.$storage.'/php-gallery.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok') throw new RuntimeException('Database integrity check failed.');
if(strtolower($db->query('PRAGMA journal_mode=WAL')->fetchColumn())!=='wal') throw new RuntimeException('WAL mode unavailable.');
$db->exec('BEGIN IMMEDIATE');$db->exec('ROLLBACK');$db->exec('PRAGMA wal_checkpoint(TRUNCATE)');$db=null;
if(!is_file($app.'/config.local.php'))file_put_contents($app.'/config.local.php',"<?php\nreturn ['media_root'=>__DIR__.'/storage/archive'];\n");
if(!is_dir($storage.'/sessions'))mkdir($storage.'/sessions',0700);
// Keep the initial database snapshot separately; this is not a scheduled backup.
if(!is_dir($home.'/rendergallery-php/initial-snapshot'))mkdir($home.'/rendergallery-php/initial-snapshot',0700);
if(!is_file($home.'/rendergallery-php/initial-snapshot/php-gallery.sqlite'))copy($storage.'/php-gallery.sqlite',$home.'/rendergallery-php/initial-snapshot/php-gallery.sqlite');
if(!is_dir($public))mkdir($public,0755,true);
chmod($public,0755);
if(is_link($public) || str_starts_with(realpath($storage),realpath($public).'/'))throw new RuntimeException('Private storage must remain outside document root.');
$entry="<?php\n// RenderGallery shared-host entry.\nif (strtolower(\$_SERVER['HTTP_HOST'] ?? '') !== 'php.gallery.example.com') { http_response_code(403); exit; }\nif (empty(\$_SERVER['HTTPS']) || \$_SERVER['HTTPS']==='off') { header('Location: https://php.gallery.example.com'.\$_SERVER['REQUEST_URI'],true,308); exit; }\nrequire ".var_export($app.'/public/index.php',true).";\n";
if(is_file($public.'/index.php') && file_get_contents($public.'/index.php')!==$entry)throw new RuntimeException('Refusing to overwrite an unrelated public entry point.');
if(!is_dir($public.'/assets'))mkdir($public.'/assets',0755);
chmod($public.'/assets',0755);
foreach(new DirectoryIterator($app.'/public/assets') as $file)if($file->isFile()) { copy($file->getPathname(),$public.'/assets/'.$file->getFilename());chmod($public.'/assets/'.$file->getFilename(),0644); }
$htaccess="Options -Indexes\nDirectoryIndex index.php\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^ index.php [L]\n</IfModule>\n";
if(is_file($public.'/.htaccess') && file_get_contents($public.'/.htaccess')!==$htaccess)throw new RuntimeException('Public .htaccess differs; inspect before replacing.');
file_put_contents($public.'/.htaccess',$htaccess);chmod($public.'/.htaccess',0644);
file_put_contents($public.'/.user.ini',"display_errors=Off\nlog_errors=On\nerror_log=".$home."/rendergallery-php/php-errors.log\nupload_max_filesize=10M\npost_max_size=12M\nmemory_limit=256M\n");chmod($public.'/.user.ini',0644);
file_put_contents($public.'/index.php',$entry);chmod($public.'/index.php',0644);
echo 'Installed private app and database; '.$count." archive images verified.\n";
echo "Public root contains only the entry point and static assets. HTTPS must be checked before sign-in.\n";
