<?php

namespace App\Domain\Import\Importers;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Services\SupplierService;
use App\Domain\Catalog\Support\Gs1;
use App\Domain\Catalog\Support\ProductRules;
use App\Domain\Catalog\Support\ProductType;
use App\Domain\Import\Contracts\Importer;
use App\Domain\Import\Exports\ImportTemplateExport;
use App\Domain\Import\Support\ImportResult;
use App\Domain\Import\Support\ImportRow;
use App\Domain\Import\Support\ImportValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * Ürün kartı şablonu (Aşama 38). Yalnızca yeni ürün ekler: kod zorunlu ve
 * organizasyonda tekil; var olan kod hata sayılır, mevcut kart ezilmez.
 * Olmayan kategori ve tedarikçiler adıyla oluşturulur.
 */
class ProductImporter implements Importer
{
    /** Şablon başlığı => ürün alanı (doğrulama mesajlarında başlık adı görünür). */
    private const COLUMNS = [
        'Ürün Adı' => 'name',
        'Ürün Kodu' => 'code',
        'Kategori' => null,
        'Tedarikçi' => null,
        'Ana Birim' => 'base_unit',
        'Alış Fiyatı' => 'purchase_price',
        'Min Stok' => 'min_stock',
        'Max Stok' => 'max_stock',
        'Barkod' => 'barcode',
        'GTIN' => 'gtin',
        'ÜTS No' => 'uts_number',
        'Ruhsat No' => 'license_number',
        'Üretici' => 'manufacturer',
        'Saklama Koşulu' => 'storage_condition',
        'Ürün Türü' => 'product_type',
        'Soğuk Zincir' => 'cold_chain',
        'Min Sıcaklık' => 'storage_min_temp',
        'Max Sıcaklık' => 'storage_max_temp',
        'Kontrollü' => 'is_controlled',
        'Seri Takipli' => 'tracks_serials',
    ];

    private const BOOLEAN_COLUMNS = ['Soğuk Zincir', 'Kontrollü', 'Seri Takipli'];

    public function __construct(
        private readonly ProductService $products,
        private readonly CategoryService $categories,
        private readonly SupplierService $suppliers,
    ) {}

    public function headings(): array
    {
        return array_keys(self::COLUMNS);
    }

    public function templateFileName(): string
    {
        return 'urun-sablonu.xlsx';
    }

    public function template(User $actor): ImportTemplateExport
    {
        return new ImportTemplateExport('Ürünler', $this->headings(), [[
            'Enjektör 2ml', 'ENJ-01', 'Sarf Malzeme', 'Dental Medikal A.Ş.', 'Adet', '12,50', '20', '200',
            '8690000000017', '', '', '', '', '', 'Sarf Malzeme', 'H', '', '', 'H', 'H',
        ]], [
            ['Ürün Adı', 'Zorunlu.'],
            ['Ürün Kodu', 'Zorunlu ve tekil. Açılış stoğu dosyası ürünü bu kodla bulur. Sistemde aynı kodla ürün varsa satır hatalı sayılır.'],
            ['Kategori / Tedarikçi', 'Ad yazın. Sistemde yoksa içe aktarmada oluşturulur.'],
            ['Ana Birim', 'Zorunlu (ör. Adet, Kutu). Stok bu birimle tutulur.'],
            ['Alış Fiyatı', 'Boşsa 0. 1.250,50 ya da 1250.50 yazılabilir.'],
            ['Min Stok / Max Stok', 'Tam sayı. Min boşsa 0.'],
            ['Barkod / GTIN', 'İsteğe bağlı; ikisi de tekil olmalı. GTIN 8, 12, 13 veya 14 hane, kontrol hanesi doğru.'],
            ['Ürün Türü', 'Sarf Malzeme, Ekipman veya İlaç. Boşsa Sarf Malzeme.'],
            ['Soğuk Zincir / Kontrollü / Seri Takipli', 'E veya H. Boşsa H.'],
            ['Min Sıcaklık / Max Sıcaklık', 'Soğuk zincir ürününde zorunlu (°C, −100 ile 100 arası).'],
        ]);
    }

    public function validate(array $rows, User $actor): ImportResult
    {
        $rules = ProductRules::fields($actor->organization_id);
        unset($rules['category_id'], $rules['supplier_id']);
        $rules['code'] = ['required', 'string', 'max:255'];
        $attributeNames = array_flip(array_filter(self::COLUMNS));

        $existingCodes = $this->keySet(Product::whereNotNull('code')->pluck('code'));
        $existingGtins = $this->keySet(Product::whereNotNull('gtin')->pluck('gtin'));
        $categories = $this->keySet(Category::pluck('name'));
        $suppliers = $this->keySet(Supplier::pluck('name'));
        $types = collect(ProductType::cases())->mapWithKeys(fn (ProductType $type) => [ImportValue::key($type->label()) => $type->value]);

        $seen = ['code' => [], 'barcode' => [], 'gtin' => []];
        $creates = ['Kategori' => [], 'Tedarikçi' => []];
        $result = [];

        foreach ($rows as $line => $raw) {
            $row = new ImportRow($line, $raw);
            $data = [
                'name' => $raw['Ürün Adı'],
                'code' => $raw['Ürün Kodu'],
                'base_unit' => $raw['Ana Birim'],
                'purchase_price' => ImportValue::number($raw['Alış Fiyatı']) ?: '0',
                'min_stock' => ImportValue::number($raw['Min Stok']) ?: '0',
                'max_stock' => ImportValue::number($raw['Max Stok']) ?: null,
                'barcode' => $raw['Barkod'] ?: null,
                'gtin' => $raw['GTIN'] ?: null,
                'uts_number' => $raw['ÜTS No'] ?: null,
                'license_number' => $raw['Ruhsat No'] ?: null,
                'manufacturer' => $raw['Üretici'] ?: null,
                'storage_condition' => $raw['Saklama Koşulu'] ?: null,
                'product_type' => $raw['Ürün Türü'] === '' ? ProductType::Consumable->value : ($types[ImportValue::key($raw['Ürün Türü'])] ?? $raw['Ürün Türü']),
                'storage_min_temp' => ImportValue::number($raw['Min Sıcaklık']) ?: null,
                'storage_max_temp' => ImportValue::number($raw['Max Sıcaklık']) ?: null,
            ];

            foreach (self::BOOLEAN_COLUMNS as $column) {
                $value = ImportValue::boolean($raw[$column]);

                if ($value === null) {
                    $row->fail($column, 'E veya H yazın.');
                }

                // Geçersiz değer hata olarak kaydedildi; diğer alanların hataları da
                // aynı önizlemede görünsün diye doğrulamaya "hayır" olarak girer.
                $data[self::COLUMNS[$column]] = $value ?? false;
            }

            $validator = Validator::make($data, $rules, [
                ...ProductRules::messages(),
                'product_type.in' => 'Sarf Malzeme, Ekipman veya İlaç yazın.',
            ], $attributeNames);

            foreach ($validator->errors()->messages() as $field => $messages) {
                $row->fail($attributeNames[$field], $messages[0]);
            }

            $this->checkUnique($row, 'Ürün Kodu', 'kod', $data['code'], $seen['code'], $existingCodes, 'Bu kodla kayıtlı bir ürün zaten var.');
            $this->checkUnique($row, 'Barkod', 'barkod', (string) $data['barcode'], $seen['barcode']);

            if ($data['gtin'] !== null) {
                $gtin = Gs1::normalizeGtin($data['gtin']);
                $gtin === null
                    ? $row->fail('GTIN', 'Geçerli bir GTIN değil (8, 12, 13 veya 14 hane, kontrol hanesi doğru olmalı).')
                    : $this->checkUnique($row, 'GTIN', 'GTIN', $gtin, $seen['gtin'], $existingGtins, 'Bu GTIN başka bir üründe kayıtlı.');
                $data['gtin'] = $gtin;
            }

            foreach (['Kategori' => $categories, 'Tedarikçi' => $suppliers] as $column => $existing) {
                $name = trim($raw[$column]);
                $key = ImportValue::key($name);

                if ($name !== '' && ! isset($existing[$key]) && ! isset($creates[$column][$key])) {
                    $creates[$column][$key] = $name;
                }
            }

            $row->values = $data;
            $result[$line] = $row;
        }

        return new ImportResult($result, array_map(fn (array $names) => array_values($names), $creates));
    }

    public function import(ImportResult $result, User $actor): int
    {
        if (! $result->isValid()) {
            throw new LogicException('Hatalı satır içeren dosya içe aktarılamaz.');
        }

        return DB::transaction(function () use ($result) {
            $categoryIds = $this->idsByName(Category::pluck('id', 'name')->all());
            $supplierIds = $this->idsByName(Supplier::pluck('id', 'name')->all());

            foreach ($result->rows as $row) {
                $values = $row->values;

                $values['category_id'] = $this->resolve($row->raw['Kategori'], $categoryIds, fn (string $name) => $this->categories->create(['name' => $name])->id);
                $values['supplier_id'] = $this->resolve($row->raw['Tedarikçi'], $supplierIds, fn (string $name) => $this->suppliers->create(['name' => $name])->id);

                $this->products->create($values);
            }

            return $result->rowCount();
        });
    }

    /**
     * Dosya içinde ve (verildiyse) sistemde tekillik. $seen anahtar => ilk görüldüğü satır.
     *
     * @param  array<string, int>  $seen
     * @param  array<string, true>  $existing
     */
    private function checkUnique(ImportRow $row, string $column, string $noun, string $value, array &$seen, array $existing = [], ?string $existsMessage = null): void
    {
        $key = ImportValue::key($value);

        if ($key === '') {
            return;
        }

        if (isset($seen[$key])) {
            $row->fail($column, "Dosyada satır {$seen[$key]} ile aynı {$noun}.");

            return;
        }

        $seen[$key] = $row->line;

        if ($existsMessage !== null && isset($existing[$key])) {
            $row->fail($column, $existsMessage);
        }
    }

    /**
     * @param  iterable<int, string>  $values
     * @return array<string, true>
     */
    private function keySet(iterable $values): array
    {
        $set = [];
        foreach ($values as $value) {
            $set[ImportValue::key((string) $value)] = true;
        }

        return $set;
    }

    /**
     * @param  array<string, int>  $idsByName
     * @return array<string, int>
     */
    private function idsByName(array $idsByName): array
    {
        $ids = [];
        foreach ($idsByName as $name => $id) {
            $ids[ImportValue::key((string) $name)] ??= $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $ids
     * @param  callable(string): int  $create
     */
    private function resolve(string $name, array &$ids, callable $create): ?int
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return $ids[ImportValue::key($name)] ??= $create($name);
    }
}
