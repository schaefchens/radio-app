# PHP-FPM like the webhosting: PHP 8.5, FPM (so fastcgi_finish_request and the
# Authorization-header passing behave as they do there), the same extensions.
# pdo_sqlite, sodium, curl, mbstring and argon2 are compiled into the official
# image; intl and gd are what the host adds on top.
FROM php:8.5-fpm

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libicu-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev libzip-dev \
      unzip git sqlite3 \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" intl gd zip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-arche.ini

# Mount point of the `var` volume (compose.yaml). A new volume takes this
# directory's owner, so PHP-FPM (www-data) can create the database in it.
RUN mkdir -p /var/www/site/_arche/var && chown www-data:www-data /var/www/site/_arche/var

WORKDIR /srv/server
