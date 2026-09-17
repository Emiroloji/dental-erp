<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;

class ProductService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Product
    {
        return Product::create([...$attributes, 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes): Product
    {
        $product->update($attributes);

        return $product;
    }

    public function deactivate(Product $product): Product
    {
        $product->update(['status' => 'passive']);

        return $product;
    }
}
