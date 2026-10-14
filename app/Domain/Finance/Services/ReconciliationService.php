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
        if (! $account->isActive()) {
            throw new FinanceRuleException("{$account->name} pasif bir hesap; mutabakat yapılamaz.", 'account');
        }

        // Ekstre tarihi, defter yazma kurallarıyla aynı: bugünden sonra ve açılıştan önce olamaz.
        $this->ledger->ensureWritable($account, $statementDate, 'date');

        $note = trim((string) $note) === '' ? null : trim((string) $note);

        return DB::transaction(function () use ($account, $statementDate, $statementBalance, $actor, $adjust, $note) {
            ['system_balance' => $system, 'difference' => $difference] = $this->preview($account, $statementDate, $statementBalance);

            $entry = null;

            if ($difference !== 0 && $adjust) {
                $description = "Mutabakat: ekstre {$statementDate->format('d.m.Y')}".($note !== null ? " — {$note}" : '');
                $entry = $this->ledger->adjust($account, $difference, $statementDate, $actor, $description);
            }

            return FinanceReconciliation::create([
                'organization_id' => $account->organization_id,
                'account_id' => $account->id,
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
