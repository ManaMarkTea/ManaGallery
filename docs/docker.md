# Run with Docker

Install Docker Desktop (Windows/macOS) or Docker Engine with Compose (Linux).
Start Docker, then run this from the ManaGallery repository folder:

```sh
docker compose up --build -d
```

The first build takes a few minutes. Open **http://localhost:8080**, create an
account, then grant it admin access:

```sh
docker compose exec --user www-data web php scripts/promote-admin.php YOUR_USERNAME
```

Refresh the page and open Admin. PHP, Apache and the required extensions are
included in the image.

## Stop and update

```sh
docker compose down
```

To update a Git checkout:

```sh
git pull
docker compose up --build -d
```

Accounts, images and sessions stay in the `gallery-data` Docker volume when you
stop or rebuild. Keep using the same project name and Compose file location.
Deleting the volume erases the gallery. Keep a separate backup of your data.

To read the server log:

```sh
docker compose logs --tail 100 web
```

If port 8080 is busy, change its value in compose.yaml or set `MANAGALLERY_PORT`
before starting Compose. The default port is bound to this computer only.

## Public hosting

This setup runs Apache over HTTP. For a public site, put it behind an HTTPS
reverse proxy and configure PHP to recognize HTTPS from that trusted proxy.
That is required for secure cookies and form-origin checks. Keep the app port
private and back up the data volume independently. The
[Ubuntu setup](getting-started.md#ubuntu-2404-server) is another option with
Apache and HTTPS configured directly on the host.

Email notifications are not scheduled automatically. SMTP settings can be stored
in `/var/lib/managallery/config.local.php` inside the data volume; the file must
be readable by `www-data`. Keep passwords out of the image and Compose file.

## Development in VS Code

1. Install Docker, VS Code and its Dev Containers extension.
2. Open the ManaGallery folder in VS Code.
3. Run **Dev Containers: Reopen in Container** from the command palette.
4. In the container terminal, run:

```sh
php -S 0.0.0.0:8081 -t public router.php
```

Open the forwarded port 8081. Register an account, then use another container
terminal to run `php scripts/promote-admin.php YOUR_USERNAME`.

The dev container includes PHP, Composer, Node and Python. Your source folder is
mounted for editing. Development data uses its own volume and does not share the
Compose gallery's accounts or uploads.

Run the PHP checks in the container with:

```sh
php tests/gallery.php
php tests/accounts.php
```

For browser tests, install Chromium once with
`npx playwright install --with-deps chromium`, then run `npm test`.

## Automated checks

GitHub runs the Docker build and dev-container checks weekly on Mondays at
10:00 UTC. To run them sooner, open Actions > Gallery checks > Run workflow.
PHP and source checks still run on pushes and pull requests.
