# syntax=docker/dockerfile:1
#
# Dental ERP — canlı imaj (Dokploy). Ayrıntı: docs/dokploy.md
#
# Tek imaj üç rolde çalışır (docker-compose.prod.yml): web (FrankenPHP),
# kuyruk worker'ı ve zamanlayıcı. Rol CONTAINER_ROLE ile seçilir.

# --- Temel: PHP 8.4 + eklentiler + pg_dump/psql + rclone ---------------------
FROM dunglas/frankenphp:1-php8.4-bookworm AS base

# pg_dump sunucu sürümünden eski olamaz; Debian 12'nin istemcisi 15, veritabanı
# 16 olduğu için istemci PostgreSQL'in kendi deposundan kurulur (backup:run).
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg unzip rclone \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-16 \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_pgsql redis gd zip intl bcmath pcntl opcache

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-dental-erp.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# --- PHP bağımlılıkları --------------------------------------------------------
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

# --- Arayüz derlemesi ------------------------------------------------------------
# Tailwind v4 sınıfları kaynak dosyaları tarayarak bulur; bu yüzden kodun tamamı
# (ve sayfalama görünümleri için vendor) derleme aşamasında bulunmalıdır.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# --- Canlı imaj ------------------------------------------------------------------
FROM base AS runner

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan storage:link \
    && mkdir -p /config/psysh \
    && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy /config/psysh \
    && chmod +x docker/entrypoint.sh

USER www-data

EXPOSE 8080

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/app/public"]
