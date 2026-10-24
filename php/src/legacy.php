<?php
declare(strict_types=1);

// Old public bookmarks keep their numeric identities. Never carry old sessions,
// arbitrary redirect targets or request hosts into a Location header.
function legacy_destination(string $path, array $input, array $routes=[]): ?string {
    $decoded=rawurldecode($path);
    if (isset($routes['media'][$decoded])) return $routes['media'][$decoded];
    $number=static fn(string $key): ?int => preg_match('/^[1-9][0-9]{0,8}$/D',param($input,$key)) ? (int)$input[$key] : null;
    $image=$number('image_id');
    if ($path==='/details.php' && $image) return '/art/'.$image;
    if ($path==='/showimage.php' && $image) return '/media/'.$image.'/full';
    if ($path==='/download.php' && $image) return $routes['downloads'][$image]??'/download/'.$image;
    if ($path==='/categories.php' && ($category=$number('cat_id'))) return '/?category='.$category.'#gallery';
    if ($path==='/index.php') return gallery_url([],array_intersect_key($input,array_flip(['q','category','software','artist','sort','perPage','page'])));
    if (in_array($path,['/top.php','/topten.php'],true)) return '/?sort=popular#gallery';
    if ($path==='/search.php') return gallery_url([],['q'=>mb_substr(param($input,'search_keywords',param($input,'q')),0,300)]);
    if ($path==='/register.php') return '/register';
    if ($path==='/member.php') {
        if (param($input,'action')==='showprofile' && ($id=$number('user_id')) && isset($routes['artists'][$id])) return gallery_url([],['artist'=>$routes['artists'][$id]]);
        return param($input,'action')==='register' ? '/register' : '/login';
    }
    return null;
}
