<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Support\Gs1;
use PHPUnit\Framework\TestCase;

class Gs1Test extends TestCase
{
    public function test_gtin_is_normalized_to_14_digits_and_check_digit_is_verified(): void
    {
        // 4006381333931 geçerli bir EAN-13.
        $this->assertSame('04006381333931', Gs1::normalizeGtin('4006381333931'));
        $this->assertSame('04006381333931', Gs1::normalizeGtin('04006381333931'));
        $this->assertNull(Gs1::normalizeGtin('4006381333932'), 'Yanlış kontrol hanesi');
        $this->assertNull(Gs1::normalizeGtin('12345'));
        $this->assertNull(Gs1::normalizeGtin('ABC'));
    }

    public function test_parenthesized_human_readable_form(): void
    {
        $this->assertSame(
            ['gtin' => '04006381333931', 'lot_no' => 'LOT42', 'expiry_date' => '2027-03-31', 'serial' => 'SN-0001'],
            Gs1::parse('(01)04006381333931(17)270331(10)LOT42(21)SN-0001'),
        );
    }

    public function test_raw_scanner_form_with_group_separators_and_symbology_prefix(): void
    {
        $raw = "]d2010400638133393117270300\x1D10LOT42\x1D21SN-0001";

        // Gün "00" = ayın son günü.
        $this->assertSame(
            ['gtin' => '04006381333931', 'lot_no' => 'LOT42', 'expiry_date' => '2027-03-31', 'serial' => 'SN-0001'],
            Gs1::parse($raw),
        );

        // Seri en sonda, ayırıcısız.
        $this->assertSame('X9', Gs1::parse('01040063813339312'.'1X9')['serial']);
    }

    public function test_non_gs1_codes_are_not_parsed(): void
    {
        $this->assertNull(Gs1::parse('4006381333931'));
        $this->assertNull(Gs1::parse('DERP:P:5'));
        $this->assertNull(Gs1::parse('01123'));
        $this->assertNull(Gs1::parse('(01)12345678901234'), 'Kontrol hanesi yanlış');
    }
}
