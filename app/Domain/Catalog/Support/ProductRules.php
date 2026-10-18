<?php

namespace App\Domain\Catalog\Support;

use Illuminate\Validation\Rule;

/**
 * Ürün kartının alan doğrulama kuralları. Ürün ekranı ve toplu içe aktarma
 * (Aşama 38) aynı kuralları kullanır; kural tek yerde değişir.
 */
final class ProductRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function fields(int $organizationId, ?int $ignoreId = null): array
    {
        $unique = fn (string $column) => Rule::unique('products', $column)->where('organization_id', $organizationId)->ignore($ignoreId);

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            // Barkod organizasyon içinde tekil: okutulan barkod tek bir ürüne çözülmeli (Aşama 20).
            'barcode' => ['nullable', 'string', 'max:255', $unique('barcode')],
            'category_id' => ['nullable', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'base_unit' => ['required', 'string', 'max:50'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'min_stock' => ['required', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'product_type' => ['required', Rule::in(array_column(ProductType::cases(), 'value'))],
            'gtin' => ['nullable', 'string', 'max:20'],
            'uts_number' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'storage_condition' => ['nullable', 'string', 'max:255'],
            'cold_chain' => ['boolean'],
            'storage_min_temp' => ['nullable', 'required_if:cold_chain,true', 'numeric', 'between:-100,100'],
            'storage_max_temp' => ['nullable', 'required_if:cold_chain,true', 'numeric', 'between:-100,100', 'gte:storage_min_temp'],
            'is_controlled' => ['boolean'],
            'tracks_serials' => ['boolean'],
            'uts_usage_report' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'storage_min_temp.required_if' => 'Soğuk zincir ürününde en düşük saklama sıcaklığı girilmeli.',
            'storage_max_temp.required_if' => 'Soğuk zincir ürününde en yüksek saklama sıcaklığı girilmeli.',
            'storage_max_temp.gte' => 'En yüksek sıcaklık en düşükten küçük olamaz.',
        ];
    }
}
