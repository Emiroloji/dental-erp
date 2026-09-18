<?php

namespace App\Domain\Purchasing\Exceptions;

use RuntimeException;

/**
 * Satın alma iş kuralına aykırı bir işlem (geçersiz durum geçişi, sipariş
 * miktarından fazla teslim alma vb.). Mesaj kullanıcıya gösterilir.
 */
class PurchasingException extends RuntimeException
{
    //
}
