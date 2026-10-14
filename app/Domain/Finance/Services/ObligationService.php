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
use Illuminate\Support\Facades\DB;

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
        return FinanceObligation::create($this->attributes($data, auth()->user()?->organization_id));
    }

    /**
     * @param  array{direction: ObligationDirection, amount: int, due_on?: ?CarbonInterface, party?: ?FinanceParty, category?: ?FinanceCategory, description: string, document_no?: ?string, branch?: ?Branch}  $data
     */
    public function update(FinanceObligation $obligation, array $data): FinanceObligation
    {
        // Satır kilitlenir: eşzamanlı bir LedgerService::pay ile düzenleme yarışmasın.
        DB::transaction(function () use ($obligation, $data) {
            $locked = FinanceObligation::whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen() || FinanceEntry::where('obligation_id', $locked->id)->exists()) {
                throw new FinanceRuleException('Ödemesi olan veya kapanmış kayıt düzenlenemez; gerekirse iptal edip yeniden girin.');
            }

            $locked->update($this->attributes($data, $locked->organization_id, $locked));
        });

        return $obligation->refresh();
    }

    public function cancel(FinanceObligation $obligation, string $reason): FinanceObligation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new FinanceRuleException('İptal için bir gerekçe yazın.', 'reason');
        }

        // Satır kilitlenir: eşzamanlı bir LedgerService::pay ile iptal yarışmasın.
        DB::transaction(function () use ($obligation, $reason) {
            $locked = FinanceObligation::whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ObligationStatus::Cancelled) {
                throw new FinanceRuleException('Bu kayıt zaten iptal edilmiş.', 'reason');
            }

            if ($this->balances->paid($locked) !== 0) {
                throw new FinanceRuleException('Önce bu kayda bağlı ödemeleri iptal edin.', 'reason');
            }

            $locked->update(['status' => ObligationStatus::Cancelled, 'cancel_reason' => $reason]);
        });

        return $obligation->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?int $organizationId, ?FinanceObligation $current = null): array
    {
        /** @var ObligationDirection $direction */
        $direction = $data['direction'];
        $party = $data['party'] ?? null;
        $category = $data['category'] ?? null;
        $branch = $data['branch'] ?? null;
        $description = trim($data['description']);

        foreach ([$party, $category, $branch] as $related) {
            if ($related !== null && (int) $related->organization_id !== (int) $organizationId) {
                throw new FinanceRuleException('Kayıtlar aynı organizasyona ait değil.');
            }
        }

        if ($data['amount'] <= 0) {
            throw new FinanceRuleException('Tutar sıfırdan büyük olmalıdır.', 'amount');
        }

        if ($description === '') {
            throw new FinanceRuleException('Açıklama yazın (örn. fatura no veya ne için olduğu).', 'description');
        }

        // Sonradan pasife alınmış cari/kategori kayıtta değişmeden duruyorsa düzenlemeyi engellemez
        // (tekrarlayanlarla aynı kural); pasif bir kayda geçiş reddedilir. Yön kontrolü her zaman uygulanır.
        $partyUnchanged = $party !== null && $party->id === $current?->party_id;
        $categoryUnchanged = $category !== null && $category->id === $current?->category_id;

        if ($party !== null && ! $partyUnchanged && ! $party->isActive()) {
            throw new FinanceRuleException("{$party->name} pasif bir cari.", 'party');
        }

        if ($category !== null) {
            if (! $categoryUnchanged && ! $category->isActive()) {
                throw new FinanceRuleException("{$category->name} pasif bir kategori.", 'category');
            }

            if ($category->direction !== $direction->categoryDirection()) {
                throw new FinanceRuleException('Bu kayıt için bir '.mb_strtolower($direction->categoryDirection()->label()).' kategorisi seçin.', 'category');
            }
        }

        $dueOn = $data['due_on'] ?? null;

        if ($dueOn === null && $party?->default_term_days !== null) {
            $dueOn = FinanceCalendar::today()->addDays($party->default_term_days);
        }

        if ($dueOn === null) {
            throw new FinanceRuleException('Vade tarihini girin.', 'due_on');
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
            'branch_id' => $branch?->id,
        ];
    }
}
