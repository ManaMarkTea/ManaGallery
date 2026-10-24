<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/gallery.php';
require dirname(__DIR__).'/src/database.php';
require dirname(__DIR__).'/src/auth.php';
require dirname(__DIR__).'/src/uploads.php';
require dirname(__DIR__).'/src/imported-auth.php';
require dirname(__DIR__).'/src/admin.php';
require dirname(__DIR__).'/src/comments.php';
require dirname(__DIR__).'/src/community.php';
require dirname(__DIR__).'/src/downloads.php';
require dirname(__DIR__).'/src/actions.php';
require dirname(__DIR__).'/src/legacy.php';
require dirname(__DIR__).'/src/analytics.php';
require dirname(__DIR__).'/src/backups.php';
require dirname(__DIR__).'/src/notifications.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: private, no-store');
$method=$_SERVER['REQUEST_METHOD'];
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$postRoutes=['/register','/login','/logout','/upload','/account/password','/admin/approve','/admin/trust','/admin/disable','/admin/visibility','/admin/member','/admin/settings','/admin/category','/admin/comment','/comment','/comment/delete','/favorite'];
$adminPages=['/admin','/admin/review','/admin/users','/admin/artworks','/admin/comments','/admin/categories','/admin/settings','/admin/activity','/admin/health'];
$postRoutes=array_merge($postRoutes,['/account/notifications','/notifications/confirm','/notifications/unsubscribe']);
if (!in_array($method,['GET','HEAD','POST'],true) || ($method==='POST' && !in_array($path,$postRoutes,true) && !preg_match('~^/art/[1-9][0-9]*/(?:edit|delete)$~D',$path))) { header('Allow: GET, HEAD'); http_response_code(405); exit('Method not allowed'); }
try {
    $siteLock=fopen(private_directory().'/site.lock','c');
    if (!$siteLock || !flock($siteLock,LOCK_SH)) throw new RuntimeException('Cannot acquire gallery request lock');
    if (in_array($method,['GET','HEAD'],true)) {
        $routesFile=private_directory().'/legacy-routes.json';
        $routes=is_file($routesFile) ? json_decode(file_get_contents($routesFile),true,512,JSON_THROW_ON_ERROR) : [];
        if (($legacy=legacy_destination($path,$_GET,$routes))!==null) { header('Location: '.$legacy,true,301); exit; }
    }
    start_session(); $user=current_user();
    if(notification_public_action($path,$method))exit;
    $all=all_artworks(apply_categories(catalogue()));
    $data=$all; $data['items']=array_values(array_filter($all['items'],fn($item)=>empty($item['hidden']) && empty($item['pending'])));
    $artwork=null; $notFound=false; $formError='';
    if ($method==='POST') {
        try { handle_action($path,$user,$all); }
        catch (DomainException $error) { if ($path==='/comment') { $_SESSION['notice']=$error->getMessage(); redirect_to('/art/'.(int)param($_POST,'artwork').'#comments'); }
            http_response_code(422); $formError=$error->getMessage(); }
    }
    if (preg_match('~^/download/([1-9][0-9]{0,8})$~D',$path,$matches)) {
        $item=find_artwork($all,(int)$matches[1]); $format=param($_GET,'format','original');
        if (!$item || !can_view($user,$item) || !attachment_info((int)$item['id'],$format)) { http_response_code(404); exit('Download not available'); }
        if (!$user) redirect_to('/login?next='.rawurlencode('/art/'.$item['id']));
        serve_attachment($item,$user,$format,$method);
    }
    if (preg_match('~^/media/([1-9][0-9]*)/(full|preview)$~D',$path,$matches)) {
        $item=find_artwork($all,(int)$matches[1]);
        if (!$item || !can_view($user,$item)) { http_response_code(404); exit('Image not found'); }
        $file=isset($item['ownerId']) ? uploaded_media($item,$matches[2]==='full') : media_path($item,$matches[2]==='full',config()['media_root']);
        $mime=$file ? (new finfo(FILEINFO_MIME_TYPE))->file($file) : null;
        if (!$file || !in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)) { http_response_code(404); exit('Image not found'); }
        session_write_close();
        header('Content-Type: '.$mime); header('Content-Length: '.filesize($file));
        if ($method!=='HEAD') readfile($file);
        exit;
    }
    if (preg_match('~^/art/([1-9][0-9]*)(/edit)?$~D',$path,$matches)) {
        $artwork=find_artwork($all,(int)$matches[1]);
        if (!$artwork || !can_view($user,$artwork) || (isset($matches[2]) && !can_edit($user,$artwork))) { $artwork=null; $notFound=true; }
    } elseif (in_array($path,['/account','/account/password','/account/notifications','/upload'],true)) require_user($user);
    elseif (in_array($path,$adminPages,true) || ($method==='POST' && str_starts_with($path,'/admin/') && in_array($path,$postRoutes,true))) require_admin($user);
    elseif (!in_array($path,['/','/about','/privacy','/login','/register'],true)) $notFound=true;
    if ($notFound) http_response_code(404);
    if ($artwork && !str_ends_with($path,'/edit') && $method==='GET' && empty($artwork['pending']) && empty($artwork['hidden'])) $artwork['views']=track_page_view((int)$artwork['id'],$user);
    $state=browse($data,$_GET);
    $title=$notFound ? 'Page not found' : ($artwork['title']??(match($path) {'/about'=>'About the community','/privacy'=>'Privacy','/login'=>'Sign in','/register'=>'Create account','/account','/account/password'=>'Your account','/upload'=>'Upload artwork',default=>str_starts_with($path,'/admin') ? 'Administration' : 'Image gallery'}));
    $analytics=analytics_configuration($path,$user,$notFound);
    if($analytics)header("Content-Security-Policy: default-src 'self'; script-src 'self' https://www.googletagmanager.com; connect-src 'self' https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com; img-src 'self' https://*.google-analytics.com https://*.googletagmanager.com; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    $notice=$_SESSION['notice']??''; unset($_SESSION['notice']);
    header('Content-Type: text/html; charset=utf-8');
    require dirname(__DIR__).'/templates/layout.php';
} catch (Throwable $error) {
    error_log('PHP gallery: '.$error->getMessage());
    http_response_code(503); header('Content-Type: text/plain; charset=utf-8');
    echo 'The gallery is temporarily unavailable. Check the private server error log.';
}
