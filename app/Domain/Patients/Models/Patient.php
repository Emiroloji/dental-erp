<?php

namespace App\Domain\Patients\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Concerns\BelongsToOrganization;
use App\Domain\Patients\Support\IdentityType;
use App\Domain\Patients\Support\PatientIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ÜTS Kullanım bildirimi için en az veriyle tutulan hasta kaydı (Aşama 43):
 * ad, soyad, kimlik. Tedavi/hasta dosyası değildir. Kimlik numarası şifreli
 * ve gizlidir; denetim kaydına, listelere ve günlüğe düz yazılmaz.
 */
class Patient extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = ['organization_id', 'first_name', 'last_name', 'identity_type', 'identity_no', 'identity_hash', 'birth_year'];

    protected $hidden = ['identity_no', 'identity_hash'];

    protected $casts = [
        'identity_type' => IdentityType::class,
        'identity_no' => 'encrypted',
        'birth_year' => 'integer',
    ];

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function maskedIdentity(): string
    {
        return $this->identity_no === null
            ? IdentityType::Unknown->label()
            : PatientIdentity::mask($this->identity_no);
    }

    /**
     * Ad/soyadda geçen ya da kimlik numarasıyla tam eşleşen hastalar.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->whereLike('first_name', "%{$term}%")
            ->orWhereLike('last_name', "%{$term}%")
            ->orWhere('identity_hash', PatientIdentity::hash($term)));
    }
}
