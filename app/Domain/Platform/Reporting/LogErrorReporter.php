<?php

namespace App\Domain\Platform\Reporting;

use App\Domain\Platform\Contracts\ErrorReporter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sentry (veya başka bir servis) yapılandırılmadığında devreye giren
 * varsayılan sürücü. Laravel zaten exception'ı log'a yazar; buradaki kayıt
 * onun yanına "hangi istek, hangi kullanıcı, hangi organizasyon" bağlamını
 * tek satırda ekler — canlıda bir hatayı kullanıcıyla eşleştirebilmek için
 * gereken asgari bilgi budur.
 */
class LogErrorReporter implements ErrorReporter
{
    public function __construct(private readonly ?string $channel = null) {}

    public function report(Throwable $exception, array $context = []): void
    {
        $logger = $this->channel ? Log::channel($this->channel) : Log::getFacadeRoot();

        $logger->error('[hata-izleme] '.$exception::class.': '.$exception->getMessage(), [
            'file' => $exception->getFile().':'.$exception->getLine(),
            ...$context,
        ]);
    }
}
