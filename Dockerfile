FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libonig-dev libsqlite3-dev libxml2-dev && docker-php-ext-install mbstring pdo_sqlite dom xml xmlwriter pcntl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-interaction --prefer-dist && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
