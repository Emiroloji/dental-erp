<?php

namespace App\Providers;

use App\Domain\Assistant\Contracts\QueryInterpreter;
use App\Domain\Assistant\Interpreters\GeminiQueryInterpreter;
use App\Domain\Assistant\Interpreters\UnconfiguredQueryInterpreter;
use App\Domain\Organization\Support\ReadOnlyGuard;
use App\Domain\Platform\Backup\NullOffsiteBackupSync;
use App\Domain\Platform\Backup\RcloneOffsiteBackupSync;
use App\Domain\Platform\Contracts\ErrorReporter;
use App\Domain\Platform\Contracts\OffsiteBackupSync;
use App\Domain\Platform\Exceptions\BackupException;
use App\Domain\Platform\Reporting\LogErrorReporter;
use App\Domain\Platform\Reporting\NullErrorReporter;
use App\Domain\Platform\Reporting\SentryErrorReporter;
use App\Domain\Platform\Services\DatabaseBackupService;
use App\Http\Middleware\EnsurePlatformOwner;
use App\Http\Middleware\EnsureTenantAccess;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Rapor Asistanı (Aşama 25): sağlayıcı arayüz arkasında. Yeni sağlayıcı
        // (ör. Claude) eklemek için QueryInterpreter uygulanır ve buraya eklenir.
        $this->app->bind(QueryInterpreter::class, function () {
            $gemini = config('services.gemini');

            return match (config('assistant.driver')) {
                'gemini' => filled($gemini['key'] ?? null)
                    ? new GeminiQueryInterpreter($gemini['key'], $gemini['model'], $gemini['endpoint'], (int) $gemini['timeout'])
                    : new UnconfiguredQueryInterpreter,
                default => new UnconfiguredQueryInterpreter,
            };
        });

        // Aşama 28: hata izleme sağlayıcısı arayüz arkasında. SENTRY_DSN
        // tanımlıysa olaylar Sentry'ye gider, tanımlı değilse log'a yazılır.
        $this->app->bind(ErrorReporter::class, function () {
            $sentry = config('errors.sentry');

            return match (config('errors.driver')) {
                'sentry' => filled($sentry['dsn'] ?? null)
                    ? new SentryErrorReporter(
                        $this->app->make(HttpClient::class),
                        $sentry['dsn'],
                        (string) $sentry['environment'],
                        $sentry['release'] ?: null,
                        (int) $sentry['timeout'],
                    )
                    : new LogErrorReporter(config('errors.log_channel')),
                'none' => new NullErrorReporter,
                default => new LogErrorReporter(config('errors.log_channel')),
            };
        });

        // Aşama 28: yedekleme servisi aktif veritabanı bağlantısını ve
        // config/backup.php ayarlarını kullanır.
        $this->app->bind(DatabaseBackupService::class, fn () => new DatabaseBackupService(
            config('database.connections.'.config('database.default')),
            config('backup'),
        ));

        // Aşama 30: yedeğin sunucu dışına kopyalanması da arayüz arkasında.
        // Yerelde ve testlerde kapalıdır; sunucuda BACKUP_OFFSITE_DRIVER=rclone
        // ile açılır.
        $this->app->bind(OffsiteBackupSync::class, function () {
            $offsite = config('backup.offsite');

            return match ($offsite['driver'] ?? 'none') {
                'rclone' => new RcloneOffsiteBackupSync(
                    $this->localBackupDirectory(),
                    $offsite['rclone']['remote'] ?? null,
                    (string) ($offsite['rclone']['binary'] ?? 'rclone'),
                    $offsite['rclone']['config'] ?? null,
                    (int) ($offsite['rclone']['timeout'] ?? 900),
                ),
                default => new NullOffsiteBackupSync,
            };
        });
    }

    /**
     * rclone dosya sistemi üzerinden çalışır, Flysystem üzerinden değil; bu
     * yüzden yedek diskinin yerel olması gerekir. Zaten tasarım da bu:
     * uygulama yedeği yerele yazar, rclone onu dışarı taşır.
     */
    private function localBackupDirectory(): string
    {
        $disk = (string) config('backup.disk');
        $path = trim((string) config('backup.path'), '/');

        $filesystem = Storage::disk($disk);

        if (! $filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new BackupException(
                "Sunucu dışı kopya için BACKUP_DISK yerel bir disk olmalı, \"{$disk}\" değil."
            );
        }

        return $filesystem->path($path);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ekrandaki tüm sayılar uygulama diliyle biçimlenir (tr: 1.234,50).
        Number::useLocale($this->app->getLocale());

        // Livewire'ın sonraki (update) istekleri de sayfanın route middleware'inden geçer.
        Livewire::addPersistentMiddleware([EnsureTenantAccess::class, EnsurePlatformOwner::class]);

        // Salt-okunur organizasyon (Aşama 18): her Eloquent yazması tek noktadan denetlenir.
        Event::listen(['eloquent.creating: *', 'eloquent.updating: *', 'eloquent.deleting: *'], function (string $event, array $payload) {
            $this->app->make(ReadOnlyGuard::class)->check($payload[0]);
        });
    }
}
