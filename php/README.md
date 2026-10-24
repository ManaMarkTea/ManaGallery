# RenderGallery gallery / RenderGallery PHP

The standalone PHP implementation shares styling and moderation rules with the
adjacent Next.js version. This distribution includes fictional demo data, not a live
installation or its accounts, media, logs or deployment inventory. See
[shared-hosting setup](docs/midphase.md) and [notifications](docs/comment-notifications.md).

## Run locally

PHP 8.3+ with JSON, fileinfo, mbstring, PDO SQLite, Sodium and GD with WebP support. The core gallery needs no Composer, database server, Node server or frontend build. Authenticated notification mail additionally uses PHPMailer: run `composer install --no-dev --no-plugins --no-scripts` and enable PHP OpenSSL. See [comment notifications](docs/comment-notifications.md) for private SMTP setup. The app creates its own private SQLite database. A fictional 302-artwork collection is built in, so a clean checkout works without real data.

On this Windows workstation a checksum-verified, portable PHP 8.5.12 runtime is already available in ignored `.tools/php/`. On another Windows workstation, run `./scripts/setup-php.ps1` once to prepare it (official PHP download with a pinned SHA-256, no global installation):

```powershell
./scripts/serve.ps1
```

Open **http://127.0.0.1:8081**. For any machine with PHP installed:

```sh
php -S 127.0.0.1:8081 -t public router.php
```

The built-in server is for local development only. Stop it with Ctrl+C.

## Try the local archive

```sh
python scripts/import-next-archive.py ../nextjs
```

This creates ignored `storage/gallery.json` with only public artwork metadata and an ignored `config.local.php` pointing to the Next.js public image archive **for read-only access**. It never reads accounts, password hashes, sessions, secrets or the SQLite database, and never writes to the Next.js folder. To get back to the fictional demo, move `storage/gallery.json` aside.

The snapshot does not include current database moderation/metadata overrides, newer uploads, comments or software edits. Do not publish an imported snapshot without reconciling those changes. It is not an account migration and is not synchronized with the live site.

For an independent copy of archive images, add `--copy-media`. This uses extra disk space and may take a few minutes. Existing configuration is preserved, so update `media_root` to this project's `storage/archive` if a config already exists.

## What works

- Gallery, artwork details and shared visual styling.
- Software/category/search/sort filters. Dropdowns update immediately; search waits 350 ms and keeps focus. Native GET forms work without JavaScript.
- 24/48/96 images per page, direct page entry, first/last and ±1/5/10 page navigation.
- Full-tab image overlay, Escape and close-button dismissal.
- Separate prototype accounts: registration, password sign-in/out and password changes. Argon2id when available, bcrypt otherwise; cookie sessions, session rotation, CSRF checks and bounded login/registration throttles.
- JPEG/PNG/WebP upload decoding and WebP re-encoding, private originals and previews, 10 MiB / 16 megapixel / 12,000 pixel bounds. New uploads await approval; owners/admins can view pending images and edit details. Owners/admins can permanently delete uploaded artwork.
- Admin review queue with quick per-member auto-upload toggles, approval, member access controls, local/UTC date display and activity log. Admins can edit archive metadata and hide artwork. Edits cannot override approval/moderation.
- Default 1 GiB/member and 2 GiB/gallery upload limits, plus 2 GiB reserved disk space. Imported archive media is excluded from quotas. Quotas and registration/upload availability are editable under Admin > Settings.
- Escaped output, CSP, private storage and database outside the document root, media MIME/path/visibility checks. Pending and hidden image URLs are protected too; image responses are not cached.
- Unique views counted server-side once per account or anonymous browser, without view IP storage. Signing in merges anonymous visits instead of counting them twice; legacy hits remain separate and unverified.
- Favorites with private account collections, verified owned-archive collection and artist gallery filtering. All collections paginate, and hidden artwork respects ownership/admin access.
- Member-only private `.MAT`, `.3DS` and geometry-only `.GLB` downloads with file-size/SHA-256 validation and moderation checks.
- Imported archived comments, new comments and admin moderation with profanity/spam/untrusted-link filtering.

## Not ported yet

Passkeys, email verification/recovery, AdSense, backup/restore and production deployment. Existing gallery credentials work after the controlled import below. Archived artist names are reserved against registration; no ownership is inferred by matching a display name. Do not point this prototype at the live SQLite database.

## Try accounts and administration

After importing existing accounts, sign in with your existing password; admin status and verified archive ownership are preserved. For a clean installation without imported accounts, register a **new prototype account** at `/register`, then grant it admin from a trusted terminal in this folder:

```powershell
./.tools/php/php.exe scripts/promote-admin.php YOUR_PROTOTYPE_USERNAME
```

Or use `php scripts/promote-admin.php USERNAME` on other systems. Refresh the page to see Admin. No sudo or default admin password is needed; the first registrant is **not** automatically an admin.

The database is `storage/php-gallery.sqlite`; sessions and uploads also stay under private `storage/`. Override with `RENDERGALLERY_PHP_DATA_DIR` only for a separate persistent private directory. Account role, disabled status and credential version are rechecked on each request. Password changes invalidate other sessions. Never expose `storage/` or commit it. Back up SQLite consistently together with uploads before any public trial; automated PHP backup tools have not been ported yet.

The local portable PHP config and setup script include the required SQLite/GD extensions and 10/12 MiB upload/POST limits. Restart the PHP server after changing extensions. Shared hosting needs the same extensions and limits. GIFs remain displayable from the archive but are not accepted as new uploads. This milestone does not yet auto-orient JPEGs using EXIF.

## Import existing gallery accounts

From this folder, preview the adjacent Next.js database first:

```sh
python scripts/import-next-accounts.py ../nextjs
python scripts/import-next-accounts.py ../nextjs --apply
```

You can instead pass an explicit private source SQLite path. `--php` selects a PHP CLI when the portable Windows runtime or `php` is unavailable. Python 3.9+ is needed only for this administrative import, not for serving the site.

The importer reads a consistent, **read-only** Next.js snapshot, backs up the PHP database under private `storage/backups/`, and copies account verifiers, effective roles/disabled/auto-upload policies, verified artwork identity links and existing archive metadata/moderation overrides. It does not copy source sessions, passkeys, IPs or plaintext passwords. Source files are never modified. Imported names and aliases are reserved; unrelated prototype name collisions abort the import transaction instead of linking accounts.

PHP Sodium verifies the Node scrypt format with the same fixed N=16384/r=8/p=1 parameters and 32-character salt. Original legacy gallery MD5/salted-MD5 passwords are supported only for this migration, including Latin-1 fallback. On the first successful password sign-in, PHP creates the linked account with the imported role and rehashes the supplied password using its modern algorithm, then clears the copied legacy verifier. It does not weaken new account password requirements.

Re-importing preserves upgraded PHP passwords, verified account links, PHP admin policy changes and local artwork edits. Newly disabled source accounts also disable the corresponding migrated PHP account. Ambiguous names/changed identity mappings fail closed.

To restore the original gallery's trusted direct-upload groups, include its private SQL backup:

```sh
python scripts/import-next-accounts.py ../nextjs --legacy-dump /private/original-backup.sql --apply
```

Only selected group/category permission rows are parsed as data; legacy SQL is never executed. Administrators default to auto approval; active, non-expired direct-upload group grants are mapped to the new per-member trust setting. Explicit Next.js approval values and explicit PHP admin toggles take precedence, including an explicit off value. Merely having an active account or a previously published image does not grant trust. Re-import repairs the old missing-default bug for already-migrated PHP accounts too. This maps the original category-specific trust to the gallery's single trusted-uploader setting.

The one-time `php scripts/repair-approval.php --apply` tool publishes pending uploads belonging to restored, enabled trusted imports, excluding artwork hidden by moderation. It is not run by normal auto-upload toggles: those continue to affect **future** uploads only.

For archived conversations, use:

```sh
python scripts/import-next-comments.py ../nextjs --apply
```

This copies comment content, verified author identities, existing hold reasons and deletion flags. It does not copy contact data or IP addresses. PHP comment edits/moderation/deletion tombstones are preserved on re-import. New comments are checked for profanity/spam, untrusted links are held, and owners/admins can delete comments. Admin > Comments provides search, approval, holds and deletion. Original comments only become owned by a PHP user after password-verified migration.

Admin now includes separate review, artwork, member, comment, category, settings, activity and health pages. Members include unclaimed imports, role/access/trust controls, dates/activity, contribution counts and name-review filtering. Categories can be renamed/reordered or deleted when empty. Site health reports actual PHP storage/extensions and explicitly marks PHP backups as not configured. This is a **snapshot migration**, not live account synchronization: a password or approval-policy change made in PHP does not change Next.js, and changing a source password does not overwrite an already-migrated PHP password. Completed migrations use their PHP password through both canonical usernames and imported aliases. Passkeys need separate migration/re-enrollment later.

## Restore member downloads

```sh
python scripts/import-next-downloads.py ../nextjs
python scripts/import-next-downloads.py ../nextjs --apply
```

This copies checked `.MAT` and `.3DS` files and optional GLB derivatives into private `storage/attachments/`. Original names are only used in download headers; file paths are fixed numeric IDs. Guest downloads require sign-in, and hidden/pending artwork checks still apply. Bytes and SHA-256 are checked on every download, including HEAD; corrupted content returns an error rather than being sent. GLB derivatives must match the original source checksum. Files stay outside the public document root. Preset compatibility is not guaranteed by successful transport; GLBs preserve geometry/basic colors, not external textures or animation.

Next.js favorites for imported archive IDs are included by the account importer. They are applied after password-verified identity linking (or immediately for already-linked PHP users), and removal in PHP stays removed on re-import. Favorites of source uploads are not imported because those uploaded images have not been migrated here. View history starts separately in PHP; legacy counters are never presented as new unique views. Anonymous counts can recur after cookie clearing or changing browsers.

See [port status](docs/parity.md) for completed and remaining features.

## Shared hosting layout

Set the subdomain's document root to **this project's `public/` only**. Upload application `src/`, `templates/`, config and private `storage/` outside that document root. Do not upload `.tools/`, test fixtures, Git metadata or the Next.js database. Apache-compatible hosts need mod_rewrite/AllowOverride for `public/.htaccess`; `router.php` is local-only. If the panel cannot select a nested document root, put the contents of `public/` in the web root and adjust the two private include paths explicitly—never expose the entire project.

The Midphase deployment uses a private app directory and a separate public launcher/assets directory. Host extensions, SQLite, public routing and HTTPS have been checked. See [deployment notes](docs/midphase.md) before updating code. The site is deliberately noindex and new registration starts closed on the host.

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

Browser tests use the fictional demo, temporary accounts/images, an isolated server and their own temporary catalogue/database; they don't read or alter imported storage. The existing local server on 8081 can keep showing real artwork.
