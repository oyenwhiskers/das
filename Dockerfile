# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions \
        bcmath \
        gd \
        intl \
        pcntl \
        pdo_mysql \
        redis \
        zip

COPY docker/php/production.ini /usr/local/etc/php/conf.d/99-production.ini

FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

COPY . .

RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && rm -f bootstrap/cache/*.php \
    && composer dump-autoload --optimize --no-dev --no-interaction --no-scripts \
    && php artisan package:discover --ansi \
    && php artisan vendor:publish --tag=laravel-assets --ansi --force

FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./

# shortcut: root optional @laravel/multiplex is missing from the Windows lock; drop only that entry so Linux native optional bindings still install. Switch back to npm ci after the lock is regenerated on Linux.
RUN node -e 'const fs=require("fs"); const p=JSON.parse(fs.readFileSync("package.json","utf8")); delete p.optionalDependencies; fs.writeFileSync("package.json", JSON.stringify(p));' \
    && npm install --no-audit --no-fund

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY app/Livewire ./app/Livewire
COPY public ./public
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views

RUN npm run build

FROM base AS runtime

WORKDIR /app

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV SERVER_NAME=":80"

EXPOSE 80

STOPSIGNAL SIGTERM

# entrypoint.sh migrates, starts 5 queue workers, then FrankenPHP
ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
