<?php

namespace App\Domain\Finance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir hesabın banka/kasa ekstresiyle karşılaştırılmasının kaydı. system_balance
 * ve difference, mutabakat anında hesaplanıp yazılan TARİHSEL karşılaştırma
 * kaydıdır — saklanan bakiye değildir; güncel bakiye daima FinanceBalanceService'ten
 * türetilir ve bu alanlar ondan okunmaz.
 */
class FinanceReconciliation extends Model
{
    use Auditable, BelongsToOrganization;

    const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'account_id',
        'statement_date',
        'statement_balance',
        'system_balance',
        'difference',
        'adjustment_entry_id',
        'actor_id',
        'note',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'statement_balance' => 'integer',
        'system_balance' => 'integer',
        'difference' => 'integer',
        'created_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(FinanceEntry::class, 'adjustment_entry_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
