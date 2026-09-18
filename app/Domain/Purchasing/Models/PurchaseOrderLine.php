<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'quantity',
        'unit_price',
        'received_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'received_quantity' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * kurallar.md Bölüm 1: teslim alınan miktar sipariş miktarından düşülür,
     * kalan açık sipariş olarak görünür.
     */
    public function remaining(): float
    {
        return max(0.0, (float) $this->quantity - (float) $this->received_quantity);
    }
}
