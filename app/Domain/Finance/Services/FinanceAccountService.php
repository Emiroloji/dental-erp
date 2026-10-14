<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Support\AccountType;
use App\Domain\Finance\Support\FinanceCalendar;
use Carbon\CarbonInterface;

/**
 * Hesap tanımı. Açılış bakiyesi eksi olabilir (ek hesaplı banka). Açılış
 * bakiyesi ve tarihi sonradan değiştirilmez — değişirse geçmiş tüm bakiyeler
 * değişir; yanlış açılış mutabakat düzeltmesiyle kapatılır (Aşama 36).
 */
class FinanceAccountService
{
    /**
     * @param  array{name: string, type: AccountType, iban?: ?string, opening_balance: int, opening_date: CarbonInterface}  $data
     */
    public function create(array $data): FinanceAccount
    {
        if ($data['opening_date']->toDateString() > FinanceCalendar::today()->toDateString()) {
            throw new FinanceRuleException('Açılış tarihi bugünden sonra olamaz.');
        }

        $iban = strtoupper(str_replace(' ', '', (string) ($data['iban'] ?? '')));

        return FinanceAccount::create([
            'name' => trim($data['name']),
            'type' => $data['type'],
            'iban' => $iban === '' ? null : $iban,
            'opening_balance' => $data['opening_balance'],
            'opening_date' => $data['opening_date']->toDateString(),
        ]);
    }

    public function deactivate(FinanceAccount $account): FinanceAccount
    {
        $account->update(['status' => 'passive']);

        return $account;
    }

    public function activate(FinanceAccount $account): FinanceAccount
    {
        $account->update(['status' => 'active']);

        return $account;
    }
}
