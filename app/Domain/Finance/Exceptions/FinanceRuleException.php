<?php

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * Finans iş kuralı ihlali; mesajı kullanıcıya olduğu gibi gösterilir.
 */
class FinanceRuleException extends RuntimeException {}
