<?php

namespace Tests\Unit\Support;

use App\Domain\Catalog\Models\Product;
use App\Support\UnitConverter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UnitConverterTest extends TestCase
{
    public function test_converts_box_quantity_to_base_unit(): void
    {
        $product = new Product([
            'base_unit' => 'Adet',
            'conversion_rules' => [
                ['unit' => 'Kutu', 'factor' => 50],
            ],
        ]);

        $converter = new UnitConverter;

        $this->assertSame(150.0, $converter->toBaseUnit($product, 3, 'Kutu'));
    }

    public function test_quantity_already_in_base_unit_is_unchanged(): void
    {
        $product = new Product([
            'base_unit' => 'Adet',
            'conversion_rules' => [
                ['unit' => 'Kutu', 'factor' => 50],
            ],
        ]);

        $converter = new UnitConverter;

        $this->assertSame(7.0, $converter->toBaseUnit($product, 7, 'Adet'));
    }

    public function test_unknown_unit_throws_exception(): void
    {
        $product = new Product([
            'base_unit' => 'Adet',
            'conversion_rules' => [
                ['unit' => 'Kutu', 'factor' => 50],
            ],
        ]);

        $converter = new UnitConverter;

        $this->expectException(InvalidArgumentException::class);

        $converter->toBaseUnit($product, 1, 'Paket');
    }

    public function test_compound_display_breaks_base_unit_into_boxes_and_units(): void
    {
        $product = new Product([
            'base_unit' => 'Adet',
            'conversion_rules' => [
                ['unit' => 'Kutu', 'factor' => 50],
            ],
        ]);

        $converter = new UnitConverter;

        $this->assertSame('3 Kutu + 20 Adet', $converter->toCompoundDisplay($product, 170));
    }
}
