<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * fazlar-adimlar.md Aşama 10 doğrulaması: "bir şubeye ikinci bir depo eklenir,
 * stok bu depoya özel girilir ve şube toplamında doğru şekilde birleştiği
 * görülür." Tamamen ekranlar üzerinden yürütülür.
 */
class MultiWarehouseAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_warehouse_merges_into_the_branch_total(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@dental-erp.test')->sole();
        $branch = Branch::sole();
        $defaultWarehouse = Warehouse::where('is_default', true)->sole();
        $this->actingAs($admin);

        // 1) Şubeye ikinci depo eklenir.
        Livewire::test('pages::organization.warehouses')
            ->call('create')
            ->set('branch_id', (string) $branch->id)
            ->set('name', 'Cerrahi Depo')
            ->call('save')
            ->assertHasNoErrors();
        $surgical = Warehouse::where('name', 'Cerrahi Depo')->sole();

        $product = Product::create(['name' => 'Cerrahi Eldiven', 'code' => 'CRH-01', 'base_unit' => 'Adet', 'status' => 'active']);

        // 2) Stok her iki depoya ayrı ayrı girilir.
        foreach ([[$defaultWarehouse, '30', '2'], [$surgical, '20', '3']] as [$warehouse, $quantity, $cost]) {
            Livewire::test('pages::stock.in')
                ->call('openForm')
                ->set('product_id', (string) $product->id)
                ->set('warehouse_id', (string) $warehouse->id)
                ->set('quantity', $quantity)
                ->set('unit_cost', $cost)
                ->call('save')
                ->assertHasNoErrors();
        }

        // 3) Depo Stokları: her depo kendi miktarını, şube toplamı ikisinin toplamını gösterir.
        Livewire::test('pages::reports.warehouse-stock')
            ->assertSet('branchId', (string) $branch->id)
            ->assertViewHas('report', function (array $report) use ($product, $defaultWarehouse, $surgical) {
                $row = collect($report['rows'])->firstWhere('product.id', $product->id);

                return $row['quantities'][$defaultWarehouse->id] === 30.0
                    && $row['quantities'][$surgical->id] === 20.0
                    && $row['total'] === 50.0
                    && $row['value'] === 120.0
                    && $report['warehouseTotals'][$defaultWarehouse->id] === 30.0
                    && $report['warehouseTotals'][$surgical->id] === 20.0
                    && $report['branchTotal'] === 50.0;
            })
            ->assertViewHas('branchSummaries', fn ($summaries) => $summaries->firstWhere('id', $branch->id)['quantity'] === 50.0
                && $summaries->firstWhere('id', $branch->id)['warehouse_count'] === 2);

        // 4) Diğer ekranlar da aynı şube toplamını görür.
        Livewire::test('pages::dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['branchDistribution'] === [['name' => $branch->name, 'quantity' => 50.0]]);

        Livewire::test('pages::reports.stock')
            ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('product.id', $product->id)['quantity'] === 50.0);

        Livewire::test('pages::reports.stock')
            ->set('warehouseId', (string) $surgical->id)
            ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('product.id', $product->id)['quantity'] === 20.0);
    }
}
