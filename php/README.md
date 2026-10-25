# ManaGallery - PHP

Start with [the installation guide](../docs/getting-started.md). No migration or existing gallery is required.


[Shared-hosting setup](docs/shared-hosting.md) | [Email notifications](docs/comment-notifications.md)

## Run locally

PHP 8.3+ with JSON, fileinfo, mbstring, PDO SQLite, Sodium and GD with WebP support. The core gallery needs no Composer, database server, Node server or frontend build. Passkeys and authenticated notification mail use Composer dependencies: run `composer install --no-dev --no-plugins --no-scripts` and enable PHP OpenSSL. See [comment notifications](docs/comment-notifications.md) for private SMTP setup. The app creates its own private SQLite database. A fictional 302-artwork collection is built in, so a clean checkout works without real data.

On Windows, run `./scripts/setup-php.ps1` once to install a private portable PHP runtime, then start the local server:
```powershell
./scripts/serve.ps1
```

Open **http://127.0.0.1:8081**. For any machine with PHP installed:

```sh
php -S 127.0.0.1:8081 -t public router.php
```

The built-in server is for local development only. Stop it with Ctrl+C.

## What works

- Gallery, artwork details and shared visual styling.
- Software/category/search/sort filters. Dropdowns update immediately; search waits 350 ms and keeps focus. Native GET forms work without JavaScript.
- 24/48/96 images per page, direct page entry, first/last and +/-1/5/10 page navigation.
- Full-tab image overlay, Escape and close-button dismissal.
- Accounts: registration, password and passkey sign-in, password changes and optional signup email confirmation. See [account security](docs/account-security.md). Argon2id when available, bcrypt otherwise; cookie sessions, session rotation, CSRF checks and bounded login/registration throttles.
- JPEG/PNG/WebP upload decoding and WebP re-encoding, private originals and previews, 10 MiB / 16 megapixel / 12,000 pixel bounds. New uploads await approval; owners/admins can view pending images and edit details. Owners/admins can permanently delete uploaded artwork.
- Admin review queue with quick per-member auto-upload toggles, approval, member access controls, local/UTC date display and activity log. Admins can edit archive metadata and hide artwork. Edits cannot override approval/moderation.
- Default 1 GiB/member and 2 GiB/gallery upload limits, plus 2 GiB reserved disk space. Imported archive media is excluded from quotas. Quotas and registration/upload availability are editable under Admin > Settings.
- Escaped output, CSP, private storage and database outside the document root, media MIME/path/visibility checks. Published thumbnails use a private five-minute browser cache. Pending, hidden and full-size images are not cached. A thumbnail already viewed can remain cached for up to five minutes after being hidden; revalidation checks access again.
- Unique views counted server-side once per account or anonymous browser, without view IP storage. Signing in merges anonymous visits instead of counting them twice; legacy hits remain separate and unverified.
- Favorites with private account collections, verified owned-archive collection and artist gallery filtering. All collections paginate, and hidden artwork respects ownership/admin access.
- Member-only private `.MAT`, `.3DS` and geometry-only `.GLB` downloads with file-size/SHA-256 validation and moderation checks.
- Imported archived comments, new comments and admin moderation with profanity/spam/untrusted-link filtering.

## Current limits

Email password recovery is not available. SMTP notifications and backup tools require separate setup.

## Try accounts and administration

For a fresh installation, register a **new account** at `/register`, then grant it admin from a trusted terminal in this folder:

```powershell
./.tools/php/php.exe scripts/promote-admin.php YOUR_USERNAME
```

Or use `php scripts/promote-admin.php USERNAME` on other systems. Refresh the page to see Admin. No sudo or default admin password is needed; the first registrant is **not** automatically an admin.

The database is `storage/php-gallery.sqlite`; sessions and uploads also stay under private `storage/`. Override with `RENDERGALLERY_PHP_DATA_DIR` only for a separate persistent private directory. Account role, disabled status and credential version are rechecked on each request. Password changes invalidate other sessions. Never expose `storage/` or commit it. Back up SQLite consistently together with uploads before any public trial; see [shared-hosting setup](docs/shared-hosting.md) for optional backup tools.

The local portable PHP config and setup script include the required SQLite/GD extensions and 10/12 MiB upload/POST limits. Restart the PHP server after changing extensions. Shared hosting needs the same extensions and limits. GIFs remain displayable from the archive but are not accepted as new uploads. JPEG auto-orientation using EXIF is not currently implemented.


## Shared hosting layout

Set the subdomain's document root to **this project's `public/` only**. Upload application `src/`, `templates/`, config and private `storage/` outside that document root. Do not upload `.tools/`, test fixtures, Git metadata or private runtime data. Apache-compatible hosts need mod_rewrite/AllowOverride for `public/.htaccess`; `router.php` is local-only. If the panel cannot select a nested document root, put the contents of `public/` in the web root and adjust the two private include paths explicitlyâ€”never expose the entire project.

A shared-hosting deployment uses a private app directory and a separate public launcher/assets directory. Host extensions, SQLite, public routing and HTTPS have been checked. See [deployment notes](docs/shared-hosting.md) before updating code. Configure registration and upload policy under Admin > Settings.

## Checks

```sh
php tests/gallery.php
php tests/accounts.php
```

Optional browser tests use Node only as development tooling; it is not needed to serve the PHP site. From this directory:

```sh
npm ci
npx playwright install chromium
npm test
```

Browser tests use a fresh empty gallery, temporary accounts/images, an isolated server and their own temporary catalogue/database; they don't read or alter imported storage. The existing local server on 8081 can keep showing real artwork.
