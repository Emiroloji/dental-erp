<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Support\ProductType;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'category_id',
        'supplier_id',
        'name',
        'code',
        'barcode',
        'base_unit',
        'conversion_rules',
        'purchase_price',
        'min_stock',
        'max_stock',
        'product_type',
        'status',
    ];

    protected $casts = [
        'conversion_rules' => 'array',
        'purchase_price' => 'decimal:2',
        'min_stock' => 'integer',
        'max_stock' => 'integer',
        'product_type' => ProductType::class,
    ];

    /**
     * Giriş/çıkışta seçilebilen birimler: ana birim + ürün kartındaki
     * dönüşüm birimleri (kurallar.md Bölüm 3). Kayıt her zaman ana birimle yapılır.
     *
     * @return array<int, string>
     */
    public function unitOptions(): array
    {
        return [$this->base_unit, ...collect($this->conversion_rules ?? [])->pluck('unit')->all()];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
