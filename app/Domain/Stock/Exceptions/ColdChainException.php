<?php

namespace App\Domain\Stock\Exceptions;

use RuntimeException;

/**
 * Soğuk zincir ürününün girişinde sıcaklık girilmedi veya ölçüm saklama
 * aralığı dışında ve kabul gerekçesi yazılmadı (Aşama 26).
 */
class ColdChainException extends RuntimeException
{
    //
}
