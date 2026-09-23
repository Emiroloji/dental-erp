#!/usr/bin/env bash
#
# Dental ERP — dağıtım betiği (Aşama 30, ek).
#
# Sunucuda çalıştırılır:
#   sudo /var/www/dental-erp/deploy/deploy.sh
#
# Yereldeki bilgisayardan tek komutla:
#   ssh root@<sunucu> /var/www/dental-erp/deploy/deploy.sh
#
# Yaptığı iş docs/production.md'deki "Dağıtım adımları" bölümünün aynısıdır;
# elle yapılınca bir adımın atlanması kolay olduğu için betiğe alınmıştır.
#
# Tasarım notları:
# - Herhangi bir adım başarısız olursa betik orada durur (set -e) ve uygulama
#   bakım modundan çıkarılır; yarım kurulumla açık kalmaz.
# - Bakım modu migration'dan ÖNCE açılır: şema değişirken gelen istek, eski
#   koda göre yazılmış sorgularla hataya düşebilir.
# - composer install --no-dev: geliştirme bağımlılıkları canlıya kurulmaz.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/dental-erp}"
cd "$APP_DIR"

echo "==> Mevcut sürüm: $(git rev-parse --short HEAD)"

# Hata olursa ne olursa olsun bakım modundan çık.
cleanup() {
    if [ -f storage/framework/maintenance.php ]; then
        echo "==> Bakım modundan çıkılıyor (hata sonrası)"
        php artisan up || true
    fi
}
trap cleanup ERR

echo "==> Bakım moduna alınıyor"
php artisan down --render="errors::503" --retry=15 || php artisan down --retry=15

echo "==> Kod güncelleniyor"
git fetch origin --quiet
git reset --hard origin/main --quiet
echo "    yeni sürüm: $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

echo "==> PHP bağımlılıkları"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

echo "==> Arayüz derleniyor"
npm ci --silent --no-audit --no-fund
npm run build --silent

echo "==> Veritabanı şeması"
php artisan migrate --force

echo "==> Önbellekler"
php artisan optimize

echo "==> Dosya izinleri"
chown -R www-data:www-data storage bootstrap/cache
# .env www-data tarafından okunabilmeli; okunamazsa Laravel sessizce
# varsayılanlara (SQLite) düşer ve site yanlış veritabanıyla açılır.
chown root:www-data .env && chmod 640 .env

echo "==> Kuyruk worker'ları yenileniyor"
php artisan queue:restart

echo "==> Bakım modundan çıkılıyor"
php artisan up
trap - ERR

echo "==> Sağlık kontrolü"
sleep 2
APP_URL_VALUE="$(php artisan tinker --execute='echo config("app.url");' 2>/dev/null | tail -1)"
HEALTH="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${APP_URL_VALUE}/health" || echo 000)"

if [ "$HEALTH" = "200" ]; then
    echo "==> TAMAM — /health 200 döndü, dağıtım başarılı."
else
    echo "==> DİKKAT — /health $HEALTH döndü. storage/logs/laravel.log kontrol edilmeli." >&2
    exit 1
fi
