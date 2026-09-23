<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\ProductRuleException;
use App\Domain\Catalog\Models\Product;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Support\AlertMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProductService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Product
    {
        $this->ensureValidTracking($attributes);

        return Product::create([...$attributes, 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes): Product
    {
        $this->ensureValidTracking($attributes);

        // Seri takibi stok varken açılıp kapatılamaz: açılırsa mevcut birimlerin
        // seri numarası olmaz, kapatılırsa kayıtlı seriler anlamsızlaşır
        // (lot miktarı = stoktaki seri sayısı değişmezi bozulur).
        if (array_key_exists('tracks_serials', $attributes)
            && (bool) $attributes['tracks_serials'] !== $product->tracks_serials
            && StockLot::where('product_id', $product->id)->where('quantity', '>', 0)->exists()) {
            throw new ProductRuleException('Stoğu olan bir üründe seri takibi açılıp kapatılamaz. Önce stoğu sıfırlayın.');
        }

        $product->update($attributes);

        return $product;
    }

    /**
     * Aşama 30 — toplu uyarı eşiği atama.
     *
     * Eşik ürün ürün girildiği için, ürün sayısı arttıkça pratikte hiç
     * girilmiyordu ve özellik kullanılmadan kalıyordu. Bu yöntem aynı eşiği bir
     * kategorinin (veya tüm kataloğun) aktif ürünlerine uygular.
     *
     * Ürünler tek tek update edilir, toplu bir UPDATE sorgusuyla değil: Product
     * modeli Auditable ve denetim kaydının her ürün için yazılması gerekiyor;
     * ayrıca salt-okunur organizasyon koruması da Eloquent yazmaları üzerinden
     * çalışıyor (Aşama 18).
     *
     * @param  int|null  $categoryId  null = tüm aktif ürünler
     * @param  array<string, mixed>  $rule  alert_mode ve eşik alanları; alert_mode null ise eşikler temizlenir
     * @param  bool  $overwriteCustom  false ise yalnızca kendi eşiği olmayan ürünlere uygulanır
     * @return int güncellenen ürün sayısı
     */
    public function applyAlertRule(?int $categoryId, array $rule, bool $overwriteCustom): int
    {
        $mode = $rule['alert_mode'] === null ? null : AlertMode::from($rule['alert_mode']);

        $attributes = [
            'alert_mode' => $mode?->value,
            'alert_quantity_low' => $mode?->tracksQuantity() ? (float) $rule['alert_quantity_low'] : null,
            'alert_quantity_critical' => $mode?->tracksQuantity() ? (float) $rule['alert_quantity_critical'] : null,
            'alert_expiry_low_days' => $mode?->tracksExpiry() ? (int) $rule['alert_expiry_low_days'] : null,
            'alert_expiry_critical_days' => $mode?->tracksExpiry() ? (int) $rule['alert_expiry_critical_days'] : null,
        ];

        $updated = 0;

        DB::transaction(function () use ($categoryId, $mode, $overwriteCustom, $attributes, &$updated) {
            $this->alertRuleTargets($categoryId, $mode, $overwriteCustom)
                ->chunkById(200, function ($products) use ($attributes, &$updated) {
                    foreach ($products as $product) {
                        $product->update($attributes);
                        $updated++;
                    }
                });
        });

        return $updated;
    }

    /**
     * Toplu atamanın kaç ürünü etkileyeceği — onay ekranında gösterilir, böylece
     * kullanıcı "8 ürün" mü "800 ürün" mü değiştireceğini görerek onaylar.
     */
    public function alertRuleTargetCount(?int $categoryId, ?AlertMode $mode, bool $overwriteCustom): int
    {
        return $this->alertRuleTargets($categoryId, $mode, $overwriteCustom)->count();
    }

    /**
     * @return Builder<Product>
     */
    private function alertRuleTargets(?int $categoryId, ?AlertMode $mode, bool $overwriteCustom): Builder
    {
        return Product::query()
            ->where('status', 'active')
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            // Eşik temizleniyorsa yalnızca kendi eşiği olan ürünlere dokunulur;
            // aksi hâlde "üzerine yazma" kapalıyken eşiği olmayanlar hedeftir.
            ->when($mode === null, fn ($query) => $query->whereNotNull('alert_mode'))
            ->when($mode !== null && ! $overwriteCustom, fn ($query) => $query->whereNull('alert_mode'));
    }

    public function deactivate(Product $product): Product
    {
        $product->update(['status' => 'passive']);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ensureValidTracking(array $attributes): void
    {
        if (! ($attributes['cold_chain'] ?? false)) {
            return;
        }

        $min = $attributes['storage_min_temp'] ?? null;
        $max = $attributes['storage_max_temp'] ?? null;

        if ($min === null || $max === null) {
            throw new ProductRuleException('Soğuk zincir ürününde saklama sıcaklık aralığı (en düşük ve en yüksek) girilmelidir.');
        }

        if ((float) $min > (float) $max) {
            throw new ProductRuleException('Saklama sıcaklığının en düşük değeri en yüksek değerden büyük olamaz.');
        }
    }
}
