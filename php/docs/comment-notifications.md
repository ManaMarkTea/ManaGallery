# Comment notifications

Approved new comments queue private email notifications for the artwork owner
and earlier participants, according to their separate account preferences.
Existing imported comments can establish participation, but imports never send
notifications. Self-notifications and duplicate email recipients are excluded.
New or changed addresses need confirmation; confirmed legacy addresses retain
the original `user_showemail` opt-in without making the address public.

The CLI-only `scripts/import-original-notifications.php APP OLD_GALLERY
--check|--apply` reads the original MySQL gallery using its private config. It
matches imported IDs or unambiguous artwork ownership, never display names.
Existing preferences are preserved. It also copies the original Discord webhook
into private configuration. No addresses or credentials are printed.

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
/usr/php85/usr/bin/php ~/rendergallery-php/configure-smtp.php
```

The private helper uses the configured notification From address as its username
on `smtp.stackmail.com:587` with STARTTLS. It hides password entry, verifies the
server certificate and tests authentication without sending a message. Only a
successful login is saved in `config.local.php`, mode 0600, outside the public
root. A private pre-change configuration is retained for rollback. Never enter
the password into a scheduled task, command argument, chat, or Git.

When `notification_smtp` is configured, delivery uses authenticated SMTP and never
falls back to the unauthenticated relay on failure. Diagnostics do not print the
password or SMTP transcript. Private backups include the configuration, locked
dependencies and vendor files; the recovery password remains stored separately.
