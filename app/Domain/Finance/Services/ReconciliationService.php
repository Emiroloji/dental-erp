<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceReconciliation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Mutabakat: hesabın ekstre bakiyesini ekstre tarihindeki defter bakiyesiyle
 * karşılaştırır. Fark istenirse ekstre tarihli bir düzeltme hareketiyle kapatılır
 * (LedgerService::adjust); istenmezse yalnızca kayda geçer ve sonra araştırılır.
 */
class ReconciliationService
{
    public function __construct(
        private readonly FinanceBalanceService $balances,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @return array{system_balance: int, difference: int}
     */
    public function preview(FinanceAccount $account, CarbonInterface $statementDate, int $statementBalance): array
    {
        $system = $this->balances->balance($account, $statementDate);

        return ['system_balance' => $system, 'difference' => $statementBalance - $system];
    }

    public function reconcile(
        FinanceAccount $account,
        CarbonInterface $statementDate,
        int $statementBalance,
        User $actor,
        bool $adjust,
        ?string $note = null,
    ): FinanceReconciliation {
        $note = trim((string) $note) === '' ? null : mb_substr(trim((string) $note), 0, 500);

        return DB::transaction(function () use ($account, $statementDate, $statementBalance, $actor, $adjust, $note) {
            // Hesap satırı kilitlenir: eşzamanlı iki mutabakat aynı sistem bakiyesini
            // okuyup iki düzeltme yazmasın. Defter yazımı (finance_entries ekleme) hesap
            // satırına yabancı anahtar nedeniyle FOR KEY SHARE kilidi alır ve bu FOR UPDATE'i
            // bekler; kilidi etkili kılan budur. pay() yükümlülüğü kilitler, hesabı değil;
            // ortak kilit olmadığından sıra çakışması (deadlock) oluşmaz.
            $locked = FinanceAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw new FinanceRuleException("{$locked->name} pasif bir hesap; mutabakat yapılamaz.", 'account');
            }

            // Ekstre tarihi, defter yazma kurallarıyla aynı: bugünden sonra ve açılıştan önce olamaz.
            $this->ledger->assertDateWritable($locked, $statementDate);

            ['system_balance' => $system, 'difference' => $difference] = $this->preview($locked, $statementDate, $statementBalance);

            $entry = null;

            if ($difference !== 0 && $adjust) {
                $description = "Mutabakat: ekstre {$statementDate->format('d.m.Y')}".($note !== null ? " — {$note}" : '');
                $entry = $this->ledger->adjust($locked, $difference, $statementDate, $actor, $description);
            }

            return FinanceReconciliation::create([
                'organization_id' => $locked->organization_id,
                'account_id' => $locked->id,
                'statement_date' => $statementDate->toDateString(),
                'statement_balance' => $statementBalance,
                'system_balance' => $system,
                'difference' => $difference,
                'adjustment_entry_id' => $entry?->id,
                'actor_id' => $actor->id,
                'note' => $note,
            ]);
        });
    }
}
