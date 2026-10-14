<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceCategory;
use App\Domain\Finance\Models\FinanceEntry;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Organization\Models\Branch;
use Carbon\CarbonInterface;

/**
 * Vadeli borç ve alacak. Geçmiş tarihli vade girilebilir (eski ödenmemiş
 * faturayı sisteme almak için). Ödeme LedgerService::pay ile yapılır; ödenen
 * tutar burada değil defterde tutulur.
 */
class ObligationService
{
    public function __construct(private readonly FinanceBalanceService $balances) {}

    /**
     * @param  array{direction: ObligationDirection, amount: int, due_on?: ?CarbonInterface, party?: ?FinanceParty, category?: ?FinanceCategory, description: string, document_no?: ?string, branch?: ?Branch}  $data
     */
    public function create(array $data): FinanceObligation
    {
        return FinanceObligation::create($this->attributes($data));
    }

    /**
     * @param  array{direction: ObligationDirection, amount: int, due_on?: ?CarbonInterface, party?: ?FinanceParty, category?: ?FinanceCategory, description: string, document_no?: ?string, branch?: ?Branch}  $data
     */
    public function update(FinanceObligation $obligation, array $data): FinanceObligation
    {
        if (! $obligation->isOpen() || FinanceEntry::where('obligation_id', $obligation->id)->exists()) {
            throw new FinanceRuleException('Ödemesi olan veya kapanmış kayıt düzenlenemez; gerekirse iptal edip yeniden girin.');
        }

        $obligation->update($this->attributes($data));

        return $obligation;
    }

    public function cancel(FinanceObligation $obligation, string $reason): FinanceObligation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new FinanceRuleException('İptal için bir gerekçe yazın.');
        }

        if ($obligation->status === ObligationStatus::Cancelled) {
            throw new FinanceRuleException('Bu kayıt zaten iptal edilmiş.');
        }

        if ($this->balances->paid($obligation) !== 0) {
            throw new FinanceRuleException('Önce bu kayda bağlı ödemeleri iptal edin.');
        }

        $obligation->update(['status' => ObligationStatus::Cancelled, 'cancel_reason' => $reason]);

        return $obligation;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        /** @var ObligationDirection $direction */
        $direction = $data['direction'];
        $party = $data['party'] ?? null;
        $category = $data['category'] ?? null;
        $description = trim($data['description']);

        if ($data['amount'] <= 0) {
            throw new FinanceRuleException('Tutar sıfırdan büyük olmalıdır.');
        }

        if ($description === '') {
            throw new FinanceRuleException('Açıklama yazın (örn. fatura no veya ne için olduğu).');
        }

        if ($party !== null && ! $party->isActive()) {
            throw new FinanceRuleException("{$party->name} pasif bir cari.");
        }

        if ($category !== null) {
            if (! $category->isActive()) {
                throw new FinanceRuleException("{$category->name} pasif bir kategori.");
            }

            if ($category->direction !== $direction->categoryDirection()) {
                throw new FinanceRuleException('Bu kayıt için bir '.mb_strtolower($direction->categoryDirection()->label()).' kategorisi seçin.');
            }
        }

        $dueOn = $data['due_on'] ?? null;

        if ($dueOn === null && $party?->default_term_days !== null) {
            $dueOn = FinanceCalendar::today()->addDays($party->default_term_days);
        }

        if ($dueOn === null) {
            throw new FinanceRuleException('Vade tarihini girin.');
        }

        $documentNo = trim((string) ($data['document_no'] ?? ''));

        return [
            'direction' => $direction,
            'amount' => $data['amount'],
            'due_on' => $dueOn->toDateString(),
            'party_id' => $party?->id,
            'category_id' => $category?->id,
            'description' => $description,
            'document_no' => $documentNo === '' ? null : $documentNo,
            'branch_id' => ($data['branch'] ?? null)?->id,
        ];
    }
}
