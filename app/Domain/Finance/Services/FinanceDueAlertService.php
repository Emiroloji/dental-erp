<?php

namespace App\Domain\Finance\Services;

use App\Domain\Access\Support\Module;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Notifications\FinanceDueNotification;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\OrganizationStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Vade taraması: NOTICE_DAYS gün içinde vadesi dolacak (upcoming) veya geçmiş
 * (overdue) açık yükümlülükler için Admin'e ve finans okuma yetkili personele
 * uygulama içi bildirim. Aynı yükümlülük için aynı durumda okunmamış bildirim
 * varsa tekrar gönderilmez; durum kötüleşirse (yaklaşan → geçmiş) yenisi gider.
 */
class FinanceDueAlertService
{
    public const NOTICE_DAYS = 3;

    public function __construct(private readonly FinanceBalanceService $balances) {}

    public function scan(?CarbonImmutable $today = null): int
    {
        $today ??= FinanceCalendar::today();
        $activeOrganizations = Organization::where('status', OrganizationStatus::Active)->pluck('id');
        $sent = 0;

        FinanceObligation::withoutGlobalScopes()
            ->whereIn('organization_id', $activeOrganizations)
            ->whereIn('status', [ObligationStatus::Open, ObligationStatus::Partial])
            ->whereDate('due_on', '<=', $today->addDays(self::NOTICE_DAYS)->toDateString())
            ->chunkById(200, function (Collection $obligations) use ($today, &$sent) {
                foreach ($obligations->groupBy('organization_id') as $organizationId => $group) {
                    // Zamanlanmış çok kiracılı tarama: tek organizasyonun hatası diğerlerini durdurmaz.
                    try {
                        $recipients = $this->recipients((int) $organizationId);

                        foreach ($group as $obligation) {
                            $sent += $this->notify($obligation, $recipients, $today);
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $sent;
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function notify(FinanceObligation $obligation, Collection $recipients, CarbonImmutable $today): int
    {
        $state = $obligation->due_on->toDateString() < $today->toDateString() ? 'overdue' : 'upcoming';
        $remaining = Money::format($this->balances->remaining($obligation));
        $what = $obligation->direction->paymentLabel();
        $title = $state === 'overdue' ? "Vadesi geçmiş {$what}" : "Vadesi yaklaşan {$what}";
        $message = "{$obligation->description} — kalan {$remaining}, vade {$obligation->due_on->format('d.m.Y')}";
        $sent = 0;

        foreach ($recipients as $recipient) {
            $alreadyNotified = $recipient->unreadNotifications()
                ->where('type', FinanceDueNotification::class)
                ->where('data->obligation_id', $obligation->id)
                ->where('data->state', $state)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            $recipient->notify(new FinanceDueNotification($obligation->id, $state, $title, $message, url('/finans/odemeler')));
            $sent++;
        }

        return $sent;
    }

    /**
     * Finans organizasyon geneli (karar 2.9): Admin + finans okuma yetkili
     * personel. Finans yetkisi yalnızca "tüm şubeler" kapsamıyla verilebildiği
     * için şube süzgeci gerekmez.
     *
     * @return Collection<int, User>
     */
    private function recipients(int $organizationId): Collection
    {
        return User::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->where('module', Module::Finance->value)->where('can_read', true));
            })
            ->get();
    }
}
