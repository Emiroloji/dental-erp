<?php

namespace App\Domain\Transfer\Exceptions;

use RuntimeException;

/**
 * Transfer iş kuralına aykırı bir işlem (geçersiz durum geçişi, aynı depoya
 * transfer vb.). Mesaj kullanıcıya gösterilir.
 */
class TransferException extends RuntimeException
{
    //
}
