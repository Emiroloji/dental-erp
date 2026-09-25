#!/bin/sh
#
# Dental ERP — konteyner başlangıcı (Dockerfile).
#
# CONTAINER_ROLE=app       → migration + önbellekler, sonra web sunucusu
# CONTAINER_ROLE=worker    → önbellekler, sonra kuyruk worker'ı
# CONTAINER_ROLE=scheduler → önbellekler, sonra zamanlayıcı
#
# Migration yalnızca web konteynerinde çalışır; worker ve zamanlayıcı web
# konteyneri sağlıklı olana kadar başlatılmaz (docker-compose.prod.yml).

set -e

cd /app

# storage kalıcı bir volume'dur; ilk açılışta veya elle temizlendiyse
# Laravel'in beklediği klasörler yeniden oluşturulur.
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    php artisan migrate --force
fi

php artisan optimize

exec "$@"
