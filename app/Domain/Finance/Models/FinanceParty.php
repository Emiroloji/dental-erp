<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cari hesap: kurum veya firma (hasta açılmaz — karar 2.6, 2.9). Bakiye
 * saklanmaz; açık yükümlülüklerin kalanından türetilir (FinanceBalanceService).
 */
class FinanceParty extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'kind',
        'phone',
        'email',
        'tax_number',
        'default_term_days',
        'note',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'kind' => PartyKind::class,
        'default_term_days' => 'integer',
    ];

    public function obligations(): HasMany
    {
        return $this->hasMany(FinanceObligation::class, 'party_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinanceEntry::class, 'party_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
