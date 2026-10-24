# Install your own ManaGallery

You do not need an existing gallery, its database, an AI assistant or a cloud API
key. Download this repository and choose **one** application. They provide separate
implementations of the gallery; do not install both to run one site.

| Your situation | Choose |
| --- | --- |
| Shared hosting with PHP, or the simplest server setup | PHP |
| You want to develop a React application and already operate Node services | Next.js |

Both store data on persistent local disk using SQLite. A single conventional
server works; the default setup is not a serverless or multiple-replica deployment.
An AWS EC2 instance, DigitalOcean Droplet or another Linux VM can run the PHP
recipe below. You still need a domain and a server or hosting account.

## 1. Try it on your computer

[Download the ZIP](https://github.com/ManaMarkTea/ManaGallery/archive/refs/heads/main.zip)
and extract it, or run:

```sh
git clone https://github.com/ManaMarkTea/ManaGallery.git
cd ManaGallery
```

### PHP

Install PHP 8.3+ with PDO SQLite, GD (including WebP), mbstring, Sodium and fileinfo.
On Windows, open PowerShell inside `php` and run `./scripts/setup-php.ps1` once;
use `./.tools/php/php.exe` wherever the following instructions say `php`.
On Ubuntu 24.04, the packages are `php-cli php-sqlite3 php-gd php-mbstring`.
PHP's JSON and session support must also be available.

```sh
cd php
php scripts/initialize.php
php -S 127.0.0.1:8081 -t public router.php
```

Open **http://127.0.0.1:8081**. You should see an empty gallery with a Community art
category, not somebody else's artwork. The initializer checks extensions and
refuses to replace existing data. If you skip it, the application shows fictional
demo entries instead. Do not use the built-in PHP server for public hosting.

1. Click Create an account and register your own username and password.
2. In a second terminal, inside `php`, run `php scripts/promote-admin.php YOUR_USERNAME`.
3. Refresh the page. Open Admin, then Settings to set your gallery name and limits.
4. Upload a JPEG, PNG or WebP image. Check the gallery and artwork page.

There are no default accounts or passwords. The first registrant does not become
an administrator automatically. Administrator uploads publish directly; other
members' uploads need approval unless you grant them automatic approval.

Core PHP use needs no Node, Python, Composer or separate database server.
Email notifications are optional and additionally require Composer/PHPMailer,
OpenSSL, an SMTP mailbox and a scheduled task. See [notification setup](../php/docs/comment-notifications.md).

### Next.js

Install Node.js 24 with npm. Python 3 is required for the administrator command.
From the repository root:

```sh
cd nextjs
npm ci
npm run dev
```

Open **http://localhost:3000**, register an account, then run in another terminal:

```sh
cd nextjs
python scripts/promote-admin.py YOUR_USERNAME
```

Refresh the page and open Admin. This version starts with an empty gallery.
For development, that is enough. For hosting, read [Next.js deployment](../nextjs/docs/deployment.md)
and its [configuration requirements](../nextjs/Readme.md#configuration).
`npm run dev` is not the public production server. Do not use the PHP hosting
instructions for the Node application.

## 2. Put PHP on shared hosting

This is a manual installation, not a one-click hosting installer. Your account
needs PHP 8.3+, the extensions above, a private directory outside the document
root, and terminal access for initialization and administrator setup.

1. Upload the `php` directory into a private location, for example
   `/home/YOUR_ACCOUNT/Apps/ManaGallery/app`. Do not upload it as the public root.
2. In the hosting terminal, change into that directory and run `php scripts/initialize.php`.
   Use your host's full PHP executable path if plain `php` is older than 8.3.
3. Create a domain/subdomain with an empty public document root, for example
   `/home/YOUR_ACCOUNT/public_html/gallery`. Enable its SSL certificate.
4. Copy `scripts/shared-host-launcher.php` into that public root as `index.php`.
   Edit its `$app` path, `$canonical` domain and `$allowedHosts` list to your values.
5. Copy the contents of `public/assets` into the public root's `assets` directory.
   On Apache-compatible hosting, copy `scripts/shared-host.htaccess` as `.htaccess`.
   Other web servers require equivalent routing configured by the hosting provider.
6. Ensure the PHP web process can write to private `app/storage`. Set PHP
   `upload_max_filesize = 10M` and `post_max_size = 12M` in the hosting panel.
7. Visit your HTTPS domain, register an account, then run
   `php scripts/promote-admin.php YOUR_USERNAME` inside the private app directory.
8. Set your site name in Admin > Settings and test an upload, sign-in and sign-out.

Keep all of `storage`, config.local.php and backup exports private. Only the
launcher, assets and web settings belong in the public root. Configure backups
before accepting real submissions; hosting-provider backups alone have not been
verified by this application. See [deployment and backups](../php/docs/shared-hosting.md).

## 3. Put PHP on an Ubuntu 24.04 server

Use this path for a single Ubuntu 24.04 VM with a domain pointing to its public IP.
Run commands as your normal SSH user with sudo access. Replace `gallery.example.com`
with your own domain in both the configuration and certificate command.
These commands install Apache; use a fresh VM without another server already
occupying ports 80 and 443. Allow inbound TCP 80/443 in your cloud firewall and
preserve SSH access.

```sh
sudo apt-get update
sudo apt-get install -y git apache2 libapache2-mod-php php-cli php-sqlite3 php-gd php-mbstring certbot python3-certbot-apache
sudo mkdir -p /srv/managallery
sudo chown "$USER":"$(id -gn)" /srv/managallery
git clone https://github.com/ManaMarkTea/ManaGallery.git /srv/managallery
cd /srv/managallery/php
php scripts/initialize.php
sudo chown -R www-data:www-data storage
sudo chmod 700 storage
sudo a2enmod rewrite
```

Create `/etc/apache2/sites-available/managallery.conf` with `sudo nano`:

```apache
<VirtualHost *:80>
    ServerName gallery.example.com
    DocumentRoot /srv/managallery/php/public
    <Directory /srv/managallery/php/public>
        Require all granted
        AllowOverride All
        Options -Indexes
        DirectoryIndex index.php
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [L]
    </Directory>
    php_admin_value upload_max_filesize 10M
    php_admin_value post_max_size 12M
    ErrorLog ${APACHE_LOG_DIR}/managallery-error.log
    CustomLog ${APACHE_LOG_DIR}/managallery-access.log combined
</VirtualHost>
```

Then run:

```sh
sudo a2ensite managallery
sudo apache2ctl configtest
sudo systemctl reload apache2
sudo certbot --apache -d gallery.example.com --redirect
```

When HTTPS works, register your account in the browser. Back in the SSH terminal:

```sh
cd /srv/managallery/php
sudo -u www-data php scripts/promote-admin.php YOUR_USERNAME
```

Refresh the gallery and open Admin > Settings. Test an upload before inviting
members. Record your server/domain details privately. Set up independent encrypted
backups of the SQLite database, catalogue, uploads and local configuration.
Use SQLite's backup mechanism or stop writes while taking a recovery copy; copying
only a running database's main file can lose changes in its WAL journal.

## When something goes wrong

| Symptom | First check |
| --- | --- |
| Initializer reports a missing extension | Enable it for both CLI and web PHP; they may use different settings. |
| Home works but `/register` is 404 | Rewrite rules are not enabled for the public root. |
| Upload fails | GD/WebP, PHP upload/POST limits, private storage permissions and Admin upload settings. |
| Storage cannot be created/written | The web PHP user needs write access to private storage; do not solve this with public access or chmod 777. |
| PHP source downloads instead of running | Stop exposing the site and configure a PHP handler with your host. |
| Blank page or HTTP 500 | Read your hosting PHP error log, or `/var/log/apache2/managallery-error.log` for the VM recipe. |
| No Admin link | Promote the exact registered username using the same application/data directory. |

The PHP version does not yet provide self-service password recovery or passkeys.
Keep a working administrator account and terminal access. Email, Discord,
analytics, remote backups and automated updates are separate optional setup steps;
none is silently enabled by these instructions. Migration tools for the original
installation are not needed or included in the public distribution.
