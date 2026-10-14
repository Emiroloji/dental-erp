<?php

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * Finans iş kuralı ihlali; mesajı kullanıcıya olduğu gibi gösterilir. field,
 * hatanın formda hangi alanın altında gösterileceğini söyler (ekranlar mesaj
 * metnine bakarak tahmin etmesin — mesaj kullanıcı adları içerebilir).
 */
class FinanceRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
