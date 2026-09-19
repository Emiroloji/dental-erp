<?php

namespace App\Domain\Assistant\Contracts;

use App\Domain\Assistant\Exceptions\InterpreterException;
use App\Domain\Assistant\Support\InterpreterContext;

/**
 * Doğal dildeki soruyu yapılandırılmış rapor sorgusuna çeviren yapay zekâ
 * sağlayıcısı. Uygulamalar (Gemini, ileride Claude...) yalnızca soruyu ve
 * InterpreterContext'i görür; sonucu doğrulamak QueryIntent'in işidir.
 */
interface QueryInterpreter
{
    /**
     * @return array<string, mixed> InterpreterContext::responseSchema() biçiminde ham cevap
     *
     * @throws InterpreterException
     */
    public function interpret(string $question, InterpreterContext $context): array;

    /**
     * Ekranda gösterilecek sağlayıcı adı.
     */
    public function name(): string;
}
