<?php

namespace App\Domain\Uts\Models;

use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ÜTS'ye gönderilen her bildirim denemesi (Aşama 42; şimdilik yalnızca Alma).
 * Yalnızca eklenir: başarılı ve başarısız denemeler kalıcı kayıttır. Alma
 * bildiriminin ÜTS'de iptali olmadığı için "kim, ne zaman kabul etti"
 * sorusunun cevabı buradadır.
 */
class UtsNotification extends Model
{
    use BelongsToOrganization;

    public const TYPE_RECEIVE = 'alma';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id', 'user_id', 'type', 'source_notification_id', 'uts_notification_id',
        'status', 'environment', 'uno', 'lot', 'serial', 'quantity', 'sender_code',
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
}
