<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Models\Branch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finans defterinin tek kapısı (karar 1.7 — stoktaki StockMovementService'in
 * finanstaki karşılığı). finance_entries tablosuna başka hiçbir sınıf yazmaz.
 *
 * Kurallar: tutar > 0, yönü türü belirler; pasif hesap/kategoriye yazılmaz;
 * ileri tarihli ve hesabın açılışından önceki tarihli hareket yazılmaz; eksi
 * bakiyeye izin verilir (ek hesaplı banka gerçekliği), uyarıyı ekran gösterir.
 * Yükümlülük ödemeleri de buradan geçer (pay); yükümlülüğün durumu her ödeme ve
 * iptalden sonra defterden yeniden hesaplanır.
 */
class LedgerService
{
    public function __construct(private readonly FinanceBalanceService $balances) {}

    public function income(
        FinanceAccount $account,
        int $amount,
        CarbonInterface $date,
        FinanceCategory $category,
        ?User $actor = null,
        ?string $description = null,
        ?Branch $branch = null,
        ?string $documentNo = null,
        ?FinanceParty $party = null,
    ): FinanceEntry {
        return $this->categorized(FinanceEntryType::Income, $account, $amount, $date, $category, $actor, $description, $branch, $documentNo, $party);
    }

    public function expense(
        FinanceAccount $account,
        int $amount,
        CarbonInterface $date,
        FinanceCategory $category,
        ?User $actor = null,
        ?string $description = null,
        ?Branch $branch = null,
        ?string $documentNo = null,
        ?FinanceParty $party = null,
    ): FinanceEntry {
        return $this->categorized(FinanceEntryType::Expense, $account, $amount, $date, $category, $actor, $description, $branch, $documentNo, $party);
    }

    /**
     * Hesaplar arası transfer: iki ayak aynı transfer_group ile yazılır. POS'tan
     * bankaya geçişte banka net tutarı yatırır; komisyon verilirse hedef
     * hesaptan ayrıca gider olarak düşülür (aynı gruba bağlı).
     *
     * @return Collection<int, FinanceEntry>
     */
    public function transfer(
        FinanceAccount $from,
        FinanceAccount $to,
        int $amount,
        CarbonInterface $date,
        ?User $actor = null,
        ?string $description = null,
        int $commission = 0,
        ?FinanceCategory $commissionCategory = null,
    ): Collection {
        $this->ensurePositive($amount);

        if ($from->is($to)) {
            throw new FinanceRuleException('Kaynak ve hedef hesap aynı olamaz.', 'to_account');
        }

        $this->ensureWritable($from, $date);
        $this->ensureWritable($to, $date, 'to_account');
        $this->ensureSameOrganization($from, $to, $commissionCategory);

        if ($commission < 0) {
            throw new FinanceRuleException('Komisyon eksi olamaz.', 'commission');
        }

        if ($commission > 0) {
            if ($commissionCategory === null) {
                throw new FinanceRuleException('Komisyon için bir gider kategorisi seçin.', 'commission');
            }

            $this->ensureCategory($commissionCategory, CategoryDirection::Expense);
        }

        $common = ['transfer_group' => (string) Str::uuid(), 'description' => $this->clean($description)];

        return DB::transaction(function () use ($from, $to, $amount, $date, $actor, $commission, $commissionCategory, $common) {
            $entries = collect([
                $this->record($from, FinanceEntryType::TransferOut, -1, $amount, $date, $actor, $common),
                $this->record($to, FinanceEntryType::TransferIn, 1, $amount, $date, $actor, $common),
            ]);

            if ($commission > 0) {
                $entries->push($this->record($to, FinanceEntryType::Expense, -1, $commission, $date, $actor, [
                    ...$common,
                    'category_id' => $commissionCategory->id,
                ]));
            }

            return $entries;
        });
    }

    /**
     * Yükümlülüğe ödeme/tahsilat (kısmi olabilir). Yükümlülük satırı kilitlenir:
     * kalan kontrolü ile yazma arasında ikinci bir ödeme sığmasın. Ödeme
     * kaydı yükümlülüğün kategorisini, carisini ve şubesini taşır — raporlar
     * ödemeyi o kategoride gelir/gider sayar.
     */
    public function pay(
        FinanceObligation $obligation,
        FinanceAccount $account,
        int $amount,
        CarbonInterface $date,
        ?User $actor = null,
        ?string $description = null,
    ): FinanceEntry {
        $this->ensurePositive($amount);
        $this->ensureWritable($account, $date);
        $this->ensureSameOrganization($account, $obligation);

        return DB::transaction(function () use ($obligation, $account, $amount, $date, $actor, $description) {
            $locked = FinanceObligation::whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new FinanceRuleException('Bu kayıt kapanmış veya iptal edilmiş; ödeme yapılamaz.');
            }

            $remaining = $this->balances->remaining($locked);

            if ($amount > $remaining) {
                throw new FinanceRuleException('Kalan tutardan ('.Money::format($remaining).') fazla ödeme yapılamaz.', 'amount');
            }

            $entry = $this->record($account, FinanceEntryType::Payment, $locked->direction->entryDirection(), $amount, $date, $actor, [
                'category_id' => $locked->category_id,
                'party_id' => $locked->party_id,
                'obligation_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'description' => $this->clean($description) ?? $locked->description,
                'document_no' => $locked->document_no,
            ]);

            $this->syncObligationStatus($locked);

            return $entry;
        });
    }

    /**
     * Yanlış kaydı ters kayıtla iptal eder (karar 1.5 — silme yok). Transferin
     * herhangi bir ayağı iptal edilirse grubun tüm kayıtları birlikte iptal
     * edilir; aksi hâlde para bir hesaptan çıkmış, diğerine girmemiş görünür.
     * İptal, iptal günü tarihlenir: kapanmış dönemin raporu sonradan değişmez.
     *
     * @return Collection<int, FinanceEntry>
     */
    public function reverse(FinanceEntry $entry, string $reason, ?User $actor = null): Collection
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new FinanceRuleException('İptal için bir gerekçe yazın.', 'reason');
        }

        if ($entry->type === FinanceEntryType::Reversal) {
            throw new FinanceRuleException('İptal kaydı iptal edilemez; gerekiyorsa yeni bir hareket girin.', 'reason');
        }

        $targets = $entry->transfer_group
            ? FinanceEntry::where('transfer_group', $entry->transfer_group)->where('type', '!=', FinanceEntryType::Reversal)->with('account')->orderBy('id')->get()
            : collect([$entry->loadMissing('account')]);

        if ($targets->contains(fn (FinanceEntry $target) => $target->reversal()->exists())) {
            throw new FinanceRuleException('Bu hareket zaten iptal edilmiş.', 'reason');
        }

        $today = FinanceCalendar::today();
        $targets->each(fn (FinanceEntry $target) => $this->ensureWritable($target->account, $today));

        // reverses_entry_id tekil olduğu için eşzamanlı ikinci iptal veritabanında reddedilir.
        try {
            return DB::transaction(function () use ($targets, $today, $actor, $reason) {
                // Kilit sırası: önce yükümlülükler (artan id), sonra yazma. obligation_id
                // gerçek bir FK; PostgreSQL'de insert satıra FOR KEY SHARE alır, kilidi
                // yazmadan sonra isteyen iki eşzamanlı iptal birbirini kilitler (deadlock).
                $obligationIds = $targets->pluck('obligation_id')->filter()->unique()->values();
                $obligations = $obligationIds->isEmpty()
                    ? collect()
                    : FinanceObligation::whereKey($obligationIds->all())->orderBy('id')->lockForUpdate()->get();

                $reversals = $targets->map(fn (FinanceEntry $target) => $this->record(
                    $target->account,
                    FinanceEntryType::Reversal,
                    -$target->direction,
                    $target->amount,
                    $today,
                    $actor,
                    [
                        'category_id' => $target->category_id,
                        'party_id' => $target->party_id,
                        'obligation_id' => $target->obligation_id,
                        'branch_id' => $target->branch_id,
                        'reverses_entry_id' => $target->id,
                        'description' => "İptal: #{$target->id} — {$reason}",
                    ],
                ));

                $obligations->each(fn (FinanceObligation $obligation) => $this->syncObligationStatus($obligation));

                return $reversals;
            });
        } catch (UniqueConstraintViolationException) {
            throw new FinanceRuleException('Bu hareket zaten iptal edilmiş.', 'reason');
        }
    }

    private function categorized(
        FinanceEntryType $type,
        FinanceAccount $account,
        int $amount,
        CarbonInterface $date,
        FinanceCategory $category,
        ?User $actor,
        ?string $description,
        ?Branch $branch,
        ?string $documentNo,
        ?FinanceParty $party,
    ): FinanceEntry {
        $this->ensurePositive($amount);
        $this->ensureWritable($account, $date);
        $this->ensureCategory($category, $type === FinanceEntryType::Income ? CategoryDirection::Income : CategoryDirection::Expense);
        $this->ensureSameOrganization($account, $category, $branch, $party);

        if ($party !== null && ! $party->isActive()) {
            throw new FinanceRuleException("{$party->name} pasif bir cari.", 'party');
        }

        return DB::transaction(fn () => $this->record($account, $type, $type->direction(), $amount, $date, $actor, [
            'category_id' => $category->id,
            'party_id' => $party?->id,
            'branch_id' => $branch?->id,
            'description' => $this->clean($description),
            'document_no' => $this->clean($documentNo),
        ]));
    }

    private function ensurePositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new FinanceRuleException('Tutar sıfırdan büyük olmalıdır.', 'amount');
        }
    }

    private function ensureWritable(FinanceAccount $account, CarbonInterface $date, string $field = 'account'): void
    {
        if (! $account->isActive()) {
            throw new FinanceRuleException("{$account->name} pasif bir hesap; hareket yazılamaz.", $field);
        }

        if ($date->toDateString() > FinanceCalendar::today()->toDateString()) {
            throw new FinanceRuleException('İleri tarihli hareket yazılamaz; gerçekleşmemiş ödeme hareket değildir.', 'date');
        }

        if ($date->toDateString() < $account->opening_date->toDateString()) {
            throw new FinanceRuleException("{$account->name} hesabının açılış tarihinden önce hareket yazılamaz.", $field);
        }
    }

    private function ensureCategory(FinanceCategory $category, CategoryDirection $direction): void
    {
        if (! $category->isActive()) {
            throw new FinanceRuleException("{$category->name} pasif bir kategori.", 'category');
        }

        if ($category->direction !== $direction) {
            throw new FinanceRuleException('Bu hareket için bir '.mb_strtolower($direction->label()).' kategorisi seçin.', 'category');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(FinanceAccount $account, FinanceEntryType $type, int $direction, int $amount, CarbonInterface $date, ?User $actor, array $attributes = []): FinanceEntry
    {
        return FinanceEntry::create([
            'organization_id' => $account->organization_id,
            'account_id' => $account->id,
            'type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'occurred_on' => $date->toDateString(),
            'actor_id' => $actor?->id,
            ...$attributes,
        ]);
    }

    /**
     * Durum defterden türetilir; iptal edilmiş kayda dokunulmaz.
     */
    private function syncObligationStatus(FinanceObligation $obligation): void
    {
        if ($obligation->status === ObligationStatus::Cancelled) {
            return;
        }

        $paid = $this->balances->paid($obligation);
        $status = match (true) {
            $paid <= 0 => ObligationStatus::Open,
            $paid >= $obligation->amount => ObligationStatus::Paid,
            default => ObligationStatus::Partial,
        };

        if ($obligation->status !== $status) {
            $obligation->update(['status' => $status]);
        }
    }

    /**
     * Savunma: ekranlar kapsamlı modeller geçiriyor ama defter, başka bir
     * organizasyonun kategorisini/carisini/şubesini asla yazmamalı.
     */
    private function ensureSameOrganization(?Model ...$models): void
    {
        $organizationIds = collect($models)->filter()->map(fn (Model $model) => (int) $model->organization_id)->unique();

        if ($organizationIds->count() > 1) {
            throw new FinanceRuleException('Kayıtlar aynı organizasyona ait değil.');
        }
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
