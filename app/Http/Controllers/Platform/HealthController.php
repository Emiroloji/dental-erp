<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\Services\QueueHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aşama 28 — dışarıdan izleme için sağlık kontrolü.
 *
 * Laravel'in hazır /up uç noktası yalnızca "PHP ayakta mı" sorusuna cevap
 * verir. Bu uç nokta uygulamanın gerçekten çalışabilmesi için gereken üç
 * bağımlılığı kontrol eder: veritabanı, cache ve kuyruk.
 *
 * Dönüş: bağımlılıklardan biri erişilemezse 503, aksi hâlde 200. Kuyruğun
 * "degraded" olması (biriken veya başarısız iş) 200 döner — uptime izleme
 * servisi alarm vermemeli, ama durum yine de cevapta görünür.
 */
class HealthController
{
    public function __invoke(QueueHealth $queue): JsonResponse
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'queue' => $queue->check(),
        ];

        $statuses = array_column($checks, 'status');

        $status = match (true) {
            in_array('down', $statuses, true) => 'down',
            in_array('degraded', $statuses, true) => 'degraded',
            default => 'ok',
        };

        return response()->json([
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ], $status === 'down' ? 503 : 200);
    }

    /**
     * @return array{status: string, messages?: array<int, string>}
     */
    private function database(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return ['status' => 'down', 'messages' => ['Veritabanına bağlanılamadı: '.$exception->getMessage()]];
        }
    }

    /**
     * @return array{status: string, messages?: array<int, string>}
     */
    private function cache(): array
    {
        $key = 'health:'.Str::random(8);

        try {
            Cache::put($key, 'ok', 10);

            if (Cache::get($key) !== 'ok') {
                return ['status' => 'down', 'messages' => ['Cache yazıldı ama geri okunamadı.']];
            }

            Cache::forget($key);

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return ['status' => 'down', 'messages' => ['Cache\'e bağlanılamadı: '.$exception->getMessage()]];
        }
    }
}
