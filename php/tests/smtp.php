<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/notification-delivery.php';
function verify_smtp(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);}
$settings=['host'=>'smtp.example.invalid','port'=>587,'username'=>'notifications@example.invalid','password'=>'fictional-test-password'];
$mail=notification_smtp_client($settings,'notifications@example.invalid');
verify_smtp($mail->Mailer==='smtp' && $mail->SMTPAuth && $mail->SMTPSecure==='tls' && $mail->Port===587,'Authenticated STARTTLS required');
verify_smtp($mail->SMTPDebug===0 && $mail->SMTPOptions['ssl']['verify_peer'] && $mail->SMTPOptions['ssl']['verify_peer_name'] && !$mail->SMTPOptions['ssl']['allow_self_signed'],'Certificate verification and no credential logging');
verify_smtp($mail->From==='notifications@example.invalid' && $mail->Sender===$mail->From,'Header and envelope sender agree');
$mail->addAddress('recipient@example.invalid');$mail->Subject='Fictional notification';$mail->Body='Fictional body';$mail->addCustomHeader('List-Unsubscribe','<https://example.invalid/unsubscribe>');
verify_smtp($mail->preSend(),'Build MIME without contacting any server');$message=$mail->getSentMIMEMessage();
verify_smtp(preg_match('/^From: "?ManaGallery"? <notifications@example\.invalid>\r?$/m',$message)===1 && str_contains($message,'List-Unsubscribe:'),'Correct sender and unsubscribe in SMTP message');
verify_smtp(!str_contains($message,$settings['password']),'SMTP password never enters message');
$settings['port']=465;$secure=notification_smtp_client($settings,'notifications@example.invalid');verify_smtp($secure->SMTPSecure==='ssl','Implicit TLS for port 465');
foreach([['port'=>25],['password'=>''],['host'=>"smtp.example.invalid\r\n"],['username'=>'invalid']] as $bad){
    $rejected=false;try{notification_smtp_client(array_replace($settings,$bad),'notifications@example.invalid');}catch(RuntimeException $error){$rejected=true;}
    verify_smtp($rejected,'Invalid SMTP settings rejected');
}
echo "SMTP configuration, TLS, sender and MIME checks passed without sending mail.\n";
