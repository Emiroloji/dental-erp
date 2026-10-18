<?php

namespace App\Domain\EInvoice\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Faturanın bir kalemi. Organizasyon kapsamı fatura üzerinden gelir.
 */
class EInvoiceLine extends Model
{
    protected $fillable = ['e_invoice_id', 'line_no', 'name', 'seller_code', 'quantity', 'unit_code', 'unit_price', 'line_amount', 'vat_rate', 'product_id'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'integer',
        'line_amount' => 'integer',
        'vat_rate' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(EInvoice::class, 'e_invoice_id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(EInvoiceLineIdentity::class)->orderBy('id');
    }

    /**
     * UBL birim kodu (UN/ECE Rec. 20) → kısa Türkçe ad; bilinmeyen kod olduğu gibi.
     */
    public function unitLabel(): ?string
    {
        return match ($this->unit_code) {
            'C62', 'NIU', 'H87' => 'Adet',
            'BX' => 'Kutu',
            'PK', 'PA' => 'Paket',
            'SET' => 'Set',
            'KGM' => 'kg',
            'GRM' => 'g',
            'LTR' => 'lt',
            'MLT' => 'ml',
            'MTR' => 'm',
            'MTK' => 'm²',
            default => $this->unit_code,
        };
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
