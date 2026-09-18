<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Support\ReturnStatus;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * fazlar-adimlar.md Aşama 14 doğrulaması: bir iade "Talep Edildi"den
 * "Tamamlandı"ya kadar uçtan uca takip edilir (proje.md Bölüm 10). Senaryo:
 * siparişle gelen kompozitlerin bir kısmı hasarlı çıkar ve tedarikçiye iade
 * edilir. Kurulum dahil her adım ekranlardan.
 */
class ReturnAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_return_is_tracked_from_requested_to_completed(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@dental-erp.test')->sole();
        $warehouse = Warehouse::where('is_default', true)->sole();
        $this->actingAs($admin);

        // Kurulum: tedarikçi, ürün, Stok yazma yetkili depo görevlisi.
        Livewire::test('pages::catalog.suppliers')->call('openForm')->set('name', 'Dental Tedarik A.Ş.')->call('save')->assertHasNoErrors();
        $supplier = Supplier::sole();

        Livewire::test('pages::catalog.products')->call('openForm')
            ->set('name', 'Kompozit A')->set('base_unit', 'Adet')->set('purchase_price', '500')->set('supplier_id', (string) $supplier->id)
            ->call('save')->assertHasNoErrors();
        $product = Product::sole();

        Livewire::test('pages::access.staff')->call('openForm')
            ->set('name', 'Depo Görevlisi')->set('email', 'depo@dental-erp.test')->set('password', 'guclu-sifre-1')
            ->set('branch_id', (string) $warehouse->branch_id)
            ->set('modules.stock_movement.read', true)->set('modules.stock_movement.write', true)
            ->call('save')->assertHasNoErrors();
        $clerk = User::where('email', 'depo@dental-erp.test')->sole();

        // Ürün siparişle gelir: 20 adet, lot LOT-2609, fatura FTR-2026-0918.
        Livewire::test('pages::purchasing.index')->call('create')
            ->set('supplier_id', (string) $supplier->id)->set('warehouse_id', (string) $warehouse->id)
            ->set('lines.0.product_id', (string) $product->id)->set('lines.0.quantity', '20')
            ->call('save', true)->assertHasNoErrors();
        $order = PurchaseOrder::sole();
        Livewire::test('pages::purchasing.index')->call('approve', $order->id)->call('markOrdered', $order->id);
        $line = $order->lines()->sole();
        Livewire::test('pages::purchasing.index')->call('openReceipt', $order->id)
            ->set('invoice_number', 'FTR-2026-0918')
            ->set("receiptLines.{$line->id}.quantity", '20')
            ->set("receiptLines.{$line->id}.lot_no", 'LOT-2609')
            ->call('receive')->assertHasNoErrors();
        $lot = StockLot::sole();
        $this->assertSame(20.0, (float) $lot->quantity);

        // 1) Talep Edildi — görevli 4 hasarlı ürünü iade için açar; tedarikçi,
        //    sipariş ve fatura lotun teslim kaydından önerilir.
        $this->actingAs($clerk);
        Livewire::test('pages::returns.index')->call('openForm')
            ->set('warehouse_id', (string) $warehouse->id)
            ->set('product_id', (string) $product->id)
            ->set('lot_id', (string) $lot->id)
            ->assertSet('supplier_id', (string) $supplier->id)
            ->assertSet('purchase_order_id', (string) $order->id)
            ->assertSet('invoice_number', 'FTR-2026-0918')
            ->set('quantity', '4')
            ->set('reason', 'damaged')
            ->set('reason_note', 'Şırınga uçları kırık geldi')
            ->call('save')->assertHasNoErrors();
        $return = SupplierReturn::sole();
        $this->assertSame(ReturnStatus::Requested, $return->status);
        $this->assertSame(20.0, (float) $lot->fresh()->quantity, 'talep stoğa dokunmaz');

        Livewire::test('pages::returns.index')->set('statusFilter', 'open')->assertSee($return->number())->assertSee('Talep Edildi');

        // 2) Onaylandı — Admin.
        $this->actingAs($admin);
        Livewire::test('pages::returns.show', ['return' => $return->id])->call('approve');
        $this->assertSame(ReturnStatus::Approved, $return->fresh()->status);
        $this->assertSame(20.0, (float) $lot->fresh()->quantity, 'onay stoğa dokunmaz');

        // 3) Kargoya Verildi — iade miktarı lottan düşülür.
        $this->actingAs($clerk);
        Livewire::test('pages::returns.show', ['return' => $return->id])->set('actionNote', 'Yurtiçi Kargo 7788')->call('ship');
        $this->assertSame(ReturnStatus::Shipped, $return->fresh()->status);
        $this->assertSame(16.0, (float) $lot->fresh()->quantity);

        // 4) Tedarikçi Onayladı — tedarikçinin yanıtı kaydedilir.
        Livewire::test('pages::returns.show', ['return' => $return->id])->set('actionNote', 'Hasar kabul edildi, kredi notu kesilecek')->call('supplierApprove');
        $this->assertSame(ReturnStatus::SupplierApproved, $return->fresh()->status);

        // 5) Tamamlandı — kredi notu ile kapanır.
        Livewire::test('pages::returns.show', ['return' => $return->id])
            ->set('credit_note_number', 'KN-2026-041')->set('credit_amount', '2000')->call('complete');

        $return->refresh();
        $this->assertSame(ReturnStatus::Completed, $return->status);
        $this->assertSame('KN-2026-041', $return->credit_note_number);
        $this->assertSame(2000.0, (float) $return->credit_amount);
        $this->assertSame(16.0, (float) $lot->fresh()->quantity);

        // İade geçmişi tüm adımları kişi ve notlarıyla gösterir.
        Livewire::test('pages::returns.show', ['return' => $return->id])
            ->assertSeeInOrder(['Talep Edildi', 'Onaylandı', 'Kargoya Verildi', 'Tedarikçi Onayladı', 'Tamamlandı'])
            ->assertSee('Hasarlı — Şırınga uçları kırık geldi')
            ->assertSee('Yurtiçi Kargo 7788')
            ->assertSee('Hasar kabul edildi, kredi notu kesilecek')
            ->assertSee('KN-2026-041')
            ->assertSee($order->number())
            ->assertSee('FTR-2026-0918');
        Livewire::test('pages::returns.index')->set('statusFilter', 'open')->assertDontSee($return->number());

        // Stok hareketi ayrı bir "İade" hareketi olarak görünür; denetim kaydı ve dashboard da tutarlı.
        $movement = StockMovement::where('type', 'return')->sole();
        $this->assertSame(-4.0, (float) $movement->quantity);

        $this->actingAs($admin);
        $this->get('/stok-hareketleri')->assertOk()->assertSee('İade')->assertSee($return->number().' tedarikçiye iade');
        $this->get('/denetim-kayitlari')->assertOk()->assertSee('İade');
        Livewire::test('pages::dashboard')->assertViewHas('summary', fn (array $summary) => $summary['totalStockQuantity'] === 16.0
            && $summary['monthlyUsage'] === 0.0
            && $summary['monthlyOtherOutByReason'] === ['İade' => 4.0]);
    }
}
