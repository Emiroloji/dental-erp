<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\Contracts\ErrorReporter;
use App\Domain\Platform\Reporting\LogErrorReporter;
use App\Domain\Platform\Reporting\NullErrorReporter;
use App\Domain\Platform\Reporting\SentryErrorReporter;
use App\Domain\Platform\Support\ExceptionReporting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Aşama 28 — hata izleme.
 *
 * Testlerde gerçek Sentry'ye bağlanılmaz: varsayılan sürücü "null"dur
 * (phpunit.xml) ve Sentry sürücüsü Http::fake ile sınanır.
 */
class ErrorReportingTest extends TestCase
{
    public function test_server_errors_are_reported_but_expected_errors_are_not(): void
    {
        $this->assertTrue(ExceptionReporting::shouldReport(new RuntimeException('patladı')));
        $this->assertTrue(ExceptionReporting::shouldReport(new HttpException(500, 'sunucu hatası')));

        $this->assertFalse(ExceptionReporting::shouldReport(new NotFoundHttpException));
        $this->assertFalse(ExceptionReporting::shouldReport(new HttpException(403, 'yasak')));
        $this->assertFalse(ExceptionReporting::shouldReport(new AuthorizationException));
        $this->assertFalse(ExceptionReporting::shouldReport(new ModelNotFoundException));
        $this->assertFalse(ExceptionReporting::shouldReport(ValidationException::withMessages(['ad' => 'zorunlu'])));
    }

    public function test_driver_defaults_to_log_when_no_dsn_is_configured(): void
    {
        config()->set('errors.driver', 'sentry');
        config()->set('errors.sentry.dsn', null);

        $this->assertInstanceOf(LogErrorReporter::class, $this->app->make(ErrorReporter::class));
    }

    public function test_sentry_driver_is_used_when_a_dsn_is_configured(): void
    {
        config()->set('errors.driver', 'sentry');
        config()->set('errors.sentry.dsn', 'https://abc123@o1.ingest.sentry.io/42');

        $this->assertInstanceOf(SentryErrorReporter::class, $this->app->make(ErrorReporter::class));
    }

    public function test_tests_run_with_the_disabled_driver(): void
    {
        $this->assertInstanceOf(NullErrorReporter::class, $this->app->make(ErrorReporter::class));
    }

    public function test_log_driver_writes_the_exception_with_its_context(): void
    {
        Log::spy();

        (new LogErrorReporter)->report(new RuntimeException('stok servisi patladı'), ['user_id' => 7]);

        Log::shouldHaveReceived('error')->once();
    }

    public function test_sentry_driver_posts_the_event_to_the_store_endpoint(): void
    {
        Http::fake(['*' => Http::response(['id' => 'abc'], 200)]);

        config()->set('errors.driver', 'sentry');
        config()->set('errors.sentry.dsn', 'https://publickey@o1.ingest.sentry.io/42');
        config()->set('errors.sentry.environment', 'production');

        $this->app->make(ErrorReporter::class)->report(
            new RuntimeException('stok servisi patladı'),
            ['user_id' => 7, 'organization_id' => 3, 'url' => 'https://klinik.test/stok-girisleri'],
        );

        Http::assertSent(function ($request) {
            $this->assertSame('https://o1.ingest.sentry.io/api/42/store/', $request->url());
            $this->assertStringContainsString('sentry_key=publickey', $request->header('X-Sentry-Auth')[0]);

            $body = $request->data();

            return $body['level'] === 'error'
                && $body['environment'] === 'production'
                && $body['exception']['values'][0]['type'] === RuntimeException::class
                && $body['exception']['values'][0]['value'] === 'stok servisi patladı'
                && $body['user']['id'] === 7
                && $body['tags']['organization_id'] === 3;
        });
    }

    public function test_a_failing_sentry_call_does_not_break_the_request(): void
    {
        Http::fake(['*' => Http::response('bozuk', 500)]);
        Log::spy();

        config()->set('errors.driver', 'sentry');
        config()->set('errors.sentry.dsn', 'https://publickey@o1.ingest.sentry.io/42');

        $this->app->make(ErrorReporter::class)->report(new RuntimeException('patladı'));

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_invalid_dsn_is_reported_as_a_warning_only(): void
    {
        Http::fake();
        Log::spy();

        config()->set('errors.driver', 'sentry');
        config()->set('errors.sentry.dsn', 'bozuk-dsn');

        $this->app->make(ErrorReporter::class)->report(new RuntimeException('patladı'));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_the_exception_handler_only_forwards_server_errors_to_the_reporter(): void
    {
        $fake = new class implements ErrorReporter
        {
            /** @var array<int, array{0: \Throwable, 1: array<string, mixed>}> */
            public array $reported = [];

            public function report(\Throwable $exception, array $context = []): void
            {
                $this->reported[] = [$exception, $context];
            }
        };

        $this->app->instance(ErrorReporter::class, $fake);

        report(new RuntimeException('stok servisi patladı'));
        report(new NotFoundHttpException);

        $this->assertCount(1, $fake->reported);
        $this->assertSame('stok servisi patladı', $fake->reported[0][0]->getMessage());
        $this->assertSame('testing', $fake->reported[0][1]['environment']);
    }

    public function test_context_is_collected_from_the_console_without_a_request(): void
    {
        $context = ExceptionReporting::context();

        $this->assertSame('console', $context['source']);
        $this->assertSame('testing', $context['environment']);
    }
}
