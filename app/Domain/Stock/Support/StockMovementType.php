<?php

namespace App\Domain\Stock\Support;

enum StockMovementType: string
{
    case In = 'in';
    case Out = 'out';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case CountAdjust = 'count_adjust';
    case ReturnMovement = 'return';
    case Cancel = 'cancel';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Giriş',
            self::Out => 'Çıkış',
            self::TransferOut => 'Transfer (Gönderim)',
            self::TransferIn => 'Transfer (Teslim Alım)',
            self::CountAdjust => 'Sayım Düzeltmesi',
            self::ReturnMovement => 'İade',
            self::Cancel => 'İptal',
        };
    }
}
