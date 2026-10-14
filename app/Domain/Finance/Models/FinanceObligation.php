<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vadeli borç veya alacak ("beklenen para"). Ödenen tutar saklanmaz: bağlı
 * payment kayıtları ve iptallerinden türetilir. status, ödeme/iptal sonrası
 * LedgerService'in defterden yeniden hesapladığı bir özettir.
 */
class FinanceObligation extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'direction',
        'party_id',
        'category_id',
        'amount',
        'due_on',
        'description',
        'document_no',
        'recurring_id',
        'branch_id',
        'status',
        'cancel_reason',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected $casts = [
        'direction' => ObligationDirection::class,
        'status' => ObligationStatus::class,
        'amount' => 'integer',
        'due_on' => 'date',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'party_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(FinanceRecurring::class, 'recurring_id');
    }

    /**
     * Bu yükümlülüğe bağlı defter kayıtları (ödemeler ve onların iptalleri).
     */
    public function payments(): HasMany
    {
        return $this->hasMany(FinanceEntry::class, 'obligation_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
