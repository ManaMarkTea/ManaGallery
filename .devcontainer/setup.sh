#!/bin/sh
set -eu
cd "$(dirname "$0")/../php"
composer install --no-interaction --no-plugins --no-scripts
npm ci
if [ ! -e "$RENDERGALLERY_PHP_CATALOGUE" ] && [ ! -e "$RENDERGALLERY_PHP_DATA_DIR/php-gallery.sqlite" ]; then
    php scripts/initialize.php
fi
