<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Domain\Stock\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * fazlar-adimlar.md Aşama 12 doğrulaması: "bir sipariş kısmen teslim alınır;
 * teslim alınan kısım stoğa (lot/SKT ile) işlenir, kalan miktar doğru şekilde
 * 'açık sipariş' olarak görünür." proje.md Bölüm 9'daki örnek akış (ortodonti
 * deposunda eldiven azaldı → talep → Admin onayı → sipariş → kısmi teslim)
 * kurulum dahil ekranlar üzerinden yürütülür.
 */
class PurchaseAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_partially_received_order_books_stock_and_stays_open(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@dental-erp.test')->sole();
        $this->actingAs($admin);
        $branch = Warehouse::where('is_default', true)->sole()->branch;

        // Kurulum: Ortodonti deposu, tedarikçi, ürün ve Satın Alma yetkili personel.
        Livewire::test('pages::organization.warehouses')->call('create')
            ->set('branch_id', (string) $branch->id)->set('name', 'Ortodonti Depo')->call('save')->assertHasNoErrors();
        $orthodontics = Warehouse::where('name', 'Ortodonti Depo')->sole();

        Livewire::test('pages::catalog.suppliers')->call('openForm')->set('name', 'Dental Tedarik A.Ş.')->call('save')->assertHasNoErrors();
        $supplier = Supplier::sole();

        Livewire::test('pages::catalog.products')->call('openForm')
            ->set('name', 'Nitril Eldiven')->set('base_unit', 'Adet')->set('purchase_price', '3.20')->call('save')->assertHasNoErrors();
        $gloves = Product::sole();

        Livewire::test('pages::access.staff')->call('openForm')
            ->set('name', 'Satınalma Sorumlusu')->set('email', 'satinalma@dental-erp.test')->set('password', 'guclu-sifre-1')
            ->set('branch_id', (string) $branch->id)
            ->set('modules.purchasing.read', true)->set('modules.purchasing.write', true)
            ->call('save')->assertHasNoErrors();
        $buyer = User::where('email', 'satinalma@dental-erp.test')->sole();

        // Talep (personel) → onay (Admin) → sipariş (personel).
        $this->actingAs($buyer);
        Livewire::test('pages::purchasing.index')->call('create')
            ->set('supplier_id', (string) $supplier->id)
            ->set('warehouse_id', (string) $orthodontics->id)
            ->set('lines.0.product_id', (string) $gloves->id)
            ->assertSet('lines.0.unit_price', '3.20')
            ->set('lines.0.quantity', '500')
            ->set('note', 'Ortodonti deposunda eldiven azaldı')
            ->call('save', true)->assertHasNoErrors();
        $order = PurchaseOrder::sole();

        Livewire::test('pages::purchasing.index')->call('approve', $order->id)->assertForbidden();

        $this->actingAs($admin);
        Livewire::test('pages::purchasing.index')->call('approve', $order->id);

        $this->actingAs($buyer);
        Livewire::test('pages::purchasing.index')->call('markOrdered', $order->id);
        $this->assertSame(0, StockLot::count(), 'sipariş vermek stoğa dokunmaz');

        // Kısmi teslim: 500'ün 300'ü gelir.
        $line = $order->lines()->sole();
        Livewire::test('pages::purchasing.index')->call('openReceipt', $order->id)
            ->set('invoice_number', 'FTR-2026-0915')
            ->set('delivery_note_number', 'IRS-4471')
            ->set("receiptLines.{$line->id}.quantity", '300')
            ->set("receiptLines.{$line->id}.lot_no", 'NTR-2609')
            ->set("receiptLines.{$line->id}.expiry_date", now()->addYears(3)->toDateString())
            ->set("receiptLines.{$line->id}.unit_cost", '3.10')
            ->call('receive')->assertHasNoErrors();

        // Teslim alınan kısım lot/SKT/alış fiyatıyla stokta.
        $lot = StockLot::where('warehouse_id', $orthodontics->id)->sole();
        $this->assertSame(300.0, (float) $lot->quantity);
        $this->assertSame('NTR-2609', $lot->lot_no);
        $this->assertSame(now()->addYears(3)->toDateString(), $lot->expiry_date->toDateString());
        $this->assertSame('3.10', $lot->unit_cost);

        // Kalan 200 "açık sipariş" olarak görünür.
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);
        $this->assertSame(200.0, $line->fresh()->remaining());

        Livewire::test('pages::purchasing.index')
            ->set('statusFilter', 'open')
            ->assertSee($order->number())
            ->assertSee('200,00 kalan');

        Livewire::test('pages::purchasing.show', ['order' => $order->id])
            ->assertSee('Kısmi Teslim')
            ->assertSee('FTR-2026-0915')
            ->assertSee('NTR-2609');

        // Stok ekranları da aynı girişi görür.
        $this->actingAs($admin);
        Livewire::test('pages::reports.warehouse-stock')
            ->assertViewHas('report', fn (array $report) => $report['warehouseTotals'][$orthodontics->id] === 300.0);
        Livewire::test('pages::dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['todayIn'] === 300.0 && $summary['totalStockValue'] === 930.0);
    }
}
