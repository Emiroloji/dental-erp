<?php

namespace Tests\Unit\Finance;

use App\Domain\Finance\Support\FinanceChart;
use PHPUnit\Framework\TestCase;

class FinanceChartTest extends TestCase
{
    public function test_cizgi_sifir_cizgisini_her_zaman_gorunur_tutar(): void
    {
        $c = FinanceChart::line([0, 100, -100], 720, 200, 8);

        $this->assertSame(-100, $c['min']);
        $this->assertSame(100, $c['max']);
        $this->assertEqualsWithDelta(100.0, $c['zero_y'], 0.01);
        $this->assertCount(3, $c['points']);
        $this->assertEqualsWithDelta(8.0, $c['points'][0]['x'], 0.01);
        $this->assertEqualsWithDelta(712.0, $c['points'][2]['x'], 0.01);
        $this->assertNotSame('', $c['path']);
    }

    public function test_esit_degerler_duz_cizgi_uretir_ve_sifira_bolmez(): void
    {
        $c = FinanceChart::line([5, 5, 5], 720, 200, 8);

        $this->assertEqualsWithDelta(192.0, $c['zero_y'], 0.01);
        $this->assertNotSame('', $c['path']);
        $this->assertStringNotContainsString('NAN', strtoupper($c['path'].$c['area']));
        $this->assertStringNotContainsString('INF', strtoupper($c['path'].$c['area']));

        $z = FinanceChart::line([0, 0], 720, 200, 8);
        $this->assertStringNotContainsString('NAN', strtoupper($z['path']));
    }

    public function test_bos_dizi_bos_path_dondurur(): void
    {
        $c = FinanceChart::line([]);

        $this->assertSame('', $c['path']);
        $this->assertSame([], $c['points']);
    }

    public function test_tek_deger_yatay_cizgi_cizer(): void
    {
        $c = FinanceChart::line([50]);

        $this->assertCount(1, $c['points']);
        $this->assertStringContainsString('L', $c['path']);
    }

    public function test_cubuklar_en_buyuk_degere_gore_olceklenir(): void
    {
        $c = FinanceChart::bars([['in' => 100, 'out' => 50], ['in' => 0, 'out' => 0]], 720, 200, 8);

        $this->assertCount(4, $c['bars']);
        $this->assertSame(100, $c['max']);
        $this->assertEqualsWithDelta(184.0, $c['bars'][0]['height'], 0.01);
        $this->assertSame('in', $c['bars'][0]['kind']);
        $this->assertEqualsWithDelta(92.0, $c['bars'][1]['height'], 0.01);
        $this->assertSame('out', $c['bars'][1]['kind']);
        $this->assertEqualsWithDelta(0.0, $c['bars'][2]['height'], 0.01);
        $this->assertEqualsWithDelta(0.0, $c['bars'][3]['height'], 0.01);
    }

    public function test_tumu_sifir_olan_cubuklarin_yuksekligi_sifirdir(): void
    {
        $c = FinanceChart::bars([['in' => 0, 'out' => 0]]);

        $this->assertSame(0, $c['max']);
        $this->assertEqualsWithDelta(0.0, $c['bars'][0]['height'], 0.01);
    }
}
