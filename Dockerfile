FROM php:8.5-apache-trixie AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends gosu libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/gallery
COPY php/composer.json php/composer.lock ./
RUN composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist
COPY php/config.example.php ./
COPY php/src/ src/
COPY php/templates/ templates/
COPY php/public/ public/
COPY php/scripts/ scripts/
COPY docker/config.php config.local.php
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/gallery.ini
COPY docker/entrypoint.sh /usr/local/bin/gallery-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/gallery-entrypoint \
    && chmod 755 /usr/local/bin/gallery-entrypoint
ENV RENDERGALLERY_PHP_DATA_DIR=/var/lib/managallery \
    RENDERGALLERY_PHP_CATALOGUE=/var/lib/managallery/gallery.json \
    RENDERGALLERY_PHP_MEDIA_ROOT=/var/lib/managallery/archive
ENTRYPOINT ["gallery-entrypoint"]
CMD ["apache2-foreground"]

FROM runtime AS development
RUN apt-get update && apt-get install -y --no-install-recommends git python3 \
    && rm -rf /var/lib/apt/lists/*
