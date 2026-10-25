# Shared-hosting deployment

ManaGallery is not tied to a hosting company. Use PHP 8.3+ with PDO SQLite, GD,
Sodium, mbstring and fileinfo. SMTP also needs OpenSSL and Composer dependencies.
Select PHP through your provider; Apache PHP handler directives are provider-specific.

## Directory layout

Choose a private directory, for example `/home/account/Apps/ManaGallery`, with
application source in `app/` and release staging in a separate `releases/` directory.
Keep config.local.php, storage, uploads, backup exports and credentials outside
the public document root. Never upload the entire repository into a public folder.

Install locked dependencies in the application directory with
`composer install --no-dev --no-plugins --no-scripts`. Copy config.example.php to
config.local.php privately and configure your paths, origin and sender. Provision
the catalogue/data separately; deployment tools do not create or migrate a gallery.

Copy `scripts/shared-host-launcher.php` to the public root as `index.php`, and edit
its private application path, canonical hostname and allowed hostname list.
Copy `public/assets/` to the public root. For Apache, the supplied
`scripts/shared-host.htaccess` is an example; other web servers need equivalent
routing and private-file protection. Configure HTTPS using your host's certificate
service. Verify redirects, assets and an artwork page before changing DNS.

## Updates and scheduled tasks

Code-only updates accept explicit paths:

```sh
php scripts/deploy-shared-host.php /private/releases/reviewed-release /private/app /public/root
```

This tool requires a reviewed `manifest.json` describing each allowed file, its
`after` SHA-256 and `beforeNormalized` SHA-256 (LF-normalized existing content, or
null for a new file). It is a guarded low-level updater, not a Git pull or package
builder. Keep release staging private. It preserves runtime data and config.

Schedule `php /private/path/send-notifications.php /private/app` every five minutes;
see [notifications](comment-notifications.md) for installation and SMTP setup.
Use the PHP executable supplied by your host if `php` selects an older version.

## Backups

The optional SSH/rsync backup scripts require explicit private deployment settings:

```sh
export MANAGALLERY_PRIVATE_ROOT=/private/ManaGallery
export MANAGALLERY_PUBLIC_ROOT=/public/root
export RENDERGALLERY_BACKUP_TARGET=backup-user@backup.example.com
export PHP_BINARY=php
bash /private/ManaGallery/backup-push.sh
```

Install backup-push.sh, backup-stage.php and backup-status.php beside `app/` in
the private directory, and provision the restricted receiver, keys and known-hosts
as described in the backup documentation. The public root is used only to include
its launcher and web settings in the snapshot. Backups contain private data and
credentials: encrypt them and independently verify restoration.

For a standalone encrypted recovery archive, use
`php scripts/snapshot.php PRIVATE_APP PRIVATE_BACKUP_DIR PASSWORD_FILE`.
The one-time scripts used to migrate our original installation are maintained
privately; they are not required to run ManaGallery on a new host.
## SQLite and logs

Use the default DELETE journal mode on shared or network storage. Set
`sqlite_journal_mode` to `WAL` only when the database is on a local disk.
SQLite WAL does not support network filesystems such as NFS.

Application errors go to the private storage directory's `error_log`. Each
application request has an `X-Gallery-Request-ID` header; error pages show that
reference too. Admin > Site health lists recent referenced errors. Provider
errors that occur before PHP runs must be checked in the hosting control panel.

