<?php

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

/**
 * Ürün kartında iş kuralına aykırı bir değişiklik (ör. stoğu varken seri
 * takibini açıp kapatmak). Mesaj kullanıcıya gösterilir.
 */
class ProductRuleException extends RuntimeException
{
    //
}
