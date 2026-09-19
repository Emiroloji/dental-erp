<?php

namespace App\Domain\Stock\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Stock\Support\SerialStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Seri takipli ürünün tek bir birimi (Aşama 26). Yalnızca StockMovementService
 * tarafından oluşturulur ve güncellenir.
 */
class StockSerial extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'product_id',
        'lot_id',
        'serial_no',
        'status',
    ];

    protected $casts = [
        'status' => SerialStatus::class,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function movements(): BelongsToMany
    {
        return $this->belongsToMany(StockMovement::class, 'stock_movement_serials', 'stock_serial_id', 'stock_movement_id');
    }
}
