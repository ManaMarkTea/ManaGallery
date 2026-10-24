# Install ManaGallery

Requires PHP 8.3+ with PDO SQLite, GD/WebP, mbstring, Sodium, fileinfo and sessions.
Your gallery data is stored in SQLite and local files.

## Try it locally

[Download the ZIP](https://github.com/ManaMarkTea/ManaGallery/archive/refs/heads/main.zip)
and extract it, or run:

```sh
git clone https://github.com/ManaMarkTea/ManaGallery.git
cd ManaGallery
```

On Windows, open PowerShell inside `php` and run `./scripts/setup-php.ps1` once;
use `./.tools/php/php.exe` wherever the following instructions say `php`.
On Ubuntu 24.04, the packages are `php-cli php-sqlite3 php-gd php-mbstring`.
PHP's JSON and session support must also be available.

```sh
cd php
php scripts/initialize.php
php -S 127.0.0.1:8081 -t public router.php
```

Open **http://127.0.0.1:8081**. The gallery starts empty.
`initialize.php` checks your PHP extensions and creates the database and catalogue.
It won't overwrite an existing installation.

1. Click Create an account and register your own username and password.
2. In a second terminal, inside `php`, run `php scripts/promote-admin.php YOUR_USERNAME`.
3. Refresh the page. Open Admin, then Settings to set your gallery name and limits.
4. Upload a JPEG, PNG or WebP image. Check the gallery and artwork page.

Admin uploads publish immediately. Other members' uploads wait for approval
unless you give them automatic approval in Admin.

For email notifications, install Composer dependencies and configure an SMTP
mailbox and scheduled task. See [email setup](../php/docs/comment-notifications.md).

## Shared hosting

You'll need terminal access and a private folder outside your website's public
root. Select PHP 8.3 or newer in your hosting panel.

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

## Ubuntu 24.04 server

These steps work with a fresh Ubuntu 24.04 VM, such as an EC2 instance or
DigitalOcean Droplet. Point your domain at the server and allow ports 80 and 443
in its firewall. Keep SSH access enabled.

Connect over SSH as a user with sudo access. Replace `gallery.example.com` with
your domain. These commands install Apache; they assume ports 80 and 443 are free.

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

Password recovery and passkeys aren't available yet. Keep your administrator
password and SSH access somewhere safe. Email, Discord, analytics and remote
backups each need their own setup.
