<?php

namespace App\Domain\Access\Exceptions;

use RuntimeException;

/**
 * Personel kaydı üzerinde iş kuralına aykırı bir değişiklik denendi
 * (ör. kişinin kendi hesabını pasifleştirmesi). Mesaj kullanıcıya gösterilir.
 */
class StaffRuleException extends RuntimeException
{
    //
}
