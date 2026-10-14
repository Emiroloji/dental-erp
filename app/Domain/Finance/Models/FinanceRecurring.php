<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tekrarlayan kalem tanımı (kira, maaş, abonelik). Kendisi para değildir;
 * günlük iş bundan yükümlülük üretir (RecurringService::generateDue).
 */
class FinanceRecurring extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'direction',
        'name',
        'amount',
        'category_id',
        'party_id',
        'frequency',
        'day',
        'starts_on',
        'ends_on',
        'next_due_on',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'direction' => ObligationDirection::class,
        'frequency' => RecurringFrequency::class,
        'amount' => 'integer',
        'day' => 'integer',
        'starts_on' => 'immutable_date',
        'ends_on' => 'immutable_date',
        'next_due_on' => 'immutable_date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'party_id');
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(FinanceObligation::class, 'recurring_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
