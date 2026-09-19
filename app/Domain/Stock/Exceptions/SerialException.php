<?php

namespace App\Domain\Stock\Exceptions;

use RuntimeException;

/**
 * Seri numarası kuralı ihlali (Aşama 26): eksik/fazla seri, zaten stokta
 * olan seri, bu depoda bulunmayan seri vb. Mesaj kullanıcıya gösterilir.
 */
class SerialException extends RuntimeException
{
    //
}
