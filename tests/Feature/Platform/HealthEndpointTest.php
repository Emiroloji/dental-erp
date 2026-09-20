<?php

namespace Tests\Feature\Platform;

use App\Domain\Stock\Jobs\ScanStockLevelsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Aşama 28 — /health uç noktası (dışarıdan uptime izleme).
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthy_system_returns_ok_without_authentication(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonPath('checks.queue.status', 'ok')
            ->assertJsonStructure(['status', 'checked_at', 'checks']);
    }

    public function test_it_returns_503_when_the_database_is_unreachable(): void
    {
        DB::shouldReceive('connection')->andThrow(new \RuntimeException('bağlantı yok'));

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('checks.database.status', 'down');
    }

    public function test_a_degraded_queue_still_answers_200(): void
    {
        config()->set('health.queue.pending_threshold', 1);

        Queue::fake();
        ScanStockLevelsJob::dispatch();

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue.status', 'degraded');
    }

    public function test_it_exposes_no_clinic_data(): void
    {
        $response = $this->getJson('/health')->json();

        $this->assertSame(['status', 'checked_at', 'checks'], array_keys($response));
        $this->assertSame(['database', 'cache', 'queue'], array_keys($response['checks']));
    }
}
