<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Stock\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'purchase_receipt_id',
        'purchase_order_line_id',
        'quantity',
        'lot_no',
        'expiry_date',
        'unit_cost',
        'stock_movement_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'expiry_date' => 'date',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
