<?php

namespace App\Domain\Stock\Services;

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Domain\Stock\Notifications\StockLevelAlert;
use App\Domain\Stock\Support\StockLevel;
use App\Models\User;
use Illuminate\Support\Collection;

class StockAlertService
{
    public function __construct(private readonly StockLevelService $levels) {}

    /**
     * Tüm aktif ürünleri (veya tek bir organizasyonu) tarar, Düşük/Kritik
     * seviyeye düşmüş her ürün için ilgili Admin ve yetkili personele bildirim
     * oluşturur. Zaten aynı seviyede okunmamış bir bildirimi olan alıcı tekrar
     * bilgilendirilmez (spam engeli); seviye kötüleşirse (ör. Düşük -> Kritik)
     * yeni bir bildirim oluşturulur.
     *
     * @return int Gönderilen bildirim sayısı
     */
    public function scan(?Organization $organization = null): int
    {
        $notified = 0;

        Product::query()
            ->where('status', 'active')
            ->when($organization, fn ($query) => $query->where('organization_id', $organization->id))
            ->chunkById(100, function (Collection $products) use (&$notified) {
                foreach ($products as $product) {
                    $notified += $this->assessAndNotify($product);
                }
            });

        return $notified;
    }

    private function assessAndNotify(Product $product): int
    {
        $assessment = $this->levels->assess($product);

        if ($assessment['level'] === StockLevel::Normal) {
            return 0;
        }

        $sent = 0;

        foreach ($this->recipientsFor($product) as $recipient) {
            if ($this->alreadyNotifiedAtThisLevel($recipient, $product, $assessment['level'])) {
                continue;
            }

            $recipient->notify(new StockLevelAlert(
                $product,
                $assessment['level'],
                $assessment['quantity'],
                $assessment['reasons'],
            ));

            $sent++;
        }

        return $sent;
    }

    private function alreadyNotifiedAtThisLevel(User $recipient, Product $product, StockLevel $level): bool
    {
        return $recipient->unreadNotifications()
            ->where('type', StockLevelAlert::class)
            ->where('data->product_id', $product->id)
            ->where('data->level', $level->value)
            ->exists();
    }

    /**
     * kurallar.md Bölüm 5: uyarılar, ilgili modülde bildirim/okuma yetkisi
     * işaretlenmiş personele ve Ana Klinik Sahibi'ne (Admin) gönderilir.
     * Personel yetki kapsamı (own_branch/selected_branches/all) bugün hiçbir
     * ekranda veri filtrelemesi için kullanılmıyor (bkz. kod incelemesi); bu
     * yüzden burada da tutarlı davranılır: stok_movement.read yetkisi olan
     * her personel, kapsamından bağımsız olarak bilgilendirilir.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(Product $product): Collection
    {
        return User::where('organization_id', $product->organization_id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->orWhereHas('permissions', function ($permissionQuery) {
                        $permissionQuery->where('module', Module::StockMovement->value)
                            ->where('can_read', true);
                    });
            })
            ->get();
    }
}
