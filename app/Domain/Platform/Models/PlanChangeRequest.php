<?php

namespace App\Domain\Platform\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Platform\Support\Plan;
use App\Domain\Platform\Support\PlanChangeStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ana Klinik Sahibi'nin paket yükseltme/düşürme talebi (proje.md Bölüm 12).
 * Organizasyon kapsamlıdır: Admin yalnızca kendi taleplerini görür; Platform
 * Sahibi paneli kapsamı açıkça kaldırarak tüm talepleri listeler.
 */
class PlanChangeRequest extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'current_plan',
        'requested_plan',
        'note',
        'status',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected $casts = [
        'current_plan' => Plan::class,
        'requested_plan' => Plan::class,
        'status' => PlanChangeStatus::class,
        'decided_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isUpgrade(): bool
    {
        $order = array_flip(array_map(fn (Plan $plan) => $plan->value, Plan::cases()));

        return $order[$this->requested_plan->value] > $order[$this->current_plan->value];
    }
}
