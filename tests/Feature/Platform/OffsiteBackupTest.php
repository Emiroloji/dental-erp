<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\Backup\NullOffsiteBackupSync;
use App\Domain\Platform\Backup\RcloneOffsiteBackupSync;
use App\Domain\Platform\Contracts\OffsiteBackupSync;
use App\Domain\Platform\Notifications\OffsiteBackupAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Aşama 30 — yedeğin sunucu dışına kopyalanması ve doğrulanması.
 *
 * rclone testlerde gerçekten çalıştırılmaz (Process::fake); doğrulanan şey
 * kurulan komut satırı, çıktının okunması ve "yedek eskidi" kararıdır.
 */
class OffsiteBackupTest extends TestCase
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
            'offsite' => [
                'driver' => 'rclone',
                'rclone' => [
                    'binary' => 'rclone',
                    'remote' => 'drive:dental-erp-yedek',
                    'config' => '/etc/rclone/rclone.conf',
                    'timeout' => 900,
                ],
                'max_age_hours' => 48,
                'alert_email' => 'sorumlu@klinik.test',
                'alert_throttle_hours' => 24,
            ],
        ]);
    }

    public function test_the_driver_is_disabled_by_default(): void
    {
        config()->set('backup.offsite.driver', 'none');

        $sync = $this->app->make(OffsiteBackupSync::class);

        $this->assertInstanceOf(NullOffsiteBackupSync::class, $sync);
        $this->assertFalse($sync->isConfigured());
    }

    public function test_rclone_is_selected_when_configured(): void
    {
        $this->assertInstanceOf(RcloneOffsiteBackupSync::class, $this->app->make(OffsiteBackupSync::class));
    }

    public function test_backup_run_copies_the_backup_folder_to_the_remote(): void
    {
        Process::fake([
            'rclone*' => Process::result(
                output: '',
                errorOutput: "2026/09/23 02:31:02 INFO  : dental_erp-2026-09-23-023000.sql.gz: Copied (new)\n",
            ),
            '*' => Process::result(),
        ]);
        $this->travelTo('2026-09-23 02:30:00');

        $this->artisan('backup:run')
            ->expectsOutputToContain('Yedek alındı: dental_erp-2026-09-23-023000.sql.gz')
            ->expectsOutputToContain('drive:dental-erp-yedek hedefine 1 dosya kopyalandı.')
            ->assertSuccessful();

        Process::assertRan(fn ($process) => str_contains($process->command, 'rclone')
            && str_contains($process->command, 'copy')
            && str_contains($process->command, 'drive:dental-erp-yedek')
            && str_contains($process->command, '--config')
            && str_contains($process->command, '*.sql.gz'));
    }

    public function test_backup_run_fails_loudly_when_the_copy_fails(): void
    {
        Process::fake([
            'rclone*' => Process::result(output: '', errorOutput: 'Failed to copy: token expired', exitCode: 1),
            '*' => Process::result(),
        ]);
        $this->travelTo('2026-09-23 02:30:00');

        // Yedek alınmıştır ama sunucu dışına çıkmadığı için komut başarısızdır:
        // sessizce geçerse yedeğin gitmediği ancak felaket anında fark edilir.
        $this->artisan('backup:run')
            ->expectsOutputToContain('Yedek alındı')
            ->expectsOutputToContain('Yedek sunucu dışına kopyalanamadı: Failed to copy: token expired')
            ->assertFailed();

        Storage::disk('backups')->assertExists('backups/dental_erp-2026-09-23-023000.sql.gz');
    }

    public function test_backup_run_warns_but_succeeds_when_offsite_is_not_configured(): void
    {
        config()->set('backup.offsite.driver', 'none');
        Process::fake();
        $this->travelTo('2026-09-23 02:30:00');

        $this->artisan('backup:run')
            ->expectsOutputToContain('Sunucu dışı kopya yapılandırılmadı')
            ->assertSuccessful();
    }

    public function test_check_passes_when_the_remote_backup_is_recent(): void
    {
        $this->travelTo('2026-09-23 06:00:00');
        $this->fakeRemoteListing([
            ['Name' => 'dental_erp-2026-09-23-023000.sql.gz', 'ModTime' => '2026-09-23T02:30:05Z'],
            ['Name' => 'dental_erp-2026-09-22-023000.sql.gz', 'ModTime' => '2026-09-22T02:30:04Z'],
        ]);

        $this->artisan('backup:check-offsite')
            ->expectsOutputToContain('Sunucu dışı yedek güncel: 23.09.2026 02:30')
            ->assertSuccessful();
    }

    public function test_check_fails_when_the_newest_remote_backup_is_too_old(): void
    {
        $this->travelTo('2026-09-23 06:00:00');
        $this->fakeRemoteListing([
            ['Name' => 'dental_erp-2026-09-20-023000.sql.gz', 'ModTime' => '2026-09-20T02:30:05Z'],
        ]);

        $this->artisan('backup:check-offsite')
            ->expectsOutputToContain('en yeni yedek 75 saatlik')
            ->assertFailed();
    }

    public function test_check_fails_when_the_remote_is_empty(): void
    {
        $this->fakeRemoteListing([]);

        $this->artisan('backup:check-offsite')
            ->expectsOutputToContain('hedefinde hiç yedek yok')
            ->assertFailed();
    }

    public function test_check_fails_when_the_remote_cannot_be_reached(): void
    {
        Process::fake([
            'rclone*' => Process::result(output: '', errorOutput: 'directory not found', exitCode: 3),
        ]);

        $this->artisan('backup:check-offsite')
            ->expectsOutputToContain('Sunucu dışı yedek hedefi okunamadı: directory not found')
            ->assertFailed();
    }

    public function test_a_failing_check_emails_the_person_responsible(): void
    {
        Notification::fake();
        $this->fakeRemoteListing([]);

        $this->artisan('backup:check-offsite')
            ->expectsOutputToContain('Uyarı maili gönderildi: sorumlu@klinik.test')
            ->assertFailed();

        Notification::assertSentOnDemand(
            OffsiteBackupAlert::class,
            function (OffsiteBackupAlert $notification, array $channels, object $notifiable) {
                $this->assertSame('sorumlu@klinik.test', $notifiable->routes['mail']);
                $this->assertFalse($notification->resolved);
                $this->assertStringContainsString('hiç yedek yok', (string) $notification->problem);

                return true;
            },
        );
    }

    public function test_no_email_is_sent_when_no_address_is_configured(): void
    {
        Notification::fake();
        config()->set('backup.offsite.alert_email', null);
        $this->fakeRemoteListing([]);

        // Adres yoksa eski davranış sürer: log + hata kodu, sessiz değil.
        $this->artisan('backup:check-offsite')->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_the_same_problem_does_not_email_again_within_the_throttle_window(): void
    {
        Notification::fake();
        $this->fakeRemoteListing([]);

        $this->artisan('backup:check-offsite')->assertFailed();
        $this->artisan('backup:check-offsite')
            ->expectsOutputToContain('Uyarı maili yakın zamanda gönderildi, tekrarlanmıyor.')
            ->assertFailed();

        // Uyarı gürültüye dönüşürse okunmaz olur: sorun sürerken tek mail.
        Notification::assertSentOnDemandTimes(OffsiteBackupAlert::class, 1);
    }

    public function test_the_throttle_expires_so_a_lasting_problem_is_repeated(): void
    {
        Notification::fake();
        $this->fakeRemoteListing([]);

        $this->artisan('backup:check-offsite')->assertFailed();

        $this->travel(25)->hours();
        $this->fakeRemoteListing([]);
        $this->artisan('backup:check-offsite')->assertFailed();

        Notification::assertSentOnDemandTimes(OffsiteBackupAlert::class, 2);
    }

    public function test_recovery_is_reported_once_after_a_problem(): void
    {
        Notification::fake();

        $this->fakeRemoteListing([]);
        $this->artisan('backup:check-offsite')->assertFailed();

        $this->travelTo('2026-09-23 06:00:00');
        $this->fakeRemoteListing([
            ['Name' => 'dental_erp-2026-09-23-023000.sql.gz', 'ModTime' => '2026-09-23T02:30:05Z'],
        ]);

        $this->artisan('backup:check-offsite')
            ->expectsOutputToContain('Düzelme maili gönderildi: sorumlu@klinik.test')
            ->assertSuccessful();

        Notification::assertSentOnDemand(
            OffsiteBackupAlert::class,
            fn (OffsiteBackupAlert $notification) => $notification->resolved === true,
        );

        // İkinci başarılı çalışmada tekrar "düzeldi" maili atılmaz.
        $this->artisan('backup:check-offsite')->assertSuccessful();
        Notification::assertSentOnDemandTimes(OffsiteBackupAlert::class, 2);
    }

    public function test_a_healthy_check_never_emails_when_there_was_no_problem(): void
    {
        Notification::fake();
        Cache::flush();
        $this->travelTo('2026-09-23 06:00:00');
        $this->fakeRemoteListing([
            ['Name' => 'dental_erp-2026-09-23-023000.sql.gz', 'ModTime' => '2026-09-23T02:30:05Z'],
        ]);

        $this->artisan('backup:check-offsite')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /**
     * @param  array<int, array<string, string>>  $files
     */
    private function fakeRemoteListing(array $files): void
    {
        Process::fake([
            'rclone*' => Process::result(output: json_encode($files)),
        ]);
    }
}
