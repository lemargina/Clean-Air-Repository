FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev unzip curl && docker-php-ext-install pdo_pgsql zip intl bcmath pcntl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN mkdir -p storage/app/imports storage/app/archives storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm"]
