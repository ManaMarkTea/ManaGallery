# ManaGallery

A PHP community art gallery with uploads, comments, favorites and an admin panel.
Free to use and modify under the MIT license.

**[Installation guide](docs/getting-started.md)** — local setup, shared hosting and Ubuntu servers.

## Quick start with Docker

```sh
docker compose up --build -d
```

Open http://localhost:8080 and register an account. Then run:

```sh
docker compose exec --user www-data web php scripts/promote-admin.php YOUR_USERNAME
```

See [Docker setup](docs/docker.md) for updates, data storage and the optional
VS Code dev container. Prefer a normal PHP installation? Use the
[installation guide](docs/getting-started.md).

## Documentation

- [PHP features and development](php/README.md)
- [Shared hosting and updates](php/docs/shared-hosting.md)
- [Email and Discord notifications](php/docs/comment-notifications.md)
- [Dependency licenses](THIRD_PARTY_NOTICES.md)

Keep databases, uploads, credentials and local configuration out of Git.
Only `php/public/` belongs in a web server's public document root.

The old Next.js version is [archived separately](https://github.com/ManaMarkTea/ManaGallery-Nextjs)
and is no longer maintained.
