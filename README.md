# ManaGallery

This MIT-licensed repository contains two independent gallery implementations.
It starts with a clean publication history; private installation history is excluded.
Both implementations are independently runnable and deployable.

| Folder | Contents |
| --- | --- |
| [php/](php/README.md) | PHP application, Midphase deployment, account migration and notification tools |
| [nextjs/](nextjs/Readme.md) | Next.js application, VPS deployment and its own tests |
| [shared/](shared/README.md) | Common stylesheet, comment moderation rules and SVG assets |
| [scripts/](scripts/) | Repository publication checks and shared-file synchronization |

Run application commands from the appropriate application folder. For example:

```sh
cd nextjs
npm ci
npm run dev
```

```sh
cd php
composer install --no-dev --no-plugins --no-scripts
php -S 127.0.0.1:8081 -t public router.php
```

The application READMEs describe PHP extensions, local setup and deployment.
Local databases, artwork archives, uploads, passwords, SMTP credentials and other
runtime data are excluded from Git. A clean checkout uses fictional demo data;
real data stays in the existing private installations and backups.

## Shared files

Edit the canonical file under `shared/`, then run from the repository root:

```sh
python scripts/sync-shared.py
python scripts/sync-shared.py --check
```

Commit the shared source and its generated copies together. Both deployments keep
their own copies so they do not require the other application or a shared runtime
directory on the server. CI rejects differences between shared sources and copies,
checks source/history for private files, and runs each application's full checks.

The applications do **not** share a live database, sessions, configuration, uploads,
authentication implementation or backend code. The PHP migration tools accept the
Next.js project explicitly; from `php/`, the adjacent source is now `../nextjs`.

## Private setup inventory

Copy `ops/inventory.example.json` to an independently stored private inventory.
Keep real server addresses, hosting account names and paths there. Passwords,
private keys, OAuth tokens and webhook credentials belong in a password manager
or server-only configuration. Do not put them in Git, even a public ignored file.

Original project code is MIT-licensed. See `THIRD_PARTY_NOTICES.md` for dependency
notices and `docs/source-distribution.md` for the distribution boundary.
