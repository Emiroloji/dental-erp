<?php

namespace App\Domain\EInvoice\Models;

use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Organizasyonun e-Fatura entegratörü bağlantısı. API anahtarı APP_KEY ile
 * şifreli saklanır; arayüze, günlüğe ve denetim kaydına yazılmaz (bu yüzden
 * Auditable kullanılmaz).
 */
class EInvoiceConnection extends Model
{
    use BelongsToOrganization;

    public const PROVIDERS = ['nilvera' => 'Nilvera'];

    protected $fillable = ['organization_id', 'provider', 'environment', 'api_key', 'last_verified_at', 'last_synced_at'];

    protected $hidden = ['api_key'];

    protected $casts = [
        'api_key' => 'encrypted',
        'last_verified_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];
}
