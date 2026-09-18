<?php

namespace App\Domain\Stock\Services;

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Domain\Stock\Models\StockLot;
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
     * kurallar.md Bölüm 5: uyarılar, ilgili şube/depoda stok okuma yetkisi
     * olan personele ve Ana Klinik Sahibi'ne (Admin) gönderilir. Admin her
     * uyarıyı alır; personel yalnızca ürünün erişebildiği şubelerden birinde
     * lotu varsa (stok o şubede sıfıra düşmüş olsa bile) bilgilendirilir.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(Product $product): Collection
    {
        $productBranchIds = StockLot::query()
            ->join('warehouses', 'stock_lots.warehouse_id', '=', 'warehouses.id')
            ->where('stock_lots.product_id', $product->id)
            ->distinct()
            ->pluck('warehouses.branch_id')
            ->all();

        return User::where('organization_id', $product->organization_id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->orWhereHas('permissions', function ($permissionQuery) {
                        $permissionQuery->where('module', Module::StockMovement->value)
                            ->where('can_read', true);
                    });
            })
            ->with('permissions.branches')
            ->get()
            ->filter(function (User $user) use ($productBranchIds) {
                $accessible = $user->accessibleBranchIds(Module::StockMovement);

                return $accessible === null || array_intersect($accessible, $productBranchIds) !== [];
            })
            ->values();
    }
}
