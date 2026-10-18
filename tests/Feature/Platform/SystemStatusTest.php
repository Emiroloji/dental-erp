<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\Contracts\OffsiteBackupSync;
use App\Domain\Platform\Services\SystemStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Aşama 41: Platform Sahibi panelinde sistem durumu — son yedek, sunucu dışı
 * kopya, zamanlanmış görevler, kuyruk ve disk.
 */
class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        config()->set('backup.disk', 'backups');
        config()->set('backup.path', 'backups');
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00'));
    }

    private function system(): SystemStatus
    {
        return app(SystemStatus::class);
    }

    /**
     * @return array{status: string, summary: string}
     */
    private function check(string $key): array
    {
        return collect($this->system()->snapshot()['checks'])->firstWhere('key', $key);
    }

    private function backupAt(string $at): void
    {
        Storage::disk('backups')->put('backups/dental-'.$at.'.sql.gz', 'x');
        touch(Storage::disk('backups')->path('backups/dental-'.$at.'.sql.gz'), CarbonImmutable::parse($at)->getTimestamp());
    }

    private function offsite(bool $configured): void
    {
        $this->app->instance(OffsiteBackupSync::class, new class($configured) implements OffsiteBackupSync
        {
            public function __construct(private readonly bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function push(): array
            {
                return [];
            }

            public function latestBackupAt(): ?CarbonImmutable
            {
                throw new RuntimeException('Panel sunucu dışı hedefe bağlanmamalı.');
            }

            public function describe(): string
            {
                return 'gdrive:yedek';
            }
        });
    }

    public function test_local_backup_age_is_reported(): void
    {
        $this->assertSame('down', $this->check('backup')['status']);

        $this->backupAt('2026-10-16 02:30:00');
        $this->assertSame('ok', $this->check('backup')['status']);
        $this->assertStringContainsString('16.10.2026 02:30', $this->check('backup')['summary']);

        Storage::disk('backups')->deleteDirectory('backups');
        $this->backupAt('2026-10-14 02:30:00');
        $this->assertSame('warn', $this->check('backup')['status']);
    }

    public function test_offsite_status_comes_from_the_last_check_not_a_live_call(): void
    {
        $this->offsite(false);
        $this->assertSame('off', $this->check('offsite')['status']);

        $this->offsite(true);
        $this->assertSame('unknown', $this->check('offsite')['status']);

        $this->system()->recordOffsiteCheck(false, 'En yeni yedek 50 saatlik.');
        $this->assertSame('down', $this->check('offsite')['status']);
        $this->assertStringContainsString('50 saatlik', $this->check('offsite')['summary']);

        $this->system()->recordOffsiteCheck(true, 'Güncel.');
        $this->assertSame('ok', $this->check('offsite')['status']);

        $this->travel(31)->hours();
        $this->assertSame('warn', $this->check('offsite')['status']);
    }

    public function test_offsite_command_records_its_result(): void
    {
        $this->offsite(true);
        $this->app->instance(OffsiteBackupSync::class, new class implements OffsiteBackupSync
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function push(): array
            {
                return [];
            }

            public function latestBackupAt(): ?CarbonImmutable
            {
                return CarbonImmutable::now()->subHours(3);
            }

            public function describe(): string
            {
                return 'gdrive:yedek';
            }
        });

        $this->artisan('backup:check-offsite')->assertSuccessful();

        $this->assertSame('ok', $this->check('offsite')['status']);
    }

    public function test_scheduled_tasks_are_tracked_through_scheduler_events(): void
    {
        $schedule = app(Schedule::class);
        $task = collect($schedule->events())->first(fn ($event) => str_contains($event->command, 'stock:scan-levels'));

        $tasks = collect($this->system()->snapshot()['tasks']);
        $this->assertSame('unknown', $tasks->firstWhere('command', 'stock:scan-levels')['status']);

        $task->exitCode = 0;
        event(new ScheduledTaskFinished($task, 1.2));
        $this->assertSame('ok', collect($this->system()->snapshot()['tasks'])->firstWhere('command', 'stock:scan-levels')['status']);

        // Saatlik görev iki saattir çalışmadı.
        $this->travel(2)->hours();
        $this->assertSame('warn', collect($this->system()->snapshot()['tasks'])->firstWhere('command', 'stock:scan-levels')['status']);

        event(new ScheduledTaskFailed($task, new RuntimeException('Bağlantı koptu')));
        $row = collect($this->system()->snapshot()['tasks'])->firstWhere('command', 'stock:scan-levels');
        $this->assertSame('down', $row['status']);
        $this->assertStringContainsString('Bağlantı koptu', $row['summary']);
    }

    public function test_queue_and_disk_are_included(): void
    {
        $this->assertSame('ok', $this->check('queue')['status']);
        $this->assertContains($this->check('disk')['status'], ['ok', 'warn', 'down']);
        $this->assertStringContainsString('boş', $this->check('disk')['summary']);
    }

    public function test_platform_owner_sees_the_panel_and_tenants_cannot(): void
    {
        $owner = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_PLATFORM_OWNER, 'status' => 'active']);
        $this->backupAt('2026-10-16 02:30:00');

        $this->actingAs($owner);
        Livewire::test('pages::platform.dashboard')
            ->assertSee('Sistem Durumu')
            ->assertSee('Son yedek')
            ->assertSee('Zamanlanmış görevler')
            ->assertSee('notifications:send-digest')
            ->assertSee('Her gün 07:30 (İstanbul)')
            ->assertSee('Saat başı')
            ->assertSee('einvoice:sync')
            ->assertSee('30 dakikada bir')
            ->assertSee('uts:scan-pending');
    }
}
