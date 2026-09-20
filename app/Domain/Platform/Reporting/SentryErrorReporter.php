<?php

namespace App\Domain\Platform\Reporting;

use App\Domain\Platform\Contracts\ErrorReporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aşama 28 — 500 hatalarını Sentry'ye gönderen sürücü.
 *
 * Sentry'nin "store" uç noktasına doğrudan HTTP ile yazar; resmî SDK'ya
 * bağımlılık yoktur. Böylece uygulama tek bir arayüze (ErrorReporter) bağlı
 * kalır, sağlayıcı değiştirmek bir sınıf yazmaktan ibarettir.
 *
 * Raporlamanın kendisi hata verirse (Sentry erişilemez, DSN yanlış) uygulama
 * bundan etkilenmez: sorun log'a yazılır ve istek normal akışına devam eder.
 */
class SentryErrorReporter implements ErrorReporter
{
    private const MAX_FRAMES = 25;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $dsn,
        private readonly string $environment,
        private readonly ?string $release = null,
        private readonly int $timeout = 5,
    ) {}

    public function report(Throwable $exception, array $context = []): void
    {
        try {
            $dsn = $this->parseDsn();

            $this->http
                ->timeout($this->timeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Sentry-Auth' => $this->authHeader($dsn['public_key']),
                ])
                ->post($dsn['store_url'], $this->payload($exception, $context))
                ->throw();
        } catch (Throwable $failure) {
            Log::warning('[hata-izleme] Sentry\'ye gönderilemedi: '.$failure->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Throwable $exception, array $context): array
    {
        return [
            'event_id' => str_replace('-', '', (string) Str::uuid()),
            'timestamp' => CarbonImmutable::now()->toIso8601String(),
            'platform' => 'php',
            'level' => 'error',
            'logger' => 'dental-erp',
            'environment' => $this->environment,
            'release' => $this->release,
            'server_name' => gethostname() ?: null,
            'transaction' => $context['url'] ?? null,
            'user' => array_filter([
                'id' => $context['user_id'] ?? null,
                'email' => $context['user_email'] ?? null,
            ]),
            'tags' => array_filter([
                'organization_id' => $context['organization_id'] ?? null,
            ]),
            'extra' => $context,
            'exception' => [
                'values' => [[
                    'type' => $exception::class,
                    'value' => $exception->getMessage(),
                    'stacktrace' => ['frames' => $this->frames($exception)],
                ]],
            ],
        ];
    }

    /**
     * Sentry çerçeveleri en eskiden en yeniye bekler; getTrace() ters sırada
     * verir. En yakın MAX_FRAMES çerçeve yeterlidir.
     *
     * @return array<int, array<string, mixed>>
     */
    private function frames(Throwable $exception): array
    {
        $frames = [[
            'filename' => $exception->getFile(),
            'lineno' => $exception->getLine(),
            'function' => null,
        ]];

        foreach (array_slice($exception->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $frames[] = [
                'filename' => $frame['file'] ?? '[internal]',
                'lineno' => $frame['line'] ?? 0,
                'function' => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''),
            ];
        }

        return array_reverse($frames);
    }

    /**
     * @return array{public_key: string, store_url: string}
     */
    private function parseDsn(): array
    {
        $parts = parse_url($this->dsn);

        if (! isset($parts['scheme'], $parts['host'], $parts['user'], $parts['path'])) {
            throw new \InvalidArgumentException('SENTRY_DSN biçimi geçersiz.');
        }

        $projectId = trim($parts['path'], '/');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return [
            'public_key' => $parts['user'],
            'store_url' => "{$parts['scheme']}://{$parts['host']}{$port}/api/{$projectId}/store/",
        ];
    }

    private function authHeader(string $publicKey): string
    {
        return implode(', ', [
            'Sentry sentry_version=7',
            'sentry_client=dental-erp/1.0',
            'sentry_key='.$publicKey,
        ]);
    }
}
