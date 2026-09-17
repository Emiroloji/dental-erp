<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Supplier;

class SupplierService
{
    /**
     * @param  array{name: string, contact_person?: ?string, phone?: ?string, email?: ?string, tax_number?: ?string}  $attributes
     */
    public function create(array $attributes): Supplier
    {
        return Supplier::create([...$attributes, 'status' => 'active']);
    }

    /**
     * @param  array{name: string, contact_person?: ?string, phone?: ?string, email?: ?string, tax_number?: ?string}  $attributes
     */
    public function update(Supplier $supplier, array $attributes): Supplier
    {
        $supplier->update($attributes);

        return $supplier;
    }

    public function deactivate(Supplier $supplier): Supplier
    {
        $supplier->update(['status' => 'passive']);

        return $supplier;
    }
}
