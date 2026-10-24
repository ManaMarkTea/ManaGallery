<?php
declare(strict_types=1);
// Run with -d disable_functions=mail: this fake transport never sends email.
if(!in_array('mail',array_map('trim',explode(',',ini_get('disable_functions'))),true))throw new RuntimeException('Disable the real mail function before running this test');
$sender='notifications@example.invalid';$calls=[];
function config(): array{return ['notification_from'=>$GLOBALS['sender']]+(isset($GLOBALS['smtp']) ? ['notification_smtp'=>$GLOBALS['smtp']] : []);}
if(!function_exists('mail')) {
    function mail(string $to,string $subject,string $body,array $headers,string $parameters): bool {
        $GLOBALS['calls'][]=[$to,$headers,$parameters];return true;
    }
}
require dirname(__DIR__).'/src/notification-delivery.php';
if(!notification_mail('recipient@example.invalid','Fictional test','Fictional body'))throw new RuntimeException('Transport failed');
[$to,$headers,$parameters]=$calls[0];
if($headers['From']!=='ManaGallery <notifications@example.invalid>' || $headers['Sender']!==$sender || $parameters!=='-f'.escapeshellarg($sender))throw new RuntimeException('Header and envelope senders must match');
foreach(["bad@example.invalid\r\nBcc: other@example.invalid",'"bad sender"@example.invalid','bad;command@example.invalid'] as $bad){
    $sender=$bad;$rejected=false;try{notification_mail('recipient@example.invalid','Test','Test');}catch(RuntimeException $e){$rejected=true;}
    if(!$rejected || count($calls)!==1)throw new RuntimeException('Unsafe sender reached mail transport');
}
$sender='notifications@example.invalid';$smtp=['host'=>'smtp.example.invalid','port'=>587,'username'=>$sender,'password'=>''];
$rejected=false;try{notification_mail('recipient@example.invalid','Test','Test');}catch(RuntimeException $error){$rejected=true;}
if(!$rejected || count($calls)!==1)throw new RuntimeException('Invalid SMTP configuration must not fall back to unauthenticated mail');
echo "Mail header/envelope sender and injection checks passed without sending mail.\n";
