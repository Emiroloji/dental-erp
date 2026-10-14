<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Models\FinanceRecurring;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\RecurringFrequency;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Support\OrganizationStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tekrarlayan kalemler (kira, maaş, abonelik). Günlük iş, aktif tanımlardan
 * önümüzdeki HORIZON_DAYS gün içinde vadesi gelecek dönemlerin yükümlülüğünü
 * üretir. (recurring_id, due_on) tekil olduğu için iş iki kez çalışsa da çift
 * kayıt oluşmaz. Oturum olmadan (zamanlayıcı) çalışır: organizasyon açıkça verilir.
 */
class RecurringService
{
    public const HORIZON_DAYS = 35;

    /**
     * @param  array{direction: ObligationDirection, name: string, amount: int, category: FinanceCategory, party?: ?FinanceParty, frequency: RecurringFrequency, starts_on: CarbonInterface, ends_on?: ?CarbonInterface}  $data
     */
    public function create(array $data): FinanceRecurring
    {
        $startsOn = CarbonImmutable::parse($data['starts_on']->toDateString());
        $day = $data['frequency']->dayFor($startsOn);

        // Çok eski bir başlangıç yüzlerce geçmiş yükümlülük üretmesin: ilk vade
        // en fazla ufuk kadar geriden başlar (starts_on ve day olduğu gibi kalır).
        $floor = FinanceCalendar::today()->subDays(self::HORIZON_DAYS)->toDateString();
        $nextDue = $startsOn;
        while ($nextDue->toDateString() < $floor) {
            $nextDue = $data['frequency']->next($nextDue, $day);
        }

        return FinanceRecurring::create([
            ...$this->attributes($data, $data['direction'], $startsOn),
            'direction' => $data['direction'],
            'frequency' => $data['frequency'],
            'day' => $day,
            'starts_on' => $startsOn->toDateString(),
            'next_due_on' => $nextDue->toDateString(),
        ]);
    }

    /**
     * @param  array{name: string, amount: int, category: FinanceCategory, party?: ?FinanceParty, ends_on?: ?CarbonInterface}  $data
     */
    public function update(FinanceRecurring $recurring, array $data): FinanceRecurring
    {
        $recurring->update($this->attributes($data, $recurring->direction, $recurring->starts_on, $recurring));

        return $recurring;
    }

    public function deactivate(FinanceRecurring $recurring): FinanceRecurring
    {
        $recurring->update(['status' => 'passive']);

        return $recurring;
    }

    /**
     * Pasifken kaçırılan dönemler toplu üretilmez: sonraki vade bugüne veya
     * sonrasına ilerletilir (geçmiş kira yükümlülükleri sessizce birikmesin).
     */
    public function activate(FinanceRecurring $recurring): FinanceRecurring
    {
        $today = FinanceCalendar::today();
        $next = $recurring->next_due_on;

        while ($next->lt($today)) {
            $next = $recurring->frequency->next($next, $recurring->day);
        }

        $recurring->update(['status' => 'active', 'next_due_on' => $next->toDateString()]);

        return $recurring;
    }

    public function generateDue(?CarbonImmutable $today = null): int
    {
        $horizon = ($today ?? FinanceCalendar::today())->addDays(self::HORIZON_DAYS);
        $activeOrganizations = Organization::where('status', OrganizationStatus::Active)->pluck('id');
        $created = 0;

        FinanceRecurring::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereIn('organization_id', $activeOrganizations)
            ->whereDate('next_due_on', '<=', $horizon->toDateString())
            ->chunkById(100, function (Collection $recurrings) use ($horizon, &$created) {
                foreach ($recurrings as $recurring) {
                    // Bozuk bir tanım diğer tanımların (ve kiracıların) üretimini durdurmasın.
                    try {
                        $created += $this->generateFor($recurring, $horizon);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $created;
    }

    private function generateFor(FinanceRecurring $recurring, CarbonImmutable $horizon): int
    {
        return DB::transaction(function () use ($recurring, $horizon) {
            $created = 0;
            $next = $recurring->next_due_on;

            // Tarihler metin olarak karşılaştırılır: bugün İstanbul gece yarısı, date cast'leri UTC gece yarısıdır.
            $horizonDate = $horizon->toDateString();
            $endsOn = $recurring->ends_on?->toDateString();

            // Uzun süre kapalı kalan klinik yeniden açılınca geçmiş dönemler toplu
            // üretilmez: create() ile aynı taban (bugün − HORIZON_DAYS).
            $floor = FinanceCalendar::today()->subDays(self::HORIZON_DAYS)->toDateString();

            while ($next->toDateString() < $floor) {
                $next = $recurring->frequency->next($next, $recurring->day);
            }

            while ($next->toDateString() <= $horizonDate && ($endsOn === null || $next->toDateString() <= $endsOn)) {
                // whereDate: due_on 'date' cast'iyle saklanırken düz eşitlik mevcut kaydı bulamayabilir.
                $exists = FinanceObligation::withoutGlobalScopes()
                    ->where('recurring_id', $recurring->id)
                    ->whereDate('due_on', $next->toDateString())
                    ->exists();

                if (! $exists) {
                    FinanceObligation::withoutGlobalScopes()->create([
                        'organization_id' => $recurring->organization_id,
                        'recurring_id' => $recurring->id,
                        'due_on' => $next->toDateString(),
                        'direction' => $recurring->direction,
                        'party_id' => $recurring->party_id,
                        'category_id' => $recurring->category_id,
                        'amount' => $recurring->amount,
                        'description' => $recurring->name.' — '.$recurring->frequency->periodLabel($next),
                    ]);
                    $created++;
                }

                $next = $recurring->frequency->next($next, $recurring->day);
            }

            $ended = $endsOn !== null && $next->toDateString() > $endsOn;
            $recurring->update(['next_due_on' => $next->toDateString(), 'status' => $ended ? 'passive' : $recurring->status]);

            return $created;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data, ObligationDirection $direction, CarbonImmutable $startsOn, ?FinanceRecurring $current = null): array
    {
        $name = trim($data['name']);
        $party = $data['party'] ?? null;
        $endsOn = $data['ends_on'] ?? null;

        if ($name === '') {
            throw new FinanceRuleException('Ad yazın (örn. Kira, Maaş).', 'name');
        }

        if ($data['amount'] <= 0) {
            throw new FinanceRuleException('Tutar sıfırdan büyük olmalıdır.', 'amount');
        }

        // Sonradan pasife alınmış cari/kategori, kalemde değişmeden duruyorsa düzenlemeyi engellemez;
        // pasif bir kayda geçiş yine reddedilir.
        $categoryUnchanged = $current !== null && $current->category_id === $data['category']->id;
        $partyUnchanged = $current !== null && $party !== null && $current->party_id === $party->id;

        if ((! $categoryUnchanged && ! $data['category']->isActive()) || $data['category']->direction !== $direction->categoryDirection()) {
            throw new FinanceRuleException('Aktif bir '.mb_strtolower($direction->categoryDirection()->label()).' kategorisi seçin.', 'category');
        }

        if ($party !== null && ! $partyUnchanged && ! $party->isActive()) {
            throw new FinanceRuleException("{$party->name} pasif bir cari.", 'party');
        }

        if ($endsOn !== null && $endsOn->toDateString() < $startsOn->toDateString()) {
            throw new FinanceRuleException('Bitiş tarihi başlangıçtan önce olamaz.', 'ends_on');
        }

        return [
            'name' => $name,
            'amount' => $data['amount'],
            'category_id' => $data['category']->id,
            'party_id' => $party?->id,
            'ends_on' => $endsOn?->toDateString(),
        ];
    }
}
