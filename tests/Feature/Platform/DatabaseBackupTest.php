<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\Services\DatabaseBackupService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Aşama 28 — veritabanı yedekleme ve geri yükleme.
 *
 * Testlerde pg_dump/psql gerçekten çalıştırılmaz (Process::fake); doğrulanan
 * şey kurulan komut satırı, dosyanın diske yazılması ve saklama süresi
 * temizliğidir. Komutun gerçek PostgreSQL üzerindeki çalışması
 * docs/yedekleme.md'deki geri yükleme provasıyla ayrıca doğrulanmıştır.
 */
class DatabaseBackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');

        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => 'db.test',
            'port' => '5432',
            'username' => 'dental_erp',
            'password' => 'gizli',
            'database' => 'dental_erp',
        ]);
        config()->set('backup', [
            'disk' => 'backups',
            'path' => 'backups',
            'retention_days' => 14,
            'pg_dump' => 'pg_dump',
            'psql' => 'psql',
            'timeout' => 900,
        ]);
    }

    private function service(): DatabaseBackupService
    {
        return $this->app->make(DatabaseBackupService::class);
    }

    public function test_backup_runs_pg_dump_and_stores_a_compressed_file(): void
    {
        Process::fake();
        $this->travelTo('2026-09-21 02:30:00');

        $this->artisan('backup:run')
            ->expectsOutputToContain('Yedek alındı: dental_erp-2026-09-21-023000.sql.gz')
            ->assertSuccessful();

        Storage::disk('backups')->assertExists('backups/dental_erp-2026-09-21-023000.sql.gz');

        Process::assertRan(function ($process) {
            $this->assertSame('gizli', $process->environment['PGPASSWORD']);

            return str_contains($process->command, 'pg_dump')
                && str_contains($process->command, "--host='db.test'")
                && str_contains($process->command, "--dbname='dental_erp'")
                && str_contains($process->command, '--clean --if-exists')
                && str_contains($process->command, '| gzip -9 > ');
        });
    }

    public function test_backup_fails_clearly_when_the_connection_is_not_postgres(): void
    {
        Process::fake();
        config()->set('database.default', 'sqlite');

        $this->artisan('backup:run')
            ->expectsOutputToContain('yalnızca PostgreSQL')
            ->assertFailed();

        Process::assertNothingRan();
        $this->assertSame([], Storage::disk('backups')->files('backups'));
    }

    public function test_backup_prunes_files_older_than_the_retention_period(): void
    {
        Process::fake();
        $this->travelTo('2026-09-21 02:30:00');

        $this->putBackup('dental_erp-2026-09-06-023000.sql.gz', now()->subDays(15));
        $this->putBackup('dental_erp-2026-09-18-023000.sql.gz', now()->subDays(3));

        $this->artisan('backup:run')
            ->expectsOutputToContain('1 eski yedek silindi')
            ->assertSuccessful();

        Storage::disk('backups')->assertMissing('backups/dental_erp-2026-09-06-023000.sql.gz');
        Storage::disk('backups')->assertExists('backups/dental_erp-2026-09-18-023000.sql.gz');
        Storage::disk('backups')->assertExists('backups/dental_erp-2026-09-21-023000.sql.gz');
    }

    public function test_only_sql_gz_files_are_listed_as_backups(): void
    {
        Storage::disk('backups')->put('backups/not-a-backup.txt', 'x');
        $this->putBackup('dental_erp-2026-09-18-023000.sql.gz', now());

        $names = array_column($this->service()->backups(), 'name');

        $this->assertSame(['dental_erp-2026-09-18-023000.sql.gz'], $names);
    }

    public function test_restore_pipes_the_backup_into_psql(): void
    {
        Process::fake();
        $this->putBackup('dental_erp-2026-09-18-023000.sql.gz', now());

        $this->artisan('backup:restore', ['file' => 'dental_erp-2026-09-18-023000.sql.gz', '--force' => true])
            ->expectsOutputToContain('Yedek geri yüklendi')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $this->assertSame('gizli', $process->environment['PGPASSWORD']);

            return str_starts_with($process->command, 'gunzip -c ')
                && str_contains($process->command, '| psql')
                && str_contains($process->command, 'ON_ERROR_STOP=on');
        });
    }

    public function test_restore_asks_for_confirmation_before_overwriting(): void
    {
        Process::fake();
        $this->putBackup('dental_erp-2026-09-18-023000.sql.gz', now());

        $this->artisan('backup:restore', ['file' => 'dental_erp-2026-09-18-023000.sql.gz'])
            ->expectsConfirmation('Devam edilsin mi?', 'no')
            ->expectsOutputToContain('İşlem iptal edildi.')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_restore_fails_when_the_file_does_not_exist(): void
    {
        Process::fake();
        $this->putBackup('dental_erp-2026-09-18-023000.sql.gz', now());

        $this->artisan('backup:restore', ['file' => 'olmayan.sql.gz', '--force' => true])
            ->expectsOutputToContain('Yedek dosyası bulunamadı')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_restore_fails_when_there_is_no_backup_at_all(): void
    {
        Process::fake();

        $this->artisan('backup:restore', ['--force' => true])
            ->expectsOutputToContain('geri yüklenebilecek bir yedek yok')
            ->assertFailed();
    }

    public function test_failed_pg_dump_is_reported_and_leaves_no_file_behind(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'pg_dump: bağlanılamadı', exitCode: 1),
        ]);

        $this->artisan('backup:run')
            ->expectsOutputToContain('Yedek alınamadı: pg_dump: bağlanılamadı')
            ->assertFailed();

        $this->assertSame([], Storage::disk('backups')->files('backups'));
    }

    private function putBackup(string $name, \DateTimeInterface $modifiedAt): void
    {
        Storage::disk('backups')->put('backups/'.$name, 'yedek');

        touch(Storage::disk('backups')->path('backups/'.$name), $modifiedAt->getTimestamp());
    }
}
