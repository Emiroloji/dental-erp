<?php

namespace App\Domain\EInvoice\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Support\Gs1;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\EInvoice\Models\EInvoiceLine;
use App\Domain\Purchasing\Models\PurchaseReceipt;

/**
 * Gelen faturayı kliniğin kayıtlarıyla eşleştirir (Aşama 45): tedarikçi
 * (VKN/TCKN), satın alma teslimi (fatura no + tedarikçi) ve kalem ürünü
 * (GTIN/UNO → ürün GTIN'i ya da barkodu; yoksa satıcı kodu → ürün kodu).
 * Yalnızca boş bağları doldurur; elle yapılan eşleşmeyi ezmez.
 *
 * Eşitleme kuyrukta oturumsuz çalışır ve oturum yokken organizasyon kapsamı
 * filtre uygulamaz; bu yüzden her sorgu organizasyonu açıkça verir.
 */
class EInvoiceMatcher
{
    public function match(EInvoice $invoice): void
    {
        $organizationId = $invoice->organization_id;

        if ($invoice->supplier_id === null && filled($invoice->sender_tax_number)) {
            $invoice->supplier_id = Supplier::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereRaw("replace(tax_number, ' ', '') = ?", [preg_replace('/\s+/', '', $invoice->sender_tax_number)])
                ->orderByRaw("case when status = 'active' then 0 else 1 end")
                ->value('id');
        }

        if ($invoice->purchase_receipt_id === null && $invoice->supplier_id !== null) {
            $invoice->purchase_receipt_id = PurchaseReceipt::query()
                ->whereNull('cancelled_at')
                ->where('invoice_number', $invoice->invoice_number)
                ->whereHas('order', fn ($order) => $order->withoutGlobalScopes()
                    ->where('organization_id', $organizationId)
                    ->where('supplier_id', $invoice->supplier_id))
                ->latest('id')
                ->value('id');
        }

        $invoice->save();

        foreach ($invoice->lines()->with('identities')->whereNull('product_id')->get() as $line) {
            if ($productId = $this->productFor($organizationId, $line)) {
                $line->update(['product_id' => $productId]);
            }
        }
    }

    private function productFor(int $organizationId, EInvoiceLine $line): ?int
    {
        $products = fn () => Product::withoutGlobalScopes()->where('organization_id', $organizationId);

        foreach ($line->identities->pluck('gtin')->filter()->unique() as $code) {
            $gtin = Gs1::normalizeGtin($code);
            $id = $products()
                ->where(fn ($query) => $query->where('barcode', $code)->when($gtin, fn ($query) => $query->orWhere('gtin', $gtin)->orWhere('barcode', $gtin)))
                ->value('id');

            if ($id) {
                return $id;
            }
        }

        return filled($line->seller_code)
            ? $products()->where('code', $line->seller_code)->value('id')
            : null;
    }
}
