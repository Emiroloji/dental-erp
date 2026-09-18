<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Organization\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bir teslim alma işlemi (fatura/irsaliye ile). Oluşan stok girişleri bu kayda
 * bağlanır; kayıt da siparişe → tedarikçiye bağlıdır (proje.md Bölüm 9).
 */
class PurchaseReceipt extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'purchase_order_id',
        'warehouse_id',
        'invoice_number',
        'delivery_note_number',
        'document_path',
        'document_name',
        'received_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class);
    }
}
