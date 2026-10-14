<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kasa, banka veya POS hesabı. Bakiye saklanmaz: açılış bakiyesi + defter
 * (FinanceBalanceService). Hesap silinmez, pasife alınır (karar 1.5).
 */
class FinanceAccount extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'type',
        'iban',
        'opening_balance',
        'opening_date',
        'currency',
        'status',
    ];

    protected $attributes = [
        'currency' => 'TRY',
        'status' => 'active',
    ];

    protected $casts = [
        'type' => AccountType::class,
        'opening_balance' => 'integer',
        'opening_date' => 'date',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(FinanceEntry::class, 'account_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
