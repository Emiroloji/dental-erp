<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Contracts\OffsiteBackupSync;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;
use Throwable;

/**
 * Aşama 41 — Platform Sahibi panelindeki sistem durumu: son yerel yedek,
 * sunucu dışı kopya, zamanlanmış görevler, kuyruk ve disk.
 *
 * Panel açılırken dış servise (rclone/Google Drive) bağlanılmaz: sunucu dışı
 * kopyanın durumu her sabah çalışan backup:check-offsite'ın kaydettiği
 * sonuçtur. Zamanlanmış görevlerin son çalışması zamanlayıcı olaylarıyla
 * (ScheduledTaskFinished/Failed) önbelleğe yazılır; önbellek canlıda Redis'tir
 * ve zamanlayıcı ile web konteyneri arasında ortaktır.
 *
 * Durumlar: ok, warn, down, unknown (henüz kayıt yok), off (yapılandırılmadı).
 */
class SystemStatus
{
    public const OFFSITE_KEY = 'system-status:offsite';

    public const TASKS_KEY = 'system-status:tasks';

    /** Gece yedeği günde bir; bir günü biraz aşan yaş uyarıdır. */
    private const BACKUP_MAX_AGE_HOURS = 26;

    /** Sunucu dışı kontrol günde bir çalışır; daha eski sonuç, kontrolün çalışmadığını gösterir. */
    private const OFFSITE_CHECK_MAX_AGE_HOURS = 30;

    /** Görevin planlı çalışmasından sonra bitmesi için tanınan süre (dakika). */
    private const TASK_GRACE_MINUTES = 15;

    public function __construct(
        private readonly DatabaseBackupService $backups,
        private readonly OffsiteBackupSync $offsite,
        private readonly QueueHealth $queue,
        private readonly Schedule $schedule,
    ) {}

    public function recordOffsiteCheck(bool $ok, string $message): void
    {
        Cache::forever(self::OFFSITE_KEY, ['ok' => $ok, 'message' => $message, 'checked_at' => CarbonImmutable::now()->toIso8601String()]);
    }

    public function recordTaskRun(Event $task, bool $ok, ?string $error = null): void
    {
        $tasks = Cache::get(self::TASKS_KEY, []);
        $tasks[$this->commandName($task)] = ['ok' => $ok, 'error' => $error, 'finished_at' => CarbonImmutable::now()->toIso8601String()];

        Cache::forever(self::TASKS_KEY, $tasks);
    }

    /**
     * @return array{overall: string, checks: list<array{key: string, label: string, status: string, summary: string}>, tasks: list<array{command: string, expression: string, status: string, summary: string}>}
     */
    public function snapshot(): array
    {
        $tasks = $this->tasks();
        $taskStatus = $this->worst(array_column($tasks, 'status'));
        $late = count(array_filter($tasks, fn (array $task) => in_array($task['status'], ['warn', 'down'], true)));

        $checks = [
            $this->backup(),
            $this->offsite(),
            [
                'key' => 'tasks',
                'label' => 'Zamanlanmış görevler',
                'status' => $taskStatus,
                'summary' => match (true) {
                    $late > 0 => "{$late} görev gecikti veya başarısız oldu.",
                    $taskStatus === 'unknown' => 'Bazı görevlerin henüz çalışma kaydı yok.',
                    default => count($tasks).' görev zamanında çalışıyor.',
                },
            ],
            $this->queue(),
            $this->disk(),
        ];

        return [
            'overall' => $this->worst(array_column($checks, 'status')),
            'checks' => $checks,
            'tasks' => $tasks,
        ];
    }

    /**
     * @return array{key: string, label: string, status: string, summary: string}
     */
    private function backup(): array
    {
        $check = ['key' => 'backup', 'label' => 'Son yedek'];

        try {
            $latest = $this->backups->backups()[0] ?? null;
        } catch (Throwable $e) {
            return [...$check, 'status' => 'down', 'summary' => 'Yedek klasörü okunamadı: '.$e->getMessage()];
        }

        if ($latest === null) {
            return [...$check, 'status' => 'down', 'summary' => 'Hiç yedek yok.'];
        }

        $age = (int) $latest['modified_at']->diffInHours(CarbonImmutable::now());
        $summary = sprintf('%s (%s önce, %s)', $latest['modified_at']->format('d.m.Y H:i'), $this->hours($age), Number::fileSize($latest['size']));

        return [...$check, 'status' => $age > self::BACKUP_MAX_AGE_HOURS ? 'warn' : 'ok', 'summary' => $summary];
    }

    /**
     * @return array{key: string, label: string, status: string, summary: string}
     */
    private function offsite(): array
    {
        $check = ['key' => 'offsite', 'label' => 'Sunucu dışı kopya'];

        if (! $this->offsite->isConfigured()) {
            return [...$check, 'status' => 'off', 'summary' => 'Yapılandırılmadı (BACKUP_OFFSITE_DRIVER).'];
        }

        $last = Cache::get(self::OFFSITE_KEY);

        if ($last === null) {
            return [...$check, 'status' => 'unknown', 'summary' => $this->offsite->describe().' — henüz kontrol edilmedi.'];
        }

        $checkedAt = CarbonImmutable::parse($last['checked_at']);
        $stale = $checkedAt->diffInHours(CarbonImmutable::now()) > self::OFFSITE_CHECK_MAX_AGE_HOURS;
        $summary = $last['message'].' Son kontrol: '.$checkedAt->format('d.m.Y H:i').'.';

        return [...$check, 'status' => ! $last['ok'] ? 'down' : ($stale ? 'warn' : 'ok'), 'summary' => $stale && $last['ok'] ? 'Kontrol bir günden uzun süredir çalışmadı. '.$summary : $summary];
    }

    /**
     * @return array{key: string, label: string, status: string, summary: string}
     */
    private function queue(): array
    {
        $result = $this->queue->check();

        return [
            'key' => 'queue',
            'label' => 'Kuyruk',
            'status' => ['ok' => 'ok', 'degraded' => 'warn'][$result['status']] ?? 'down',
            'summary' => $result['messages'] !== []
                ? implode(' ', $result['messages'])
                : sprintf('Bekleyen iş: %d, son 24 saatte başarısız: %d.', $result['pending'], $result['failed_recently']),
        ];
    }

    /**
     * @return array{key: string, label: string, status: string, summary: string}
     */
    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if (! $free || ! $total) {
            return ['key' => 'disk', 'label' => 'Disk', 'status' => 'unknown', 'summary' => 'Disk bilgisi okunamadı.'];
        }

        $ratio = $free / $total;

        return [
            'key' => 'disk',
            'label' => 'Disk',
            'status' => $ratio < 0.05 ? 'down' : ($ratio < 0.15 ? 'warn' : 'ok'),
            'summary' => sprintf('%s boş / %s (%%%s)', Number::fileSize($free, 1), Number::fileSize($total, 1), Number::format($ratio * 100, 0)),
        ];
    }

    /**
     * @return list<array{command: string, expression: string, status: string, summary: string}> expression: okunur zamanlama
     */
    private function tasks(): array
    {
        $runs = Cache::get(self::TASKS_KEY, []);
        $now = CarbonImmutable::now();

        return collect($this->schedule->events())
            ->map(function (Event $task) use ($runs, $now) {
                $command = $this->commandName($task);
                $run = $runs[$command] ?? null;
                // Toleransı geçmiş en son planlı çalışma: bu andan önce bitmiş bir kayıt olmalı.
                $reference = $now->subMinutes(self::TASK_GRACE_MINUTES)->setTimezone($task->timezone ?? config('app.timezone'));
                $expectedAt = CarbonImmutable::instance((new CronExpression($task->expression))->getPreviousRunDate($reference, 0, true));

                $row = ['command' => $command, 'expression' => $this->describeSchedule($task)];

                if ($run === null) {
                    return [...$row, 'status' => 'unknown', 'summary' => 'Çalışma kaydı yok.'];
                }

                $finishedAt = CarbonImmutable::parse($run['finished_at']);
                $when = $finishedAt->format('d.m.Y H:i');

                if (! $run['ok']) {
                    return [...$row, 'status' => 'down', 'summary' => "{$when} başarısız".($run['error'] ? ': '.$run['error'] : '.')];
                }

                $late = $finishedAt->lt($expectedAt);

                return [...$row, 'status' => $late ? 'warn' : 'ok', 'summary' => $late ? "Son çalışma {$when}; beklenen çalışma gelmedi." : "Son çalışma {$when}."];
            })
            ->values()
            ->all();
    }

    /**
     * Sık kullanılan cron ifadelerini düz Türkçeye çevirir; diğerleri olduğu gibi kalır.
     */
    private function describeSchedule(Event $task): string
    {
        $zone = ($task->timezone ?? config('app.timezone')) === 'Europe/Istanbul' ? 'İstanbul' : 'UTC';

        return match (true) {
            $task->expression === '0 * * * *' => 'Saat başı',
            (bool) preg_match('/^(\d+) (\d+) \* \* \*$/', $task->expression, $m) => sprintf('Her gün %02d:%02d (%s)', $m[2], $m[1], $zone),
            default => $task->expression,
        };
    }

    private function commandName(Event $task): string
    {
        return preg_match("/artisan'?\s+'?([\w:-]+)/", (string) $task->command, $match) ? $match[1] : (string) ($task->description ?? $task->command);
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function worst(array $statuses): string
    {
        foreach (['down', 'warn', 'unknown', 'ok'] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return 'off';
    }

    private function hours(int $hours): string
    {
        return $hours < 1 ? '1 saatten az' : ($hours < 48 ? "{$hours} saat" : intdiv($hours, 24).' gün');
    }
}
