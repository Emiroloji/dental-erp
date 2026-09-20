<?php

namespace App\Domain\Platform\Contracts;

use Throwable;

/**
 * Aşama 28 — hata izleme sağlayıcısı arayüzü.
 *
 * Rapor Asistanı'ndaki QueryInterpreter ile aynı yaklaşım: uygulama koda
 * sağlayıcı adı yazmaz, yalnızca bu arayüzü çağırır. Sağlayıcı değişirse
 * (Sentry → Bugsnag, self-hosted GlitchTip...) yalnızca yeni bir uygulama
 * yazılır ve AppServiceProvider'daki bağlama güncellenir.
 */
interface ErrorReporter
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function report(Throwable $exception, array $context = []): void;
}
