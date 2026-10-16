<?php

namespace App\Domain\Import\Importers;

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Import\Contracts\Importer;
use App\Domain\Import\Exports\ImportTemplateExport;
use App\Domain\Import\Support\ImportResult;
use App\Domain\Import\Support\ImportRow;
use App\Domain\Import\Support\ImportValue;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\SerialException;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Services\SerialRegistry;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\SerialStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Açılış stoğu şablonu (Aşama 38). Her satır bir stok giriş hareketidir ve
 * yalnızca StockMovementService::in üzerinden yazılır; soğuk zincir ve seri
 * kuralları elle girişteki gibi uygulanır. Aralık dışı sıcaklık içe aktarmada
 * gerekçeyle kabul edilmez — o ürün Stok Girişi ekranından girilir.
 */
class OpeningStockImporter implements Importer
{
    public const REASON = 'Açılış stoğu';

    public function __construct(private readonly StockMovementService $stock) {}

    public function headings(): array
    {
        return ['Ürün Kodu', 'Şube', 'Depo', 'Miktar', 'Lot No', 'SKT', 'Birim Maliyet', 'Sıcaklık', 'Seri Numaraları'];
    }

    public function templateFileName(): string
    {
        return 'acilis-stogu-sablonu.xlsx';
    }

    public function template(User $actor): ImportTemplateExport
    {
        $warehouses = $this->warehouses($actor)
            ->map(fn (Warehouse $warehouse) => ["{$warehouse->branch->name} / {$warehouse->name}", 'Şube ve Depo sütunlarına bu adları yazın.'])
            ->values()
            ->all();

        return new ImportTemplateExport('Açılış Stoğu', $this->headings(), [
            ['ENJ-01', 'Merkez', 'Ana Depo', '40', 'LOT-2027-01', '31.03.2027', '12,50', '', ''],
        ], [
            ['Ürün Kodu', 'Zorunlu. Ürün kartındaki kod; ürün aktif olmalı.'],
            ['Şube / Depo', 'Zorunlu. Aşağıdaki listeden; yalnızca stok girişi yetkiniz olan, açık depolar.'],
            ['Miktar', 'Zorunlu, sıfırdan büyük, ürünün ana biriminde. Seri takipli üründe tam sayı.'],
            ['Lot No', 'İsteğe bağlı. Aynı ürün, depo ve lot numarası tek lotta toplanır.'],
            ['SKT', 'İsteğe bağlı, GG.AA.YYYY.'],
            ['Birim Maliyet', 'Boşsa 0.'],
            ['Sıcaklık', 'Soğuk zincir ürününde zorunlu (°C); saklama aralığında olmalı.'],
            ['Seri Numaraları', 'Seri takipli üründe zorunlu; virgül veya noktalı virgülle ayırın. Sayısı miktara eşit olmalı.'],
            ...$warehouses,
        ]);
    }

    public function validate(array $rows, User $actor): ImportResult
    {
        $products = Product::where('status', 'active')->whereNotNull('code')->get()
            ->groupBy(fn (Product $product) => ImportValue::key($product->code));
        $warehouses = $this->warehouses($actor)
            ->keyBy(fn (Warehouse $warehouse) => ImportValue::key($warehouse->branch->name).'|'.ImportValue::key($warehouse->name));

        $seenSerials = [];
        $result = [];

        foreach ($rows as $line => $raw) {
            $row = new ImportRow($line, $raw);

            $matches = $products->get(ImportValue::key($raw['Ürün Kodu']), collect());
            $product = $matches->count() === 1 ? $matches->first() : null;

            if ($raw['Ürün Kodu'] === '') {
                $row->fail('Ürün Kodu', 'Zorunlu.');
            } elseif ($matches->isEmpty()) {
                $row->fail('Ürün Kodu', 'Bu kodla aktif bir ürün yok.');
            } elseif ($matches->count() > 1) {
                $row->fail('Ürün Kodu', 'Bu kod birden çok üründe kayıtlı; ürün kartlarında kodları tekilleştirin.');
            }

            $warehouse = $warehouses->get(ImportValue::key($raw['Şube']).'|'.ImportValue::key($raw['Depo']));
            if ($warehouse === null) {
                $row->fail('Depo', 'Bu şube ve depo bulunamadı ya da bu depoda stok girişi yetkiniz yok.');
            }

            $quantity = ImportValue::number($raw['Miktar']);
            if (! is_numeric($quantity) || (float) $quantity <= 0) {
                $row->fail('Miktar', 'Sıfırdan büyük bir sayı olmalı.');
            }

            if (mb_strlen($raw['Lot No']) > 255) {
                $row->fail('Lot No', 'En fazla 255 karakter olabilir.');
            }

            $expiry = ImportValue::date($raw['SKT']);
            if ($expiry === null) {
                $row->fail('SKT', 'Geçerli bir tarih değil (GG.AA.YYYY).');
            }

            $unitCost = ImportValue::number($raw['Birim Maliyet']) ?: '0';
            if (! is_numeric($unitCost) || (float) $unitCost < 0) {
                $row->fail('Birim Maliyet', 'Sıfır veya daha büyük bir sayı olmalı.');
            }

            $temperature = ImportValue::number($raw['Sıcaklık']);
            if ($temperature !== '' && (! is_numeric($temperature) || abs((float) $temperature) > 100)) {
                $row->fail('Sıcaklık', '−100 ile 100 arasında bir sayı olmalı.');
            } elseif ($product?->cold_chain && $temperature === '') {
                $row->fail('Sıcaklık', 'Soğuk zincir ürününde ölçülen sıcaklık zorunlu.');
            } elseif ($product?->cold_chain && ! $product->temperatureInRange((float) $temperature)) {
                $row->fail('Sıcaklık', "Saklama aralığı ({$product->storageRangeLabel()}) dışında.");
            }

            $serials = $product ? $this->serials($row, $product, $quantity, $seenSerials) : [];

            $row->values = [
                'product' => $product,
                'warehouse' => $warehouse,
                'quantity' => (float) $quantity,
                'lot' => ['lot_no' => $raw['Lot No'] ?: null, 'expiry_date' => $expiry ?: null, 'unit_cost' => (float) $unitCost],
                'tracking' => ['temperature' => $temperature === '' ? null : $temperature, 'serials' => $serials],
            ];
            $result[$line] = $row;
        }

        return new ImportResult($result);
    }

    public function import(ImportResult $result, User $actor): int
    {
        if (! $result->isValid()) {
            throw new LogicException('Hatalı satır içeren dosya içe aktarılamaz.');
        }

        return DB::transaction(function () use ($result, $actor) {
            foreach ($result->rows as $row) {
                $values = $row->values;

                $this->stock->in(
                    $values['product'], $values['warehouse'], $values['quantity'], $values['lot'],
                    $actor, self::REASON, tracking: $values['tracking'],
                );
            }

            return $result->rowCount();
        });
    }

    /**
     * @return Collection<int, Warehouse>
     */
    private function warehouses(User $actor): Collection
    {
        return Warehouse::operational()
            ->inBranches($actor->accessibleBranchIds(Module::StockMovement))
            ->with('branch')
            ->orderBy('name')
            ->get();
    }

    /**
     * Seri takipli üründe seri listesi; dosya içinde ve stokta tekrar etmemeli.
     *
     * @param  array<string, int>  $seen  "ürünId|seri" => ilk görüldüğü satır
     * @return array<int, string>
     */
    private function serials(ImportRow $row, Product $product, string $quantity, array &$seen): array
    {
        $text = $row->raw['Seri Numaraları'];

        if (! $product->tracks_serials) {
            if ($text !== '') {
                $row->fail('Seri Numaraları', 'Bu ürün seri takipli değil; sütunu boş bırakın.');
            }

            return [];
        }

        try {
            $serials = SerialRegistry::parseList($text);

            if (is_numeric($quantity)) {
                SerialRegistry::ensureMatchesQuantity($product, (float) $quantity, $serials);
            }
        } catch (SerialException $e) {
            $row->fail('Seri Numaraları', $e->getMessage());

            return [];
        }

        foreach ($serials as $serial) {
            $key = "{$product->id}|{$serial}";

            if (isset($seen[$key])) {
                $row->fail('Seri Numaraları', "Dosyada satır {$seen[$key]} ile aynı seri: {$serial}");
            }

            $seen[$key] ??= $row->line;
        }

        $inStock = StockSerial::where('product_id', $product->id)
            ->whereIn('serial_no', $serials)
            ->whereIn('status', [SerialStatus::InStock, SerialStatus::InTransit])
            ->pluck('serial_no');

        if ($inStock->isNotEmpty()) {
            $row->fail('Seri Numaraları', 'Bu seriler zaten stokta: '.$inStock->implode(', '));
        }

        return $serials;
    }
}
