<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Support\ProductType;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Stock\Support\AlertMode;
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
        'gtin',
        'uts_number',
        'license_number',
        'manufacturer',
        'storage_condition',
        'cold_chain',
        'storage_min_temp',
        'storage_max_temp',
        'is_controlled',
        'tracks_serials',
        'base_unit',
        'conversion_rules',
        'purchase_price',
        'min_stock',
        'max_stock',
        'alert_mode',
        'alert_quantity_low',
        'alert_quantity_critical',
        'alert_expiry_low_days',
        'alert_expiry_critical_days',
        'product_type',
        'status',
    ];

    protected $casts = [
        'conversion_rules' => 'array',
        'purchase_price' => 'decimal:2',
        'min_stock' => 'integer',
        'max_stock' => 'integer',
        'alert_mode' => AlertMode::class,
        'alert_quantity_low' => 'float',
        'alert_quantity_critical' => 'float',
        'alert_expiry_low_days' => 'integer',
        'alert_expiry_critical_days' => 'integer',
        'product_type' => ProductType::class,
        'cold_chain' => 'boolean',
        'storage_min_temp' => 'float',
        'storage_max_temp' => 'float',
        'is_controlled' => 'boolean',
        'tracks_serials' => 'boolean',
    ];

    /**
     * Ürünün kendi uyarı modu; seçilmemişse miktar bazlı sabit varsayılan
     * geçerlidir (fazlar-adimlar.md Aşama 29.1).
     */
    public function alertMode(): AlertMode
    {
        return $this->alert_mode ?? AlertMode::Quantity;
    }

    /** Ürün kartında kendi eşiği tanımlı mı, yoksa organizasyon varsayılanı mı kullanılıyor. */
    public function hasCustomAlertRule(): bool
    {
        return $this->alert_mode !== null;
    }

    /**
     * Uyarı kuralının insan okunur özeti — ürün listesinde ve stok ekranlarında
     * hangi eşiğin geçerli olduğunu göstermek için. "İkisi birden" modunda iki
     * eksen de tek satırda görünür.
     */
    public function alertRuleLabel(): string
    {
        if (! $this->hasCustomAlertRule()) {
            return 'Varsayılan eşik (son '.(int) config('stock.levels.low_quantity_threshold').' adet sarı / son '.(int) config('stock.levels.critical_quantity_threshold').' adet kırmızı)';
        }

        $mode = $this->alertMode();
        $format = fn (?float $value) => $value === null ? '—' : rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');

        $parts = [];

        if ($mode->tracksQuantity()) {
            $parts[] = "{$format($this->alert_quantity_low)} {$this->base_unit} altında sarı, {$format($this->alert_quantity_critical)} {$this->base_unit} altında kırmızı";
        }

        if ($mode->tracksExpiry()) {
            $parts[] = "SKT'ye {$this->alert_expiry_low_days} gün kala sarı, {$this->alert_expiry_critical_days} gün kala kırmızı";
        }

        return implode(' · ', $parts);
    }

    /**
     * Soğuk zincir ürününde ölçülen sıcaklık saklama aralığında mı (Aşama 26).
     */
    public function temperatureInRange(float $celsius): bool
    {
        return (! $this->cold_chain)
            || (($this->storage_min_temp === null || $celsius >= $this->storage_min_temp)
                && ($this->storage_max_temp === null || $celsius <= $this->storage_max_temp));
    }

    public function storageRangeLabel(): ?string
    {
        if (! $this->cold_chain) {
            return null;
        }

        $format = fn (?float $value) => $value === null ? '…' : rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');

        return "{$format($this->storage_min_temp)}–{$format($this->storage_max_temp)} °C";
    }

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
