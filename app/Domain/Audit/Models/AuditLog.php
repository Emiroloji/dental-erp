<?php

namespace App\Domain\Audit\Models;

use App\Domain\Access\Models\Permission;
use App\Domain\Audit\Support\AuditAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    use BelongsToOrganization;

    const UPDATED_AT = null;

    public const ENTITY_LABELS = [
        Product::class => 'Ürün',
        Category::class => 'Kategori',
        Supplier::class => 'Tedarikçi',
        User::class => 'Personel',
        Permission::class => 'Yetki',
        Branch::class => 'Şube',
        Warehouse::class => 'Depo',
    ];

    protected $fillable = [
        'organization_id',
        'actor_id',
        'entity_type',
        'entity_id',
        'action',
        'before',
        'after',
    ];

    protected $casts = [
        'action' => AuditAction::class,
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Denetim kayıtları değiştirilemez.'));
        static::deleting(fn () => throw new LogicException('Denetim kayıtları silinemez.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function entityLabel(): string
    {
        return self::ENTITY_LABELS[$this->entity_type] ?? class_basename($this->entity_type);
    }

    public function entityName(): string
    {
        return $this->after['name'] ?? $this->before['name'] ?? '#'.$this->entity_id;
    }

    /**
     * Ekranda gösterilecek alanlar: güncellemede yalnızca değişenler,
     * oluşturma/silmede kaydın tamamı.
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function changes(): array
    {
        $keys = array_unique([...array_keys($this->before ?? []), ...array_keys($this->after ?? [])]);

        return collect($keys)
            ->reject(fn (string $key) => $key === 'id')
            ->mapWithKeys(fn (string $key) => [$key => [
                'before' => $this->before[$key] ?? null,
                'after' => $this->after[$key] ?? null,
            ]])
            ->all();
    }

    public static function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Evet' : 'Hayır',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
