<?php

namespace App\Domain\Finance\Support;

/**
 * Finans raporlarındaki SVG grafiklerin geometrisi (JS grafik kütüphanesi yok).
 * Tutarlar kuruş (int); koordinatlar 2 ondalık.
 */
final class FinanceChart
{
    /**
     * @param  list<int>  $values
     * @return array{path: string, area: string, zero_y: float, min: int, max: int, points: list<array{x: float, y: float}>}
     */
    public static function line(array $values, int $width = 720, int $height = 200, int $padding = 8): array
    {
        $values = array_values($values);
        $count = count($values);
        $bottom = (float) ($height - $padding);

        if ($count === 0) {
            return ['path' => '', 'area' => '', 'zero_y' => round($bottom, 2), 'min' => 0, 'max' => 0, 'points' => []];
        }

        $min = min(0, ...$values);
        $max = max(0, ...$values);
        $span = $max - $min;
        $plotH = (float) ($height - 2 * $padding);
        $plotW = (float) ($width - 2 * $padding);

        // Tüm değerler 0 ise aralık 0 olur: sıfır çizgisi altta, bölme yok.
        $yOf = fn (int $v): float => $span === 0
            ? $bottom
            : round($padding + ($max - $v) / $span * $plotH, 2);

        $points = [];
        foreach ($values as $i => $v) {
            $x = $count === 1 ? $width / 2 : $padding + $i / ($count - 1) * $plotW;
            $points[] = ['x' => round($x, 2), 'y' => $yOf($v)];
        }

        $zeroY = $yOf(0);

        // Tek nokta: görünür bir yatay çizgi için kenardan kenara uzat.
        $drawn = $count === 1
            ? [['x' => (float) $padding, 'y' => $points[0]['y']], ['x' => (float) ($width - $padding), 'y' => $points[0]['y']]]
            : $points;

        $path = '';
        foreach ($drawn as $i => $p) {
            $path .= ($i === 0 ? 'M' : ' L').$p['x'].' '.$p['y'];
        }

        $first = $drawn[0];
        $last = $drawn[count($drawn) - 1];
        $area = $path.' L'.$last['x'].' '.$zeroY.' L'.$first['x'].' '.$zeroY.' Z';

        return ['path' => $path, 'area' => $area, 'zero_y' => $zeroY, 'min' => $min, 'max' => $max, 'points' => $points];
    }

    /**
     * Negatif giriş/çıkış 0 kabul edilir (çubuk grafik yalnızca pozitif tutar çizer).
     *
     * @param  list<array{in: int, out: int}>  $groups
     * @return array{bars: list<array{x: float, y: float, width: float, height: float, kind: 'in'|'out'}>, zero_y: float, max: int}
     */
    public static function bars(array $groups, int $width = 720, int $height = 200, int $padding = 8): array
    {
        $groups = array_map(fn (array $g) => ['in' => max(0, $g['in']), 'out' => max(0, $g['out'])], array_values($groups));
        $count = count($groups);
        $zeroY = (float) ($height - $padding);

        if ($count === 0) {
            return ['bars' => [], 'zero_y' => $zeroY, 'max' => 0];
        }

        $max = 0;
        foreach ($groups as $g) {
            $max = max($max, $g['in'], $g['out']);
        }

        $plotH = (float) ($height - 2 * $padding);
        $slot = ($width - 2 * $padding) / $count;
        $barW = $slot * 0.32;
        $gap = 2.0; // iki çubuk arası yüzey boşluğu

        $bars = [];
        foreach ($groups as $i => $g) {
            $left = $padding + $i * $slot + ($slot - (2 * $barW + $gap)) / 2;
            foreach (['in', 'out'] as $k => $kind) {
                $h = $max === 0 ? 0.0 : $g[$kind] / $max * $plotH;
                $bars[] = [
                    'x' => round($left + $k * ($barW + $gap), 2),
                    'y' => round($zeroY - $h, 2),
                    'width' => round($barW, 2),
                    'height' => round($h, 2),
                    'kind' => $kind,
                ];
            }
        }

        return ['bars' => $bars, 'zero_y' => $zeroY, 'max' => $max];
    }
}
