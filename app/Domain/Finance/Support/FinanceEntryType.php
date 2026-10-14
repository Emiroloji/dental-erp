<?php

namespace App\Domain\Finance\Support;

enum FinanceEntryType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Payment = 'payment';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Gelir',
            self::Expense => 'Gider',
            self::TransferOut => 'Transfer (Çıkan)',
            self::TransferIn => 'Transfer (Giren)',
            self::Payment => 'Ödeme/Tahsilat',
            self::Reversal => 'İptal',
            self::Adjustment => 'Mutabakat düzeltmesi',
        };
    }

    /**
     * Hesaba etkisinin işareti. Ödeme, iptal ve mutabakat düzeltmesinin yönü sabit değildir:
     * ödemede yükümlülüğün yönü, iptalde iptal ettiği kaydın tersi, düzeltmede farkın işareti.
     */
    public function direction(): ?int
    {
        return match ($this) {
            self::Income, self::TransferIn => 1,
            self::Expense, self::TransferOut => -1,
            self::Payment, self::Reversal, self::Adjustment => null,
        };
    }
}
