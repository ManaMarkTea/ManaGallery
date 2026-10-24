# Shared-hosting deployment

The gallery needs PHP 8.3+, PDO SQLite, GD, Sodium, mbstring and fileinfo. SMTP
additionally needs OpenSSL and the locked Composer dependencies.

Keep the application, config.local.php, database, uploads and archive outside the
public document root. Publish only the launcher, rewrite rules and static assets.
Configure your own allowed hostnames and canonical HTTPS origin in the launcher.

The supplied CLI tools use a private ~/rendergallery-php/app directory and a
~/public_html_sub/rendergallery-php public directory. Review these paths against
your hosting layout before using the installation/deployment tools.

Copy config.example.php to config.local.php privately and set your own paths,
notification origin and sender. Never commit that file. Initial migration packages
contain private records and must stay outside the public document root. Code-only
updates must preserve storage and local configuration.

See comment-notifications.md for SMTP setup and the five-minute scheduled worker.
SSH crontab access may be unavailable; use the hosting control panel when needed.
Keep backup status logs private and verify restoration independently.
