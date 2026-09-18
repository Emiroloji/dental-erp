<?php

namespace App\Domain\Organization\Exceptions;

use RuntimeException;

/**
 * Şube/depo tanımı üzerinde iş kuralına aykırı bir değişiklik denendi
 * (ör. stoğu olan bir depoyu pasifleştirmek). Mesaj kullanıcıya gösterilir.
 */
class LocationRuleException extends RuntimeException
{
    //
}
