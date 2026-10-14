<?php

namespace App\Domain\Finance\Support;

enum FinanceEntryType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Gelir',
            self::Expense => 'Gider',
            self::TransferOut => 'Transfer (Çıkan)',
            self::TransferIn => 'Transfer (Giren)',
            self::Reversal => 'İptal',
        };
    }

    /**
     * Hesaba etkisinin işareti. İptal kaydının yönü sabit değildir: iptal
     * ettiği kaydın tersidir (LedgerService::reverse).
     */
    public function direction(): ?int
    {
        return match ($this) {
            self::Income, self::TransferIn => 1,
            self::Expense, self::TransferOut => -1,
            self::Reversal => null,
        };
    }
}
