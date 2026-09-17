<?php

namespace App\Support;

use App\Domain\Catalog\Models\Product;
use InvalidArgumentException;

class UnitConverter
{
    public function toBaseUnit(Product $product, float $quantity, string $unit): float
    {
        if ($unit === $product->base_unit) {
            return $quantity;
        }

        $factor = $this->factorFor($product, $unit);

        return $quantity * $factor;
    }

    public function toCompoundDisplay(Product $product, float $quantityInBaseUnit): string
    {
        $rules = collect($product->conversion_rules ?? [])->sortByDesc('factor')->values();

        $remaining = $quantityInBaseUnit;
        $parts = [];

        foreach ($rules as $rule) {
            $unitCount = intdiv((int) $remaining, (int) $rule['factor']);

            if ($unitCount > 0) {
                $parts[] = "{$unitCount} {$rule['unit']}";
                $remaining -= $unitCount * $rule['factor'];
            }
        }

        if ($remaining > 0 || empty($parts)) {
            $parts[] = "{$remaining} {$product->base_unit}";
        }

        return implode(' + ', $parts);
    }

    private function factorFor(Product $product, string $unit): float
    {
        $rule = collect($product->conversion_rules ?? [])->firstWhere('unit', $unit);

        if (! $rule) {
            throw new InvalidArgumentException("'{$unit}' birimi için ürün üzerinde bir dönüşüm oranı tanımlı değil.");
        }

        return (float) $rule['factor'];
    }
}
