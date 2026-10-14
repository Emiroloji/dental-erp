<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Organization\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Finans defterinin bir satırı (karar 1.7). Yalnızca LedgerService yazar;
 * kaydedilmiş satır değiştirilemez ve silinemez — yanlış kayıt ters kayıtla
 * (Reversal) iptal edilir. Defter kendi denetim izi olduğu için Auditable yok.
 */
class FinanceEntry extends Model
{
    use BelongsToOrganization;

    const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'account_id',
        'type',
        'direction',
        'amount',
        'occurred_on',
        'category_id',
        'branch_id',
        'transfer_group',
        'reverses_entry_id',
        'description',
        'document_no',
        'actor_id',
    ];

    protected $casts = [
        'type' => FinanceEntryType::class,
        'direction' => 'integer',
        'amount' => 'integer',
        'occurred_on' => 'date',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Finans hareketi değiştirilemez; ters kayıtla iptal edin.'));
        static::deleting(fn () => throw new LogicException('Finans hareketi silinemez; ters kayıtla iptal edin.'));
    }

    public function signedAmount(): int
    {
        return $this->direction * $this->amount;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Bu kaydı iptal eden ters kayıt (varsa).
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    /**
     * Bu bir ters kayıtsa, iptal ettiği asıl kayıt.
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function scopeNotReversed(Builder $query): void
    {
        $query->whereDoesntHave('reversal');
    }
}
