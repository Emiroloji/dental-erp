<?php

namespace App\Domain\Forecasting\Support;

use InvalidArgumentException;

/**
 * Holt doğrusal üstel düzeltme (double exponential smoothing). Zaman serisinin
 * seviyesini ve trendini güncelleyerek bir sonraki dönemi tahmin eder:
 *
 *   seviye_t = α·y_t + (1−α)·(seviye_{t−1} + trend_{t−1})
 *   trend_t  = β·(seviye_t − seviye_{t−1}) + (1−β)·trend_{t−1}
 *   tahmin   = max(0, seviye_n + trend_n)
 *
 * Başlangıç: seviye_0 = y_0, trend_0 = y_1 − y_0 (tek gözlemde 0).
 * Tüketim negatif olamayacağı için tahmin sıfırın altına inmez.
 */
final class HoltForecaster
{
    /**
     * @param  array<int, float>  $series  Eskiden yeniye dönem değerleri
     * @return array{level: float, trend: float, next: float}
     */
    public static function forecast(array $series, float $alpha, float $beta): array
    {
        $series = array_values($series);

        if ($series === []) {
            throw new InvalidArgumentException('Tahmin için en az bir gözlem gerekir.');
        }

        if ($alpha < 0 || $alpha > 1 || $beta < 0 || $beta > 1) {
            throw new InvalidArgumentException('alpha ve beta 0 ile 1 arasında olmalıdır.');
        }

        $level = (float) $series[0];
        $trend = count($series) > 1 ? (float) $series[1] - (float) $series[0] : 0.0;

        for ($t = 1; $t < count($series); $t++) {
            $previousLevel = $level;
            $level = $alpha * (float) $series[$t] + (1 - $alpha) * ($level + $trend);
            $trend = $beta * ($level - $previousLevel) + (1 - $beta) * $trend;
        }

        return [
            'level' => $level,
            'trend' => $trend,
            'next' => max(0.0, $level + $trend),
        ];
    }
}
