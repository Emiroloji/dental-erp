<?php

namespace App\Domain\EInvoice\Services;

use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinancePartyService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Finance\Support\ObligationStatus;
use App\Domain\Finance\Support\PartyKind;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Gelen faturayı Finans'a cari borç (ödenecek yükümlülük) olarak aktarır
 * (Aşama 46). Elle yapılır; otomatik borç yoktur (Faz 5 kararı). Yükümlülük
 * Finans'ın kendi servisiyle açılır; defter ve bakiye kuralları değişmez.
 */
class EInvoiceFinanceService
{
    public function __construct(
        private readonly ObligationService $obligations,
        private readonly FinancePartyService $parties,
    ) {}

    /**
     * @param  ?CarbonInterface  $dueOn  boşsa fatura tarihi + carinin vade günü (yoksa fatura tarihi)
     *
     * @throws EInvoiceException
     */
    public function transfer(EInvoice $invoice, ?CarbonInterface $dueOn = null): FinanceObligation
    {
        if ($invoice->currency !== 'TRY') {
            throw new EInvoiceException('Yalnızca TL faturalar Finans\'a aktarılabilir; Finans döviz tutmaz.');
        }

        if ($invoice->type === 'IADE') {
            throw new EInvoiceException('İade faturası borç olarak aktarılamaz.');
        }

        try {
            return DB::transaction(function () use ($invoice, $dueOn) {
                $locked = EInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                if ($this->hasOpenTransfer($locked)) {
                    throw new EInvoiceException('Bu fatura zaten Finans\'a aktarıldı.');
                }

                $party = $this->partyFor($locked);

                $obligation = $this->obligations->create([
                    'direction' => ObligationDirection::Payable,
                    'amount' => $locked->payable,
                    'due_on' => $dueOn ?? $locked->issue_date->copy()->addDays($party->default_term_days ?? 0),
                    'party' => $party,
                    'description' => "e-Fatura {$locked->invoice_number}, {$locked->sender_name}",
                    'document_no' => $locked->invoice_number,
                ]);

                $locked->update(['finance_obligation_id' => $obligation->id]);

                return $obligation;
            });
        } catch (FinanceRuleException $e) {
            throw new EInvoiceException($e->getMessage());
        }
    }

    public function hasOpenTransfer(EInvoice $invoice): bool
    {
        return $invoice->finance_obligation_id !== null
            && FinanceObligation::whereKey($invoice->finance_obligation_id)->where('status', '!=', ObligationStatus::Cancelled->value)->exists();
    }

    /**
     * Gönderenin carisi VKN/TCKN ile bulunur; yoksa "Tedarikçi" türünde
     * açılır. Aynı adlı başka bir cari varsa ad VKN ile ayrıştırılır.
     */
    private function partyFor(EInvoice $invoice): FinanceParty
    {
        $taxNumber = preg_replace('/\s+/', '', (string) $invoice->sender_tax_number);

        if ($taxNumber !== '' && ($party = FinanceParty::whereRaw("replace(tax_number, ' ', '') = ?", [$taxNumber])->orderByRaw("case when status = 'active' then 0 else 1 end")->first())) {
            return $party;
        }

        $name = trim((string) $invoice->sender_name) ?: "VKN {$taxNumber}";

        if (FinanceParty::whereRaw('LOWER(name) = LOWER(?)', [$name])->exists()) {
            $name = "{$name} ({$taxNumber})";
        }

        return $this->parties->create(['name' => $name, 'kind' => PartyKind::Supplier, 'tax_number' => $taxNumber ?: null]);
    }
}
