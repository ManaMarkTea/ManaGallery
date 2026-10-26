#!/bin/sh
set -eu
if [ "$1" = apache2-foreground ]; then
    umask 077
    mkdir -p "$RENDERGALLERY_PHP_DATA_DIR"
    chown www-data:www-data "$RENDERGALLERY_PHP_DATA_DIR"
    chmod 700 "$RENDERGALLERY_PHP_DATA_DIR"
    mkdir -p /var/www/gallery/public/previews
    chown www-data:www-data /var/www/gallery/public/previews
    chmod 755 /var/www/gallery/public/previews
    catalogue="$RENDERGALLERY_PHP_CATALOGUE"
    database="$RENDERGALLERY_PHP_DATA_DIR/php-gallery.sqlite"
    if [ ! -e "$catalogue" ] && [ ! -e "$database" ]; then
        gosu www-data php scripts/initialize.php
    elif [ ! -f "$catalogue" ] || [ ! -f "$database" ]; then
        echo 'Incomplete gallery data. Check the data volume before starting.' >&2
        exit 1
    fi
    if gosu www-data php -r 'require "src/gallery.php"; exit((config()["public_preview_directory"] ?? "") === "" ? 1 : 0);'; then
        gosu www-data php scripts/publish-previews.php
    fi
fi
exec docker-php-entrypoint "$@"
