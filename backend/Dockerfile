FROM composer:2 AS composer
FROM postgres:17-bookworm AS pgtools
FROM php:8.4.27-apache-bookworm
RUN apt-get update && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libpng-dev libwebp-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev postgresql-client unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j2 pdo_pgsql intl zip gd mbstring bcmath pcntl opcache \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && a2dismod status \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && printf '<VirtualHost *:8080>\nDocumentRoot /var/www/html/public\n<Directory /var/www/html/public>\nAllowOverride All\nOptions -Indexes\nRequire all granted\n</Directory>\n</VirtualHost>\n' > /etc/apache2/sites-available/000-default.conf \
    && printf 'ServerTokens Prod\nServerSignature Off\nTraceEnable Off\nTimeout 60\nKeepAliveTimeout 2\n<IfModule mpm_prefork_module>\nStartServers 2\nMinSpareServers 1\nMaxSpareServers 2\nMaxRequestWorkers 3\nMaxConnectionsPerChild 500\n</IfModule>\n' > /etc/apache2/conf-enabled/production.conf \
    && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nsession.upload_progress.enabled=0\nmemory_limit=192M\nupload_max_filesize=12M\npost_max_size=16M\nmax_execution_time=60\nopcache.enable=1\nopcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/production.ini
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=pgtools /usr/lib/postgresql/17/bin/pg_dump /usr/local/bin/pg_dump
COPY --from=pgtools /usr/lib/postgresql/17/bin/pg_restore /usr/local/bin/pg_restore
COPY --from=pgtools /usr/lib/x86_64-linux-gnu/libpq.so.5* /usr/lib/x86_64-linux-gnu/
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public bootstrap/cache /var/backups/boostedone/tmp \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache /var/backups/boostedone
RUN apt-mark manual libpq5 libicu72 libzip4 libpng16-16 libwebp7 libjpeg62-turbo libfreetype6 libonig5 liblz4-1 libzstd1 libssl3 zlib1g libxml2 libsqlite3-0 libcurl4 ca-certificates \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS linux-libc-dev libc6-dev gcc-12 g++-12 libpq-dev libicu-dev libzip-dev libpng-dev libwebp-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev postgresql-client postgresql-client-15 postgresql-client-common unzip \
    && rm -rf /var/lib/apt/lists/* \
    && php -r 'exit(extension_loaded("pdo_pgsql") && extension_loaded("gd") && extension_loaded("zip") && extension_loaded("sodium") ? 0 : 1);' \
    && /usr/local/bin/pg_dump --version
USER www-data
EXPOSE 8080
CMD ["apache2-foreground"]
