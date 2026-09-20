<?php

namespace App\Domain\Platform\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Aşama 28 — kuyruğun sağlığı.
 *
 * Kuyruk worker'ı production'da Supervisor ile ayakta tutulur (bkz.
 * deploy/supervisor). Worker düştüğünde uygulama hata vermez, işler sessizce
 * birikir: rapor dışa aktarımı (GenerateReportExportJob) ve stok taraması
 * (ScanStockLevelsJob) çalışmaz. Bu yüzden bekleyen iş sayısı ve son 24 saatte
 * başarısız olan işler hem /health uç noktasından hem de saatlik çalışan
 * queue:health-check komutundan izlenir.
 */
class QueueHealth
{
    /**
     * @return array{status: string, pending: int|null, failed_recently: int|null, messages: array<int, string>}
     */
    public function check(): array
    {
        $options = config('health.queue');
        $messages = [];

        try {
            $pending = Queue::size();
        } catch (Throwable $exception) {
            return [
                'status' => 'down',
                'pending' => null,
                'failed_recently' => null,
                'messages' => ['Kuyruğa bağlanılamadı: '.$exception->getMessage()],
            ];
        }

        try {
            $failed = (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', CarbonImmutable::now()->subHours($options['failed_window_hours']))
                ->count();
        } catch (Throwable $exception) {
            return [
                'status' => 'down',
                'pending' => $pending,
                'failed_recently' => null,
                'messages' => ['failed_jobs tablosu okunamadı: '.$exception->getMessage()],
            ];
        }

        if ($pending >= $options['pending_threshold']) {
            $messages[] = sprintf(
                '%d iş kuyrukta bekliyor (eşik: %d). Worker çalışmıyor olabilir.',
                $pending,
                $options['pending_threshold'],
            );
        }

        if ($failed >= $options['failed_threshold']) {
            $messages[] = sprintf(
                'Son %d saatte %d iş başarısız oldu (failed_jobs).',
                $options['failed_window_hours'],
                $failed,
            );
        }

        return [
            'status' => $messages === [] ? 'ok' : 'degraded',
            'pending' => $pending,
            'failed_recently' => $failed,
            'messages' => $messages,
        ];
    }
}
