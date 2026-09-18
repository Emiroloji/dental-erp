<?php

namespace App\Domain\Platform\Services;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Exceptions\PlanLimitException;
use App\Domain\Platform\Support\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * Paket limitleri (proje.md Bölüm 12, Faz 3): şube, kullanıcı, depolama.
 *
 * Kullanım: aktif şube sayısı, aktif kullanıcı sayısı (Admin + personel) ve
 * yüklenen belgelerin toplam boyutu. Limit yalnızca YENİ eklemeyi engeller;
 * paket düşürüldüğünde mevcut kayıtlar silinmez veya pasife alınmaz, kullanım
 * limitin altına inene kadar yeni ekleme yapılamaz (Faz 3 kullanıcı kararı).
 */
class PlanLimitService
{
    /**
     * @return array{branches: int, users: int, storage_mb: float}
     */
    public function usage(Organization $organization): array
    {
        return [
            'branches' => Branch::withoutGlobalScopes()->where('organization_id', $organization->id)->where('status', 'active')->count(),
            'users' => User::where('organization_id', $organization->id)->where('status', 'active')->whereIn('role', [User::ROLE_ADMIN, User::ROLE_STAFF])->count(),
            'storage_mb' => round($this->storageBytes($organization) / 1048576, 2),
        ];
    }

    public function ensureCanAddBranch(Organization $organization): void
    {
        $limit = $organization->limits()['branches'];
        $used = $this->usage($organization)['branches'];

        if ($limit !== null && $used >= $limit) {
            throw new PlanLimitException("Paket limitine ulaşıldı: {$used}/{$limit} aktif şube ({$organization->plan->label()} paketi). Yeni şube açmak veya pasif bir şubeyi aktifleştirmek için paketinizi yükseltin ya da başka bir şubeyi pasife alın.");
        }
    }

    public function ensureCanAddUser(Organization $organization): void
    {
        $limit = $organization->limits()['users'];
        $used = $this->usage($organization)['users'];

        if ($limit !== null && $used >= $limit) {
            throw new PlanLimitException("Paket limitine ulaşıldı: {$used}/{$limit} aktif kullanıcı ({$organization->plan->label()} paketi). Yeni personel eklemek için paketinizi yükseltin.");
        }
    }

    public function ensureCanStore(Organization $organization, int $bytes): void
    {
        $limitMb = $organization->limits()['storage_mb'];

        if ($limitMb === null) {
            return;
        }

        $usedBytes = $this->storageBytes($organization);

        if ($usedBytes + $bytes > $limitMb * 1048576) {
            throw new PlanLimitException('Depolama limiti aşılıyor: '.Number::format($usedBytes / 1048576, precision: 1).' MB / '.Number::format($limitMb, precision: 0)." MB kullanılıyor ({$organization->plan->label()} paketi). Belgeyi yüklemek için paketinizi yükseltin.");
        }
    }

    /**
     * Bir pakete geçildiğinde mevcut kullanımın aşacağı limitler (Aşama 19'da
     * Platform Sahibi'ne onay ekranında uyarı olarak gösterilir).
     *
     * @return array<string, array{used: int|float, limit: int}>
     */
    public function overagesFor(Organization $organization, Plan $plan): array
    {
        $usage = $this->usage($organization);
        $limits = ['branches' => $plan->maxBranches(), 'users' => $plan->maxUsers(), 'storage_mb' => $plan->maxStorageMb()];

        return collect($limits)
            ->filter(fn (?int $limit, string $key) => $limit !== null && $usage[$key] > $limit)
            ->map(fn (int $limit, string $key) => ['used' => $usage[$key], 'limit' => $limit])
            ->all();
    }

    private function storageBytes(Organization $organization): int
    {
        return (int) DB::table('purchase_receipts')
            ->join('purchase_orders', 'purchase_receipts.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_orders.organization_id', $organization->id)
            ->sum('purchase_receipts.document_size');
    }
}
