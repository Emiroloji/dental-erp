<?php

namespace App\Domain\Uts\Models;

use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Organizasyonun ÜTS sistem token'ı. Token APP_KEY ile şifreli saklanır ve
 * arayüze/günlüğe/denetim kaydına yazılmaz (bu yüzden Auditable kullanılmaz).
 */
class UtsConnection extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'environment', 'token', 'last_verified_at'];

    protected $hidden = ['token'];

    protected $casts = [
        'token' => 'encrypted',
        'last_verified_at' => 'datetime',
    ];
}
