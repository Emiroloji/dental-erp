<?php

namespace App\Domain\Finance\Support;

/**
 * Yükümlülüğün yönü: biz mi ödeyeceğiz (borç), bize mi ödenecek (alacak).
 */
enum ObligationDirection: string
{
    case Payable = 'payable';
    case Receivable = 'receivable';

    public function label(): string
    {
        return match ($this) {
            self::Payable => 'Ödenecek',
            self::Receivable => 'Tahsil edilecek',
        };
    }

    /**
     * Ödemesinin defterdeki yönü: borç ödemesi hesaptan çıkar, tahsilat girer.
     */
    public function entryDirection(): int
    {
        return $this === self::Payable ? -1 : 1;
    }

    public function categoryDirection(): CategoryDirection
    {
        return $this === self::Payable ? CategoryDirection::Expense : CategoryDirection::Income;
    }

    public function paymentLabel(): string
    {
        return $this === self::Payable ? 'Ödeme' : 'Tahsilat';
    }
}
