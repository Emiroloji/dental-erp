<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\ProductRuleException;
use App\Domain\Catalog\Models\Product;
use App\Domain\Stock\Models\StockLot;

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
