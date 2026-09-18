<?php

namespace App\Domain\Returns\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Returns\Support\ReturnStatus;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Tedarikçiye iade (proje.md Bölüm 10). "Return" PHP'de ayrılmış bir kelime
 * olduğu için model SupplierReturn; tablo belgedeki adıyla "returns".
 */
class SupplierReturn extends Model
{
    use Auditable, BelongsToOrganization;

    protected $table = 'returns';

    protected $fillable = [
        'organization_id',
        'stock_lot_id',
        'product_id',
        'warehouse_id',
        'supplier_id',
        'purchase_order_id',
        'invoice_number',
        'quantity',
        'reason',
        'reason_note',
        'status',
        'supplier_response',
        'credit_note_number',
        'credit_amount',
        'requested_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'reason' => ReturnReason::class,
        'status' => ReturnStatus::class,
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupplierReturnEvent::class, 'return_id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'relatedEntity', 'related_entity_type', 'related_entity_id');
    }

    public function number(): string
    {
        return 'IA-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function reasonText(): string
    {
        return $this->reason->label().(filled($this->reason_note) ? " — {$this->reason_note}" : '');
    }

    /**
     * @param  array<int, int>|null  $branchIds  null = kısıt yok
     */
    public function scopeInBranches(Builder $query, ?array $branchIds): void
    {
        if ($branchIds !== null) {
            $query->whereIn('warehouse_id', Warehouse::query()->select('id')->whereIn('branch_id', $branchIds));
        }
    }
}
