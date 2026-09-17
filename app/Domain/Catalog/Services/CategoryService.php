<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;

class CategoryService
{
    /**
     * @param  array{name: string}  $attributes
     */
    public function create(array $attributes): Category
    {
        return Category::create([
            'name' => $attributes['name'],
            'status' => 'active',
        ]);
    }

    /**
     * @param  array{name: string}  $attributes
     */
    public function update(Category $category, array $attributes): Category
    {
        $category->update([
            'name' => $attributes['name'],
        ]);

        return $category;
    }

    public function deactivate(Category $category): Category
    {
        $category->update(['status' => 'passive']);

        return $category;
    }
}
