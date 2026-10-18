<?php

namespace App\Domain\Uts\Models;

use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Patients\Models\PatientUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ÜTS'ye gönderilen her bildirim denemesi: Alma (Aşama 42), Kullanım ve
 * Kullanım iptali (Aşama 43).
 * Yalnızca eklenir: başarılı ve başarısız denemeler kalıcı kayıttır. Alma
 * bildiriminin ÜTS'de iptali olmadığı için "kim, ne zaman kabul etti"
 * sorusunun cevabı buradadır.
 */
class UtsNotification extends Model
{
    use BelongsToOrganization;

    public const TYPE_RECEIVE = 'alma';

    public const TYPE_USAGE = 'kullanim';

    public const TYPE_USAGE_CANCEL = 'kullanim_iptal';

    public const TYPE_DECLINE = 'almak_istemiyorum';

    public const TYPE_GIVE = 'verme';

    public const TYPE_GIVE_CANCEL = 'verme_iptal';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id', 'user_id', 'patient_usage_id', 'supplier_return_id', 'type', 'source_notification_id', 'uts_notification_id',
        'status', 'environment', 'uno', 'product_name', 'lot', 'serial', 'quantity', 'sender_code',
        'sender_name', 'document_no', 'message',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'sender_code' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function patientUsage(): BelongsTo
    {
        return $this->belongsTo(PatientUsage::class);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_USAGE => 'Kullanım',
            self::TYPE_USAGE_CANCEL => 'Kullanım iptali',
            self::TYPE_DECLINE => 'Almak istemiyorum',
            self::TYPE_GIVE => 'İade (Verme)',
            self::TYPE_GIVE_CANCEL => 'İade iptali',
            default => 'Alma',
        };
    }
}
