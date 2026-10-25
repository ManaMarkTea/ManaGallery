# Comment notifications

Approved new comments queue private email notifications for the artwork owner
and earlier participants, according to their separate account preferences.
Self-notifications and duplicate email recipients are excluded. New or changed
email addresses require confirmation and are never made public.

Production `config.local.php` needs `notification_mail_enabled => true`,
`notification_from` (a sender on the site's domain), and `notification_origin`
(the canonical HTTPS origin). `discord_webhook` is optional and secret. Defaults
leave mail disabled; local tests inject fake transports and never contact Discord.
The mail service must permit the sender; acceptance alone does not prove delivery.

Install `scripts/send-notifications.php` outside the document root. On shared hosting,
add this command to the hosting control panel's scheduled tasks every five minutes:

```sh
php /private/ManaGallery/send-notifications.php /private/ManaGallery/app
```

The installed sender also detects its neighboring `app` directory when the final
application-path argument is omitted. Some hosts disable SSH `crontab` access; use their scheduled-task panel instead. Until the panel task is
created, jobs remain queued; deploying the application does not start a scheduler.
The worker's `--status` option prints aggregate counts without addresses or tokens.
Admin → Site health shows its last run, counts and sanitized delivery errors.

The worker takes a private lock and processes at most 20 due jobs per run. It
rechecks comment visibility, deletion, moderation, account access and preferences.
Failures retry with increasing delays, up to five attempts. Interrupted deliveries
are marked failed for review rather than automatically risking a duplicate. Email
delivery cannot provide an exactly-once guarantee across process crashes. Successful
and cancelled queue records expire after 30 days; outstanding failures remain.

Email contains an artwork link and unsubscribe link, not the comment body or other
recipients. Confirmation and unsubscribe require a CSRF-protected POST after opening
the signed link, so link scanners do not change preferences. Confirmation expires
after 24 hours. Unsubscribe turns off both comment-email preferences.

Discord receives the approved public comment, author display name and artwork link.
Mentions are disabled. No test messages or historical comments are broadcast during
installation. Once a public comment has been delivered, later deletion does not
recall its email or Discord post.

## Authenticated SMTP

The hosting mail relay rewrites unauthenticated scheduled mail to the account's
primary domain, even when both From and the envelope sender are set. Use a real
notifications mailbox with authenticated SMTP to preserve the gallery sender.

Install dependencies from the committed Composer lock file:

```sh
composer install --no-dev --no-interaction --no-plugins --no-scripts
```

Keep the entire `vendor` directory outside the document root next to `src`.
The initial installation package includes it. Code-only updates preserve it;
dependency updates must install the new locked vendor tree separately.

After creating the mailbox with your email provider, run in an interactive SSH terminal:

```sh
php scripts/configure-smtp.php /private/gallery smtp.example.com 587 notifications@example.com
```

Use your mail provider’s SMTP hostname, port and mailbox username. Port 587 uses
STARTTLS; port 465 uses implicit TLS. The helper hides password entry, verifies the
server certificate and tests authentication without sending a message. Only a
successful login is saved in `config.local.php`, mode 0600, outside the public
root. A private pre-change configuration is retained for rollback. Never enter
the password into a scheduled task, command argument, chat, or Git.

When `notification_smtp` is configured, delivery uses authenticated SMTP and never
falls back to the unauthenticated relay on failure. Diagnostics do not print the
password or SMTP transcript. Private backups include the configuration, locked
dependencies and vendor files; the recovery password remains stored separately.

## Delivery and Discord channels

On FastCGI hosts, posting an approved comment starts a small delivery batch after
the response finishes. Keep the five-minute scheduled worker for retries and
larger queues. Admin > Site health also has a **Send pending notifications now**
button. Other PHP interfaces use the scheduled worker or this button.

Comment posts include the artwork preview and use the existing private
`discord_webhook` setting. They cannot ping Discord members.

To announce new artwork, open **Admin > Settings > Discord artwork announcements**.
For each gallery category, create a webhook in its Discord channel under
**Edit Channel > Integrations > Webhooks**, then paste that URL into the matching
category field. Leave a field empty to keep its destination, or select Disconnect
to turn it off. Saved webhook URLs are never displayed in the page.

Announcements run when a new upload is published or approved. Pending and hidden
artwork is excluded; imported artwork is not reposted. Each artwork is announced
once, with its title, artist, preview and a link. Categories without a destination
do not post to another channel. Removing or hiding artwork before delivery cancels
its queued announcement. Delivery errors use the same bounded retry queue as
comment notifications.
