<?php

namespace App\Domain\Organization\Support;

use App\Domain\Organization\Exceptions\ReadOnlyOrganizationException;
use App\Domain\Reporting\Models\ReportExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Salt-okunur organizasyon koruması (Aşama 18). Oturumdaki kullanıcının
 * organizasyonu salt-okunursa, Eloquent üzerinden yapılan her ekleme,
 * güncelleme ve silme reddedilir — hangi ekran veya servis çağırırsa çağırsın
 * (stok hareketi, sayım, sipariş, personel...). Ekranlar ayrıca yazma
 * butonlarını gizler (Gate), bu sınıf son savunma hattıdır.
 *
 * İstisnalar: bildirimi okundu işaretlemek, kullanıcının kendi oturum/şifre
 * alanları (giriş yapabilmek, geçici şifreyi değiştirebilmek için) ve rapor
 * dışa aktarım kaydı (rapor almak bir okuma işlemidir; salt-okunur modda da
 * raporlar görülebilir ve indirilebilir).
 */
class ReadOnlyGuard
{
    private const OWN_ACCOUNT_FIELDS = ['remember_token', 'password', 'must_change_password', 'updated_at'];

    public function check(Model $model): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->inReadOnlyOrganization()) {
            return;
        }

        if ($model instanceof DatabaseNotification || $model instanceof ReportExport) {
            return;
        }

        if ($model instanceof User && $model->exists && $model->is($user)
            && array_diff(array_keys($model->getDirty()), self::OWN_ACCOUNT_FIELDS) === []) {
            return;
        }

        throw new ReadOnlyOrganizationException;
    }
}
