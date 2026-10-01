<?php

namespace App\Domain\Uts\Exceptions;

use RuntimeException;

/**
 * ÜTS'ye ulaşılamadı, yapılandırılmamış veya ÜTS bir hata döndürdü. Mesaj
 * kullanıcıya gösterilir; token hiçbir zaman mesaja girmez.
 */
class UtsException extends RuntimeException
{
    //
}
