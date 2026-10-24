<?php
declare(strict_types=1);
function site_settings(): array {
    $values=query('SELECT key,value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['siteName'=>$values['siteName']??'RenderGallery','description'=>$values['description']??'A community gallery for Bryce, Blender and beyond.',
        'registrationOpen'=>($values['registrationOpen']??'true')==='true','uploadsOpen'=>($values['uploadsOpen']??'true')==='true',
        'memberQuotaMiB'=>(int)($values['memberQuotaMiB']??1024),'galleryQuotaMiB'=>(int)($values['galleryQuotaMiB']??2048),
        'analyticsId'=>preg_match('/^G-[A-Z0-9]{6,20}$/D',$values['analyticsId']??'') ? $values['analyticsId'] : '',
        'analyticsEnabled'=>($values['analyticsEnabled']??'false')==='true'];
}
function apply_categories(array $data): array {
    $map=[]; foreach ($data['categories'] as $i=>$c) $map[$c['id']]=array_replace(['description'=>'','sort_order'=>$i,'deleted'=>0],$c);
    foreach(query('SELECT * FROM category_overrides')->fetchAll() as $c) $map[$c['id']]=$c;
    $data['categories']=array_values(array_filter($map,fn($c)=>!$c['deleted']));
    usort($data['categories'],fn($a,$b)=>$a['sort_order']<=>$b['sort_order'] ?: strcmp($a['name'],$b['name']));
    return $data;
}
function managed_accounts(): array {
    $accounts=[];
    $uploads=query('SELECT user_id,COUNT(*) FROM uploads GROUP BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $archive=query('SELECT identity,COUNT(*) FROM imported_artwork GROUP BY identity')->fetchAll(PDO::FETCH_KEY_PAIR);
    $linked=query('SELECT local_user_id,identity FROM imported_accounts WHERE local_user_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach(query('SELECT id,username,role,disabled,auto_approve,created,last_login,last_seen,login_count FROM users')->fetchAll() as $u) {
        $u['key']='local:'.$u['id']; $u['source']=isset($linked[$u['id']]) ? 'Migrated gallery account' : 'Gallery account'; $u['uploads']=(int)($uploads[$u['id']]??0); $u['archive']=(int)($archive[$linked[$u['id']]??'']??0); $accounts[]=$u;
    }
    foreach(query('SELECT identity,username,role,disabled,auto_approve,created,last_login,last_seen,login_count FROM imported_accounts WHERE local_user_id IS NULL')->fetchAll() as $u) {
        $u['id']=null; $u['uploads']=0; $u['archive']=(int)($archive[$u['identity']]??0); $u['key']='imported:'.$u['identity']; $u['source']='Imported · not signed in yet'; $accounts[]=$u;
    }
    return $accounts;
}
function member_target(string $key): ?array {
    if (preg_match('/^local:([1-9][0-9]*)$/D',$key,$m)) {
        $u=query('SELECT id,role,disabled,auto_approve FROM users WHERE id=?',[(int)$m[1]])->fetch();
        if (!$u) return null;
        $u['identity']=query('SELECT identity FROM imported_accounts WHERE local_user_id=?',[$u['id']])->fetchColumn() ?: 'php-local:'.$u['id'];
        $u['table']='users'; $u['where']='id'; $u['value']=$u['id']; return $u;
    }
    if (preg_match('/^imported:(next-(?:local|legacy):[1-9][0-9]*)$/D',$key,$m)) {
        $u=query('SELECT * FROM imported_accounts WHERE identity=?',[$m[1]])->fetch();
        if (!$u) return null;
        if ($u['local_user_id']) return member_target('local:'.$u['local_user_id']);
        $u['id']=null; $u['table']='imported_accounts'; $u['where']='identity'; $u['value']=$u['identity']; return $u;
    }
    return null;
}
function set_member_policy(array $user,string $key,string $action): void {
    with_write(function() use ($user,$key,$action) {
        $target=member_target($key); if (!$target) throw new DomainException('Member not found.');
        if ($action!=='trust' && $target['id']===(int)$user['id']) throw new DomainException('You cannot change your own administrator access.');
        $column=match($action) {'trust'=>'auto_approve','access'=>'disabled','role'=>'role',default=>throw new DomainException('Invalid member action.')};
        $value=$action==='role' ? ($target['role']==='admin' ? 'member' : 'admin') : 1-(int)$target[$column];
        query('UPDATE '.$target['table'].' SET '.$column.'=? WHERE '.$target['where'].'=?',[$value,$target['value']]);
        if ($target['table']==='users') query('UPDATE imported_accounts SET '.$column.'=? WHERE local_user_id=?',[$value,$target['id']]);
        if ($action==='trust') query('INSERT INTO publishing_overrides(identity,auto_approve) VALUES (?,?) ON CONFLICT(identity) DO UPDATE SET auto_approve=excluded.auto_approve',[$target['identity'],$value]);
        log_activity((int)$user['id'],'Member '.$action.' updated',$key);
    });
}
function admin_action(string $path,array $user,array $all): bool {
    if (!in_array($path,['/admin/member','/admin/settings','/admin/category','/admin/comment'],true)) return false;
    require_admin($user);
    if ($path==='/admin/member') { set_member_policy($user,param($_POST,'account'),param($_POST,'action')); redirect_to('/admin/users'); }
    if ($path==='/admin/settings') {
        if (param($_POST,'section')==='analytics') {
            $id=strtoupper(param($_POST,'analyticsId'));
            if($id!=='' && !preg_match('/^G-[A-Z0-9]{6,20}$/D',$id))throw new DomainException('Enter a Google Analytics measurement ID such as G-XXXXXXXXXX.');
            $enabled=isset($_POST['analyticsEnabled']);
            if($enabled && $id==='')throw new DomainException('Enter a measurement ID before enabling analytics.');
            with_write(function()use($id,$enabled,$user){
                foreach(['analyticsId'=>$id,'analyticsEnabled'=>$enabled ? 'true' : 'false'] as $key=>$value)query('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[$key,$value]);
                log_activity((int)$user['id'],'Analytics settings updated','site');
            });
            $_SESSION['notice']='Analytics settings saved.';redirect_to('/admin/settings');
        }
        $name=param($_POST,'siteName'); $description=param($_POST,'description');
        if (!$name || mb_strlen($name)>80 || mb_strlen($description)>1000) throw new DomainException('Use a site name up to 80 characters and description up to 1,000.');
        $values=['siteName'=>$name,'description'=>$description,'registrationOpen'=>isset($_POST['registrationOpen']) ? 'true' : 'false','uploadsOpen'=>isset($_POST['uploadsOpen']) ? 'true' : 'false'];
        foreach(['memberQuotaMiB','galleryQuotaMiB'] as $key) {
            $value=filter_var(param($_POST,$key),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1048576]]);
            if ($value===false) throw new DomainException('Storage limits must be whole MiB between 1 and 1,048,576.');
            $values[$key]=(string)$value;
        }
        with_write(function() use ($values,$user) { foreach($values as $key=>$value) query('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[$key,$value]); log_activity((int)$user['id'],'Site settings updated','site'); });
        $_SESSION['notice']='Settings saved.'; redirect_to('/admin/settings');
    }
    if ($path==='/admin/category') {
        $id=(int)param($_POST,'id'); $action=param($_POST,'action','save');
        with_write(function() use ($id,$action,$all,$user) {
            if ($action==='delete') {
                if (count(array_filter($all['items'],fn($i)=>(int)$i['categoryId']===$id))) throw new DomainException('Move all artwork out of this category before deleting it.');
                $category=null; foreach($all['categories'] as $c) if ((int)$c['id']===$id) $category=$c;
                if (!$category) throw new DomainException('Category not found.');
                query('INSERT INTO category_overrides(id,name,deleted) VALUES (?,?,1) ON CONFLICT(id) DO UPDATE SET deleted=1',[$id,$category['name']]);
            } else {
                $name=param($_POST,'name'); $description=param($_POST,'description'); $order=filter_var(param($_POST,'sort_order','0'),FILTER_VALIDATE_INT);
                if (!$name || mb_strlen($name)>100 || mb_strlen($description)>2000 || $order===false || abs($order)>100000) throw new DomainException('Enter a valid name, description and sort order.');
                $actualId=$id;
                if (!$actualId) $actualId=max([1000, ...array_map(fn($c)=>(int)$c['id'],$all['categories']), (int)query('SELECT COALESCE(MAX(id),0) FROM category_overrides')->fetchColumn()])+1;
                elseif (!in_array($actualId,array_map(fn($c)=>(int)$c['id'],$all['categories']),true)) throw new DomainException('Category not found.');
                query('INSERT INTO category_overrides(id,name,description,sort_order,deleted) VALUES (?,?,?,?,0) ON CONFLICT(id) DO UPDATE SET name=excluded.name,description=excluded.description,sort_order=excluded.sort_order,deleted=0',[$actualId,$name,$description,$order]);
            }
            log_activity((int)$user['id'],'Category '.$action,(string)$id);
        }); redirect_to('/admin/categories');
    }
    if ($path==='/admin/comment') {
        $id=(int)param($_POST,'id'); $action=param($_POST,'action');
        if (!in_array($action,['approve','hold','delete'],true)) throw new DomainException('Invalid review action.');
        with_write(function() use($action,$id,$all) {
            query('UPDATE comments SET '.match($action) {'approve'=>'moderation_reason=NULL','hold'=>"moderation_reason='Held by administrator'",'delete'=>'deleted=1'}.' WHERE id=?',[$id]);
            if($action==='approve')notification_enqueue((int)$id,$all);
        });
        log_activity((int)$user['id'],'Comment '.$action,(string)$id); redirect_to('/admin/comments');
    }
    return false;
}
function admin_page(array $records,int $size=25): array {
    $pages=max(1,(int)ceil(count($records)/$size)); $page=max(1,min($pages,(int)param($_GET,'page','1')));
    return [array_slice(array_values($records),($page-1)*$size,$size),$page,$pages,count($records)];
}
function admin_pagination(string $path,int $page,int $pages,string $label='Admin pages'): void {
    if ($pages<2) return;
    echo '<nav class="page-links" aria-label="'.e($label).'">';
    foreach(['← Previous'=>$page-1,'Next →'=>$page+1] as $label=>$target) if ($target>=1 && $target<=$pages) {
        $params=$_GET; $params['page']=$target; echo '<a href="'.e($path.'?'.http_build_query($params)).'">'.e($label).'</a>';
    }
    echo '<span>Page '.$page.' of '.$pages.'</span></nav>';
}
