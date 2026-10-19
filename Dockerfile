FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libxml2-dev libsqlite3-dev unzip git \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring dom xml xmlwriter pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/uploads.ini
WORKDIR /app
COPY . .
RUN composer install --no-interaction --prefer-dist --no-progress
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
