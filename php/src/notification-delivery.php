<?php

declare(strict_types=1);

function notification_brand(): string
{
    $settings = function_exists('site_settings') ? site_settings() : [];
    $name = $settings['siteName'] ?? (function_exists('config') ? (config()['notification_brand'] ?? 'ManaGallery') : 'ManaGallery');

    return trim(str_replace(["\r", "\n"], ' ', $name));
}

function notification_mail(string $to, string $subject, string $body, ?string $unsubscribe = null): bool
{
    $from = config()['notification_from'] ?? 'notifications@gallery.example.com';
    foreach ([$to, $from] as $address) {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $address)) {
            throw new RuntimeException('Invalid mail address');
        }
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*@[A-Za-z0-9.-]+$/D', $from)) {
        throw new RuntimeException('Invalid envelope sender');
    }
    if (isset(config()['notification_smtp'])) {
        $mail = notification_smtp_client(config()['notification_smtp'], $from);
        try {
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
            if ($unsubscribe) {
                $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribe . '>');
            }

            return $mail->send();
        } catch (Throwable $error) {
            throw new RuntimeException('Authenticated SMTP delivery failed');
        } finally {
            $mail->smtpClose();
        }
    }
    $brand = notification_brand();
    $displayName = preg_match('/^[A-Za-z0-9 ._-]+$/D', $brand) ? $brand : '"' . addcslashes($brand, '\\"') . '"';
    $headers = [
        'From' => $displayName . ' <' . $from . '>',
        'Sender' => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
        'Auto-Submitted' => 'auto-generated',
    ];
    if ($unsubscribe) {
        $headers['List-Unsubscribe'] = '<' . $unsubscribe . '>';
    }

    // Scheduled CLI jobs have the hosting-account domain as their default
    // envelope sender. Specify our validated site address as well as From.
    return mail($to, $subject, $body, $headers, '-f' . escapeshellarg($from));
}
function notification_smtp_client(array $smtp, string $from): \PHPMailer\PHPMailer\PHPMailer
{
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $smtp['host'] ?? '') || !in_array($smtp['port'] ?? null, [465, 587], true) || !filter_var($smtp['username'] ?? '', FILTER_VALIDATE_EMAIL) || !is_string($smtp['password'] ?? null) || $smtp['password'] === '') {
        throw new RuntimeException('Incomplete SMTP configuration');
    }
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('SMTP dependency is not installed');
    }
    require_once $autoload;
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $smtp['host'];
    $mail->Port = $smtp['port'];
    $mail->SMTPAuth = true;
    $mail->Hostname = substr(strrchr($from, '@'), 1);
    $mail->Username = $smtp['username'];
    $mail->Password = $smtp['password'];
    $mail->SMTPSecure = $smtp['port'] === 465 ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPDebug = 0;
    $mail->Timeout = 15;
    $mail->getSMTPInstance()->Timelimit = 30;
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'quoted-printable';
    $mail->setFrom($from, notification_brand());
    $mail->Sender = $from;

    return $mail;
}
function notification_discord_payload(array $comment, array $art): array
{
    return [
        'allowed_mentions' => ['parse' => []],
        'embeds' => [
            [
                'title' => mb_substr($art['title'], 0, 256),
                'url' => notification_origin() . '/art/' . $art['id'] . '#comments',
                'description' => mb_substr($comment['username'] . ': ' . $comment['body'], 0, 2000),
                'image' => ['url' => notification_origin() . '/media/' . (int)$art['id'] . '/preview'],
            ],
        ],
    ];
}

function notification_discord(array $comment, array $art): bool
{
    $url = config()['discord_webhook'] ?? '';

    return notification_discord_send($url, notification_discord_payload($comment, $art));
}

function notification_discord_artwork_payload(array $art): array
{
    return [
        'allowed_mentions' => ['parse' => []],
        'embeds' => [[
            'title' => mb_substr($art['title'], 0, 256),
            'url' => notification_origin() . '/art/' . (int)$art['id'],
            'description' => 'New artwork by ' . mb_substr($art['author'] ?? 'Community artist', 0, 100),
            'image' => ['url' => notification_origin() . '/media/' . (int)$art['id'] . '/preview'],
        ]],
    ];
}

function notification_discord_artwork(array $art): bool
{
    return notification_discord_send(notification_upload_webhook($art), notification_discord_artwork_payload($art));
}

function notification_discord_send(string $url, array $payload): bool
{
    if (!preg_match('~^https://(?:discord\.com|discordapp\.com)/api/webhooks/[0-9]+/[A-Za-z0-9._-]+$~D', $url)) {
        throw new RuntimeException('Discord webhook is not configured');
    }
    $curl = curl_init($url . '?wait=true');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    try {
        $response = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        return $response !== false && $code >= 200 && $code < 300;
    } finally {
        curl_close($curl);
    }
}
// Injectable transports keep tests isolated: no real mail or Discord posts.
function notification_deliver(array $all, ?callable $mailer = null, ?callable $discord = null, int $limit = 20, ?callable $announcer = null): array
{
    notification_schema();
    $mailer ??= notification_mail(...);
    $discord ??= notification_discord(...);
    $announcer ??= notification_discord_artwork(...);
    query("UPDATE notification_queue SET state='failed',last_error='Worker interrupted; review before retrying to avoid duplicate delivery',payload=CASE WHEN kind='confirmation' THEN '' ELSE payload END WHERE state='sending' AND next_attempt<?", [time() - 900]);
    $counts = ['sent' => 0, 'cancelled' => 0, 'deferred' => 0, 'failed' => 0];
    // Web requests do a short batch; the CLI worker drains larger queues.
    $deadline = PHP_SAPI === 'cli' ? INF : microtime(true) + 8;
    $rows = query("SELECT * FROM notification_queue WHERE state='pending' AND next_attempt<=? ORDER BY id LIMIT ?", [time(), $limit])->fetchAll();
    foreach ($rows as $job) {
        if (microtime(true) >= $deadline) {
            break;
        }
        if (!empty(config()['notifications_paused'])) {
            break;
        }
        $comment = null;
        $art = null;
        $contact = null;
        if ($job['kind'] === 'confirmation') {
            $contact = query('SELECT * FROM notification_contacts WHERE identity=?', [$job['identity']])->fetch();
            $valid = $contact && !$contact['confirmed'] && notification_available($job['identity']) && $contact['email'] === $job['email'] && $contact['confirmation_expires'] >= time() && hash_equals($contact['confirmation_hash'] ?? '', hash('sha256', $job['payload']));
        } elseif ($job['kind'] === 'discord-artwork') {
            $payload = json_decode($job['payload'], true);
            $art = is_array($payload) ? find_artwork($all, (int)($payload['id'] ?? 0)) : null;
            $valid = $art && isset($art['ownerId']) && ($art['filename'] ?? '') === ($payload['filename'] ?? null) && empty($art['hidden']) && empty($art['pending']);
        } else {
            $comment = query('SELECT * FROM comments WHERE id=?', [$job['comment_id']])->fetch();
            $art = $comment ? find_artwork($all, (int)$comment['artwork_id']) : null;
            $valid = $comment && !$comment['deleted'] && !$comment['archived'] && $art && empty($art['hidden']) && empty($art['pending']);
            if ($valid && $comment['moderation_reason'] !== null) {
                $counts['deferred']++;
                continue;
            }
            if ($valid && $job['kind'] === 'comment') {
                $contact = query('SELECT * FROM notification_contacts WHERE identity=?', [$job['identity']])->fetch();
                $valid = $contact && $contact['confirmed'] && in_array($job['payload'], ['owner_enabled', 'thread_enabled'], true) && $contact[$job['payload']] && $contact['email'] === $job['email'] && notification_available($job['identity']);
                $author = $comment['user_id'] ? notification_identity((int)$comment['user_id']) : $comment['import_identity'];
                $actorEmail = query('SELECT lower(email) FROM notification_contacts WHERE identity=?', [$author])->fetchColumn();
                if ($job['identity'] === $author || strtolower($job['email']) === $actorEmail) {
                    $valid = false;
                }
            }
        }
        if (!$valid) {
            query("UPDATE notification_queue SET state='cancelled',payload='' WHERE id=?", [$job['id']]);
            $counts['cancelled']++;
            continue;
        }
        $enabled = match ($job['kind']) {
            'discord' => !empty(config()['discord_webhook']),
            'discord-artwork' => notification_upload_webhook($art) !== '',
            default => !empty(config()['notification_mail_enabled']),
        };
        if (!$enabled) {
            $counts['deferred']++;
            continue;
        }
        query("UPDATE notification_queue SET state='sending',next_attempt=? WHERE id=? AND state='pending'", [time(), $job['id']]);
        if (!(int)query('SELECT changes()')->fetchColumn()) {
            continue;
        }
        try {
            if ($job['kind'] === 'discord-artwork') {
                $ok = $announcer($art);
            } elseif ($job['kind'] === 'discord') {
                $ok = $discord($comment, $art);
            } elseif ($job['kind'] === 'confirmation') {
                $url = notification_origin() . '/notifications/confirm?account=' . rawurlencode($job['identity']) . '&token=' . $job['payload'];
                $ok = $mailer($job['email'], 'Confirm your ' . notification_brand() . ' email address', "Confirm this email address for comment notifications:\n\n" . $url . "\n\nThis link expires in 24 hours. If you did not request it, ignore this email.\n", null);
            } else {
                $unsubscribe = notification_unsubscribe($job['identity']);
                $body = "There is a new comment on artwork you follow on " . notification_brand() . ".\n\nRead the comment:\n" . notification_origin() . '/art/' . $art['id'] . "#comments\n\nManage preferences: " . notification_origin() . "/account\nUnsubscribe from comment emails: " . $unsubscribe . "\n";
                $ok = $mailer($job['email'], 'New comment on ' . notification_brand(), $body, $unsubscribe);
            }
            if (!$ok) {
                throw new RuntimeException('Transport did not accept notification');
            }
            query("UPDATE notification_queue SET state='sent',sent=?,last_error=NULL,payload='' WHERE id=?", [time(), $job['id']]);
            $counts['sent']++;
        } catch (Throwable $error) {
            $attempts = (int)$job['attempts'] + 1;
            $failed = $attempts >= 5;
            // Never log addresses, confirmation tokens, webhook URLs or API replies.
            query('UPDATE notification_queue SET state=?,attempts=?,next_attempt=?,last_error=? WHERE id=?', [$failed ? 'failed' : 'pending', $attempts, time() + min(86400, 300 * (2 ** $attempts)), 'Delivery not accepted; check hosting mail or Discord configuration', $job['id']]);
            $counts[$failed ? 'failed' : 'deferred']++;
        }
    }
    // Keep operational history bounded; never delete outstanding failures.
    query("DELETE FROM notification_queue WHERE state IN ('sent','cancelled') AND created<?", [time() - 30 * 86400]);

    return $counts;
}
