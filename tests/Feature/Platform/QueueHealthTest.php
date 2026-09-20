<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\Services\QueueHealth;
use App\Domain\Stock\Jobs\ScanStockLevelsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aşama 28 — kuyruk sağlığı izleme.
 */
class QueueHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_queue_without_failures_is_healthy(): void
    {
        $this->artisan('queue:health-check')
            ->expectsOutputToContain('Kuyruk sağlıklı')
            ->assertSuccessful();
    }

    public function test_recently_failed_job_is_reported(): void
    {
        Log::spy();

        $this->insertFailedJob(now()->subHours(2));

        $this->artisan('queue:health-check')
            ->expectsOutputToContain('Son 24 saatte 1 iş başarısız oldu')
            ->assertFailed();

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_old_failures_outside_the_window_are_ignored(): void
    {
        $this->insertFailedJob(now()->subDays(3));

        $this->artisan('queue:health-check')
            ->expectsOutputToContain('Kuyruk sağlıklı')
            ->assertSuccessful();
    }

    public function test_pending_backlog_above_the_threshold_is_reported(): void
    {
        config()->set('health.queue.pending_threshold', 1);

        Queue::fake();
        ScanStockLevelsJob::dispatch();

        $this->artisan('queue:health-check')
            ->expectsOutputToContain('iş kuyrukta bekliyor')
            ->assertFailed();
    }

    public function test_health_result_carries_the_counts(): void
    {
        $this->insertFailedJob(now()->subMinutes(5));

        $result = app(QueueHealth::class)->check();

        $this->assertSame('degraded', $result['status']);
        $this->assertSame(0, $result['pending']);
        $this->assertSame(1, $result['failed_recently']);
    }

    private function insertFailedJob(\DateTimeInterface $failedAt): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test',
            'failed_at' => $failedAt,
        ]);
    }
}
