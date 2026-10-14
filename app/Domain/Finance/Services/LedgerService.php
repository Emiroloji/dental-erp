<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Support\CategoryDirection;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\FinanceEntryType;
use App\Domain\Organization\Models\Branch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Finans defterinin tek kapısı (karar 1.7 — stoktaki StockMovementService'in
 * finanstaki karşılığı). finance_entries tablosuna başka hiçbir sınıf yazmaz.
 *
 * Kurallar: tutar > 0, yönü türü belirler; pasif hesap/kategoriye yazılmaz;
 * ileri tarihli ve hesabın açılışından önceki tarihli hareket yazılmaz; eksi
 * bakiyeye izin verilir (ek hesaplı banka gerçekliği), uyarıyı ekran gösterir.
 */
class LedgerService
{
    public function income(
        FinanceAccount $account,
        int $amount,
        CarbonInterface $date,
        FinanceCategory $category,
        ?User $actor = null,
        ?string $description = null,
        ?Branch $branch = null,
        ?string $documentNo = null,
    ): FinanceEntry {
        return $this->categorized(FinanceEntryType::Income, $account, $amount, $date, $category, $actor, $description, $branch, $documentNo);
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
    ): FinanceEntry {
        return $this->categorized(FinanceEntryType::Expense, $account, $amount, $date, $category, $actor, $description, $branch, $documentNo);
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
    ): FinanceEntry {
        $this->ensurePositive($amount);
        $this->ensureWritable($account, $date);
        $this->ensureCategory($category, $type === FinanceEntryType::Income ? CategoryDirection::Income : CategoryDirection::Expense);

        return DB::transaction(fn () => $this->record($account, $type, $type->direction(), $amount, $date, $actor, [
            'category_id' => $category->id,
            'branch_id' => $branch?->id,
            'description' => $this->clean($description),
            'document_no' => $this->clean($documentNo),
        ]));
    }

    private function ensurePositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new FinanceRuleException('Tutar sıfırdan büyük olmalıdır.');
        }
    }

    private function ensureWritable(FinanceAccount $account, CarbonInterface $date): void
    {
        if (! $account->isActive()) {
            throw new FinanceRuleException("{$account->name} pasif bir hesap; hareket yazılamaz.");
        }

        if ($date->toDateString() > FinanceCalendar::today()->toDateString()) {
            throw new FinanceRuleException('İleri tarihli hareket yazılamaz; gerçekleşmemiş ödeme hareket değildir.');
        }

        if ($date->toDateString() < $account->opening_date->toDateString()) {
            throw new FinanceRuleException("{$account->name} hesabının açılış tarihinden önce hareket yazılamaz.");
        }
    }

    private function ensureCategory(FinanceCategory $category, CategoryDirection $direction): void
    {
        if (! $category->isActive()) {
            throw new FinanceRuleException("{$category->name} pasif bir kategori.");
        }

        if ($category->direction !== $direction) {
            throw new FinanceRuleException('Bu hareket için bir '.mb_strtolower($direction->label()).' kategorisi seçin.');
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

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
