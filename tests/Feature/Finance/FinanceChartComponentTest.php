<?php

namespace Tests\Feature\Finance;

use Tests\TestCase;

class FinanceChartComponentTest extends TestCase
{
    public function test_cizgi_grafigi_erisilebilir_svg_uretir(): void
    {
        $html = (string) $this->blade(
            '<x-finance.line-chart :values="$v" :labels="[\'1 Eki\', \'31 Eki\']" title="Nakit akışı" />',
            ['v' => [0, 150000, -250000]]
        );

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('aria-label="Nakit akışı"', $html);
        $this->assertStringContainsString('−2.500,00 ₺', $html);
        $this->assertStringNotContainsString('NaN', $html);
    }

    public function test_cubuk_grafigi_svg_uretir_ve_bos_veride_metin_gosterir(): void
    {
        $html = (string) $this->blade(
            '<x-finance.bar-chart :groups="$g" :labels="[\'Eki\', \'Kas\']" title="Gelir gider" />',
            ['g' => [['in' => 1000, 'out' => 400], ['in' => 0, 'out' => 0]]]
        );
        $this->assertStringContainsString('fill-finance-in', $html);
        $this->assertStringContainsString('aria-label="Gelir gider"', $html);
        $this->assertStringNotContainsString('NaN', $html);

        $empty = (string) $this->blade('<x-finance.line-chart :values="[]" title="x" />');
        $this->assertStringContainsString('Veri yok', $empty);
        $this->assertStringNotContainsString('<svg', $empty);
    }
}
