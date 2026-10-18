<?php

namespace App\Domain\Patients\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Uts\Models\UtsNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hastaya kullanılan ÜTS takipli ürün (Aşama 43): bir stok çıkış hareketinin
 * hastaya bağlanmış hali. Seri takipli üründe her seri ayrı satırdır.
 * Çıkış hareketi iptal edilirse cancelled_at dolar.
 */
class PatientUsage extends Model
{
    use BelongsToOrganization;

    public const UTS_NONE = 'none';

    public const UTS_SENT = 'sent';

    public const UTS_FAILED = 'failed';

    public const UTS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id', 'patient_id', 'stock_movement_id', 'product_id', 'stock_lot_id',
        'stock_serial_id', 'quantity', 'used_on', 'user_id', 'cancelled_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'used_on' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(StockSerial::class, 'stock_serial_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function utsNotifications(): HasMany
    {
        return $this->hasMany(UtsNotification::class)->orderBy('id');
    }

    /**
     * ÜTS'deki durum, bildirim kayıtlarının sırasından çıkar: gönderilmedi,
     * bildirildi, gönderilemedi ya da iptal edildi.
     */
    public function utsStatus(): string
    {
        $status = self::UTS_NONE;

        foreach ($this->utsNotifications as $notification) {
            $sent = $notification->status === UtsNotification::STATUS_SENT;

            $status = match (true) {
                $notification->type === UtsNotification::TYPE_USAGE && $sent => self::UTS_SENT,
                $notification->type === UtsNotification::TYPE_USAGE && $status !== self::UTS_SENT => self::UTS_FAILED,
                $notification->type === UtsNotification::TYPE_USAGE_CANCEL && $sent => self::UTS_CANCELLED,
                default => $status,
            };
        }

        return $status;
    }

    /** ÜTS'de geçerli kullanım bildiriminin kodu (bildirilmiş ve iptal edilmemişse). */
    public function reportedCode(): ?string
    {
        return $this->utsStatus() === self::UTS_SENT
            ? $this->utsNotifications->last(fn ($n) => $n->type === UtsNotification::TYPE_USAGE && $n->status === UtsNotification::STATUS_SENT)?->uts_notification_id
            : null;
    }

    public function utsStatusLabel(): string
    {
        return match ($this->utsStatus()) {
            self::UTS_SENT => 'ÜTS\'ye bildirildi',
            self::UTS_FAILED => 'ÜTS\'ye gönderilemedi',
            self::UTS_CANCELLED => 'ÜTS\'de iptal edildi',
            default => 'ÜTS\'ye gönderilmedi',
        };
    }
}
