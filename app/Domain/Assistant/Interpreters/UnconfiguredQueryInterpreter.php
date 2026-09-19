<?php

namespace App\Domain\Assistant\Interpreters;

use App\Domain\Assistant\Contracts\QueryInterpreter;
use App\Domain\Assistant\Exceptions\InterpreterException;
use App\Domain\Assistant\Support\InterpreterContext;

/**
 * API anahtarı girilmemişse kullanılır: hiçbir dış çağrı yapmaz.
 */
class UnconfiguredQueryInterpreter implements QueryInterpreter
{
    public function name(): string
    {
        return 'Yapılandırılmamış';
    }

    public function interpret(string $question, InterpreterContext $context): array
    {
        throw new InterpreterException('Rapor asistanı henüz yapılandırılmadı (yapay zekâ API anahtarı eksik). Sistem yöneticinize başvurun.');
    }
}
