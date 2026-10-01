<?php

namespace App\Domain\Uts\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\Gs1;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockSerial;

/**
 * ÜTS kaydını yerel veriyle eşleştirir. Hiçbir şey yazmaz. Organizasyon
 * izolasyonu: Product ve StockSerial BelongsToOrganization kapsamındadır;
 * StockLot ürün üzerinden, PurchaseReceipt sipariş üzerinden kapsanır.
 */
class UtsMatcher
{
    /**
     * @return array{product: ?Product, lot: ?StockLot, serial: ?StockSerial, receipt: ?PurchaseReceipt}
     */
    public function match(string $uno, ?string $lot, ?string $serial, ?string $documentNo = null): array
    {
        $gtin = Gs1::normalizeGtin($uno);

        $product = Product::query()
            ->where(fn ($query) => $query
                ->where('barcode', $uno)
                ->when($gtin, fn ($query) => $query->orWhere('gtin', $gtin)))
            ->first();

        $lotModel = $product && $lot
            ? StockLot::where('product_id', $product->id)->where('lot_no', $lot)->first()
            : null;

        $serialModel = $product && $serial
            ? StockSerial::where('product_id', $product->id)->where('serial_no', $serial)->first()
            : null;

        $receipt = $documentNo
            ? PurchaseReceipt::whereHas('order')
                ->where(fn ($query) => $query->where('invoice_number', $documentNo)->orWhere('delivery_note_number', $documentNo))
                ->latest('id')
                ->first()
            : null;

        return ['product' => $product, 'lot' => $lotModel, 'serial' => $serialModel, 'receipt' => $receipt];
    }
}
