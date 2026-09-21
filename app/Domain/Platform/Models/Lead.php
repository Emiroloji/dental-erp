<?php

namespace App\Domain\Platform\Models;

use App\Domain\Platform\Support\LeadStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tanıtım sitesindeki talep formundan gelen kayıt (fazlar-adimlar.md Aşama 29.2).
 *
 * Tenant kapsamı yoktur (BelongsToOrganization kullanılmaz): talep henüz bir
 * müşteriye ait değildir, yalnızca Platform Sahibi görür. Denetim kaydı da
 * tutulmaz; audit_logs organizasyon bazlı bir defterdir, talep ise
 * organizasyon öncesi bir kayıttır.
 */
class Lead extends Model
{
    protected $fillable = [
        'name',
        'clinic_name',
        'phone',
        'email',
        'note',
        'status',
        'ip_address',
        'handled_by',
        'handled_at',
        'internal_note',
    ];

    protected $casts = [
        'status' => LeadStatus::class,
        'handled_at' => 'datetime',
    ];

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** Listede tek satırda gösterilen iletişim bilgisi. */
    public function contactLine(): string
    {
        return collect([$this->phone, $this->email])->filter()->join(' · ');
    }
}
