FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    bash \
    git \
    libpq \
    postgresql-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .

RUN composer run-script post-autoload-dump --no-interaction

RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD php artisan serve --host=0.0.0.0 --port=8000

