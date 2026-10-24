# ManaGallery

A PHP community art gallery with uploads, comments, favorites and an admin panel.
Free to use and modify under the MIT license.

**[Installation guide](docs/getting-started.md)** — local setup, shared hosting and Ubuntu servers.

Requires PHP 8.3+ and SQLite. For a local trial, install the
[required extensions](docs/getting-started.md), then run:

```sh
cd php
php scripts/initialize.php
php -S 127.0.0.1:8081 -t public router.php
```

Open http://127.0.0.1:8081, create an account, then grant it admin access:

```sh
php scripts/promote-admin.php YOUR_USERNAME
```

The built-in server is for local use. Follow the installation guide to put it online.

## Documentation

- [PHP features and development](php/README.md)
- [Shared hosting and updates](php/docs/shared-hosting.md)
- [Email and Discord notifications](php/docs/comment-notifications.md)
- [Dependency licenses](THIRD_PARTY_NOTICES.md)

Keep databases, uploads, credentials and local configuration out of Git.
Only `php/public/` belongs in a web server's public document root.

The old Next.js version is [archived separately](https://github.com/ManaMarkTea/ManaGallery-Nextjs)
and is no longer maintained.
