<?php
declare(strict_types=1);
$directory=dirname(__DIR__).'/test-results/notifications-'.bin2hex(random_bytes(6));putenv('RENDERGALLERY_PHP_DATA_DIR='.$directory);
function config(): array{return ['notification_mail_enabled'=>true,'discord_webhook'=>'fixture-webhook-configured','notification_origin'=>'https://example.invalid'];}
function find_artwork(array $all,int $id): ?array{foreach($all['items'] as $item)if($item['id']===$id)return $item;return null;}
function check(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);}
require dirname(__DIR__).'/src/database.php';require dirname(__DIR__).'/src/notifications.php';require dirname(__DIR__).'/src/notification-delivery.php';
notification_schema();
for($i=1;$i<=5;$i++){
    query('INSERT INTO users(id,username,username_key,password,created) VALUES (?,?,?,?,?)',[$i,'user'.$i,'user'.$i,'fictional',1]);
    query('INSERT INTO notification_contacts(identity,email,confirmed,owner_enabled,thread_enabled,updated) VALUES (?,?,1,1,1,1)',['local:'.$i,'user'.$i.'@example.invalid']);
}
$all=['items'=>[['id'=>7,'ownerId'=>1,'title'=>'Fictional artwork']]];
function add_comment(int $user,?string $held=null,int $archived=0): int{
    query('INSERT INTO comments(artwork_id,user_id,username,body,created,moderation_reason,archived) VALUES (7,?,?,?,?,?,?)',[$user,'user'.$user,'Fictional comment',time(),$held,$archived]);return (int)database()->lastInsertId();
}
add_comment(2,null,1);add_comment(4,null,1);query("UPDATE notification_contacts SET thread_enabled=0 WHERE identity='local:4'");
$held=add_comment(3,'Review');with_write(fn()=>notification_enqueue($held,$all));
check((int)query('SELECT COUNT(*) FROM notification_queue')->fetchColumn()===0,'Held comments do not queue notifications');
query('UPDATE comments SET moderation_reason=NULL WHERE id=?',[$held]);with_write(fn()=>notification_enqueue($held,$all));with_write(fn()=>notification_enqueue($held,$all));
check((int)query('SELECT COUNT(*) FROM notification_queue')->fetchColumn()===3,'One owner, one prior commenter, one Discord; no self/opt-out/duplicate');
$mails=[];$posts=[];
$mailer=function(...$args)use(&$mails){$mails[]=$args;return true;};$discord=function(...$args)use(&$posts){$posts[]=$args;return true;};
notification_deliver($all,$mailer,$discord);
check(count($mails)===2 && count($posts)===1,'Approved comment delivered to both email recipients and Discord');
check(str_contains($mails[0][2],'/notifications/unsubscribe?') && !str_contains($mails[0][2],'user2@'),'Private, individual emails include unsubscribe');
notification_deliver($all,$mailer,$discord);check(count($mails)===2 && count($posts)===1,'Sent notifications do not repeat');
$archive=add_comment(5,null,1);notification_enqueue($archive,$all);check((int)query('SELECT COUNT(*) FROM notification_events')->fetchColumn()===1,'Historical import never queues');
query("UPDATE notification_contacts SET thread_enabled=0 WHERE identity='local:5'");
$cancel=add_comment(3);notification_enqueue($cancel,$all);query('UPDATE comments SET deleted=1 WHERE id=?',[$cancel]);notification_deliver($all,$mailer,$discord);check(count($mails)===2 && count($posts)===1,'Deletion before dispatch cancels notifications');
$stop=add_comment(3);notification_enqueue($stop,$all);query("UPDATE notification_contacts SET owner_enabled=0 WHERE identity='local:1'");query("UPDATE users SET disabled=1 WHERE id=2");notification_deliver($all,$mailer,$discord);check(count($mails)===2 && count($posts)===2,'Unsubscribe and disabled account rechecked at send time');
$failure=add_comment(3);notification_enqueue($failure,$all);notification_deliver($all,$mailer,fn()=>false);check((int)query("SELECT attempts FROM notification_queue WHERE comment_id=? AND kind='discord'",[$failure])->fetchColumn()===1,'Failed transport schedules bounded retry');
check(notification_token('local:1')!==notification_token('local:2'),'Unsubscribe credentials bound to account');
query("UPDATE notification_contacts SET email='user3@example.invalid',owner_enabled=1 WHERE identity='local:1'");query('UPDATE users SET disabled=0 WHERE id=2');
$same=add_comment(3);notification_enqueue($same,$all);check(!(bool)query("SELECT 1 FROM notification_queue WHERE comment_id=? AND identity='local:1'",[$same])->fetchColumn(),'Same email as author excluded across identities');
echo "Notification recipient, moderation, archive, deduplication, unsubscribe, disabled-account, retry and privacy checks passed.\n";
