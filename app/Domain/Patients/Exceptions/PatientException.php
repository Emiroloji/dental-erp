<?php

namespace App\Domain\Patients\Exceptions;

use RuntimeException;

/**
 * Hasta kaydı kuralı ihlali; mesaj kullanıcıya gösterilir. $field, hatanın
 * bağlanacağı form alanıdır.
 */
class PatientException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'identity_no')
    {
        parent::__construct($message);
    }
}
