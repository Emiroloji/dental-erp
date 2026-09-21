<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\Lead;
use App\Domain\Platform\Support\LeadStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Tanıtım sitesi talep kayıtlarının tek giriş noktası (Aşama 29.2).
 *
 * Talep almak hiçbir otomatik sonuç doğurmaz: organizasyon veya kullanıcı
 * hesabı açılmaz, paket atanmaz. Platform Sahibi talebi elle yürütür ve
 * uygun görürse organizasyonu mevcut akışla (Platform paneli) kendisi açar.
 */
class LeadService
{
    /**
     * @param  array{name: string, clinic_name: string, phone?: ?string, email?: ?string, note?: ?string}  $attributes
     */
    public function capture(array $attributes, ?string $ipAddress = null): Lead
    {
        return Lead::create([
            'name' => trim($attributes['name']),
            'clinic_name' => trim($attributes['clinic_name']),
            'phone' => trim((string) ($attributes['phone'] ?? '')) ?: null,
            'email' => trim((string) ($attributes['email'] ?? '')) ?: null,
            'note' => trim((string) ($attributes['note'] ?? '')) ?: null,
            'status' => LeadStatus::New,
            'ip_address' => $ipAddress,
        ]);
    }

    public function updateStatus(Lead $lead, LeadStatus $status, User $owner, ?string $internalNote = null): Lead
    {
        if (! $owner->isPlatformOwner()) {
            throw new AuthorizationException('Gelen talepleri yalnızca Platform Sahibi yönetebilir.');
        }

        $lead->update([
            'status' => $status,
            'handled_by' => $owner->id,
            'handled_at' => now(),
            'internal_note' => trim((string) $internalNote) ?: $lead->internal_note,
        ]);

        return $lead;
    }
}
