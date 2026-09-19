<?php

namespace App\Domain\Stock\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\Gs1;
use App\Domain\Stock\Exceptions\ScanException;
use App\Domain\Stock\Models\StockLot;

/**
 * Barkod/QR çözümleme (Faz 3 — Aşama 20). Okutulan metin sırasıyla:
 *  1. Sistemin bastığı lot etiketi   → DERP:L:{lot id}   (ürün + lot)
 *  2. Sistemin bastığı ürün etiketi  → DERP:P:{ürün id}
 *  3. GS1 DataMatrix / GS1-128 (ÜTS'li ürünler, Aşama 26) → GTIN ile ürün;
 *     içindeki lot, SKT ve seri no da döner (girişte forma dolar).
 *  4. Ürün kartındaki barkod, GTIN veya ürün kodu (üreticinin EAN/Code128 barkodu).
 *
 * Organizasyon izolasyonu model kapsamlarından gelir (Product
 * BelongsToOrganization); lot etiketi ayrıca kullanıcının stok şube kapsamında
 * olmalıdır. Aynı barkod birden fazla aktif üründe kayıtlıysa tahmin yapılmaz.
 */
class ScanResolver
{
    public const PRODUCT_PREFIX = 'DERP:P:';

    public const LOT_PREFIX = 'DERP:L:';

    /**
     * @param  array<int, int>|null  $branchIds  Kullanıcının stok kapsamı (null = kısıt yok)
     * @return array{product: Product, lot: ?StockLot, gs1: ?array{gtin: string, lot_no: ?string, expiry_date: ?string, serial: ?string}}
     */
    public function resolve(string $scanned, ?array $branchIds): array
    {
        $code = trim($scanned);

        if ($code === '') {
            throw new ScanException('Okutulan kod boş.');
        }

        if (preg_match('/^'.preg_quote(self::LOT_PREFIX, '/').'(\d+)$/', $code, $match)) {
            $lot = StockLot::whereHas('product')->inBranches($branchIds)->with('product', 'warehouse.branch')->find((int) $match[1]);

            if (! $lot) {
                throw new ScanException('Bu lot etiketi bulunamadı ya da erişiminiz olan bir depoya ait değil.');
            }

            $this->ensureActive($lot->product);

            return ['product' => $lot->product, 'lot' => $lot, 'gs1' => null];
        }

        if (preg_match('/^'.preg_quote(self::PRODUCT_PREFIX, '/').'(\d+)$/', $code, $match)) {
            $product = Product::find((int) $match[1]);

            if (! $product) {
                throw new ScanException('Bu ürün etiketi bulunamadı.');
            }

            $this->ensureActive($product);

            return ['product' => $product, 'lot' => null, 'gs1' => null];
        }

        if ($gs1 = Gs1::parse($code)) {
            $product = Product::where('gtin', $gs1['gtin'])->first();

            if (! $product) {
                throw new ScanException("GTIN {$gs1['gtin']} ile kayıtlı bir ürün yok. Ürün kartına GTIN'i ekleyin.");
            }

            $this->ensureActive($product);

            return ['product' => $product, 'lot' => null, 'gs1' => $gs1];
        }

        $gtin = Gs1::normalizeGtin($code);

        $matches = Product::where('status', 'active')
            ->where(fn ($query) => $query->where('barcode', $code)->orWhere('code', $code)
                ->when($gtin, fn ($query) => $query->orWhere('gtin', $gtin)))
            ->orderBy('name')
            ->limit(5)
            ->get();

        if ($matches->isEmpty()) {
            throw new ScanException("\"{$code}\" barkodu/koduyla kayıtlı aktif bir ürün yok. Ürün kartına barkodu ekleyin.");
        }

        if ($matches->count() > 1) {
            throw new ScanException("\"{$code}\" birden fazla üründe kayıtlı ({$matches->pluck('name')->join(', ')}). Ürün kartlarındaki barkodu düzeltin.");
        }

        return ['product' => $matches->first(), 'lot' => null, 'gs1' => null];
    }

    public static function productPayload(Product $product): string
    {
        return self::PRODUCT_PREFIX.$product->id;
    }

    public static function lotPayload(StockLot $lot): string
    {
        return self::LOT_PREFIX.$lot->id;
    }

    private function ensureActive(Product $product): void
    {
        if ($product->status !== 'active') {
            throw new ScanException("\"{$product->name}\" pasif bir ürün; stok işlemi yapılamaz.");
        }
    }
}
