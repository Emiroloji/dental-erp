<?php

namespace App\Domain\Platform\Exceptions;

use RuntimeException;

/**
 * Platform Sahibi hesabı üzerinde kurala aykırı bir değişiklik denendi
 * (ör. son aktif hesabı pasife almak). Mesaj kullanıcıya gösterilir.
 */
class PlatformAccountException extends RuntimeException
{
    //
}
