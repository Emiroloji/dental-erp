<?php

namespace App\Domain\Stock\Exceptions;

use RuntimeException;

/**
 * kurallar.md Bölüm 4: pasif şube/depo üzerinde hiçbir stok işlemi yapılamaz.
 */
class InactiveLocationException extends RuntimeException
{
    //
}
