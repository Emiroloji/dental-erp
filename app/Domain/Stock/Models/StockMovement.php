<?php

namespace App\Domain\Stock\Models;

use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Support\StockMovementType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'type',
        'lot_id',
        'warehouse_id',
        'quantity',
        'actor_id',
        'reason',
        'related_entity_type',
        'related_entity_id',
    ];

    protected $casts = [
        'type' => StockMovementType::class,
        'quantity' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * İptal edilmiş hareketleri dışarıda bırakır. İptal, orijinal hareketi silmez;
     * ona related_entity ile bağlı ters bir Cancel hareketi yazar (kurallar.md
     * Bölüm 2). Giriş/çıkış/kullanım toplamları bu yüzden iptal edilen orijinali
     * saymamalıdır — aksi halde stok geri gelir ama kullanım rakamı düşmez.
     */
    public function scopeWithoutCancelled(Builder $query): void
    {
        $query->whereNotExists(function ($cancellations) use ($query) {
            $cancellations->selectRaw('1')
                ->from('stock_movements as cancellations')
                ->where('cancellations.type', StockMovementType::Cancel->value)
                ->where('cancellations.related_entity_type', self::class)
                ->whereColumn('cancellations.related_entity_id', $query->getModel()->qualifyColumn('id'));
        });
    }

    public function relatedEntity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'related_entity_type', 'related_entity_id');
    }
}
