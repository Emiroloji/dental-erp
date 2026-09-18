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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
