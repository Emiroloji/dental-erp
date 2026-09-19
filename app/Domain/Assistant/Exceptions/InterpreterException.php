<?php

namespace App\Domain\Assistant\Exceptions;

use RuntimeException;

/**
 * Yapay zekâ sağlayıcısına ulaşılamadı, yapılandırılmamış veya geçersiz cevap
 * döndü. Mesaj kullanıcıya gösterilir; teknik ayrıntı loglanır.
 */
class InterpreterException extends RuntimeException
{
    //
}
