<?php

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Support\AuditAction;
use Illuminate\Support\Arr;

/**
 * Modelin oluşturma/güncelleme/silme olaylarını audit_logs tablosuna yazar.
 *
 * Gizli (hidden) alanlar ve zaman damgaları hiçbir zaman loglanmaz. Güncellemede
 * yalnızca gerçekten değişen alanlar, önceki ve yeni değerleriyle birlikte yazılır.
 * Kaydı çağıran işlem bir DB::transaction içindeyse log da onunla birlikte geri alınır.
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (self $model) {
            $model->writeAuditLog(AuditAction::Created, null, $model->auditableAttributes());
        });

        static::updated(function (self $model) {
            $changed = array_keys(Arr::except($model->getChanges(), $model->auditExcludedAttributes()));

            if ($changed === []) {
                return;
            }

            $model->writeAuditLog(
                AuditAction::Updated,
                Arr::only($model->auditableOriginal(), $changed),
                Arr::only($model->auditableAttributes(), $changed),
            );
        });

        static::deleted(function (self $model) {
            $model->writeAuditLog(AuditAction::Deleted, $model->auditableOriginal(), null);
        });
    }

    /**
     * Denetim kaydının hangi organizasyona yazılacağı. organization_id kolonu
     * olmayan modeller (ör. Warehouse, Permission) bunu kendi ilişkisinden türetir.
     */
    protected function auditOrganizationId(): ?int
    {
        return $this->organization_id ?? auth()->user()?->organization_id;
    }

    /**
     * @return array<int, string>
     */
    protected function auditExcludedAttributes(): array
    {
        return [...$this->getHidden(), $this->getCreatedAtColumn(), $this->getUpdatedAtColumn()];
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditableAttributes(): array
    {
        return Arr::except($this->attributesToArray(), $this->auditExcludedAttributes());
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditableOriginal(): array
    {
        return Arr::except($this->getOriginal(), $this->auditExcludedAttributes());
    }

    /**
     * Model olaylarının yakalayamadığı değişiklikleri (ör. çoka-çok ilişki
     * senkronizasyonu) elle denetim kaydına yazar.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordAuditChange(array $before, array $after): void
    {
        $this->writeAuditLog(AuditAction::Updated, $before, $after);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function writeAuditLog(AuditAction $action, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'organization_id' => $this->auditOrganizationId(),
            'actor_id' => auth()->id(),
            'entity_type' => $this->getMorphClass(),
            'entity_id' => $this->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
