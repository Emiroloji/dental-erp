<?php

namespace App\Domain\Platform\Reporting;

use App\Domain\Platform\Contracts\ErrorReporter;
use Throwable;

/**
 * Hata izlemeyi tamamen kapatır. Testlerin varsayılanı — hiçbir dış servise
 * bağlanılmaz, log da kirletilmez.
 */
class NullErrorReporter implements ErrorReporter
{
    public function report(Throwable $exception, array $context = []): void
    {
        //
    }
}
