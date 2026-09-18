<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Transfer\Models\TransferRequest;
use App\Domain\Transfer\Support\TransferStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * fazlar-adimlar.md Aşama 11 doğrulaması: "bir transfer talebi uçtan uca
 * yürütülür (oluştur → onayla → hazırla → gönder → teslim al); iki depodaki
 * stok da doğru zamanlarda doğru değişir. Kaynak stok yetersizken gönderim
 * denemesi reddedilir." Kurulum dahil her adım ekranlar üzerinden yapılır.
 */
class TransferAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $merkez;

    private Warehouse $besiktas;

    private Product $product;

    public function test_transfer_runs_end_to_end_and_stock_moves_at_the_right_moments(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@dental-erp.test')->sole();
        $this->actingAs($admin);
        $this->merkez = Warehouse::where('is_default', true)->sole();

        // Kurulum: ikinci şube (varsayılan deposuyla), iki taraf için personel, ürün ve stok.
        Livewire::test('pages::organization.branches')->call('create')->set('name', 'Beşiktaş Şubesi')->call('save')->assertHasNoErrors();
        $besiktasBranch = Branch::where('name', 'Beşiktaş Şubesi')->sole();
        $this->besiktas = $besiktasBranch->warehouses()->sole();

        $requester = $this->createStaff('Beşiktaş Sorumlusu', 'besiktas@dental-erp.test', $besiktasBranch);
        $shipper = $this->createStaff('Merkez Depocusu', 'merkez@dental-erp.test', $this->merkez->branch);

        Livewire::test('pages::catalog.products')->call('openForm')->set('name', 'Anestezik Kartuş')->set('base_unit', 'Adet')->call('save')->assertHasNoErrors();
        $this->product = Product::where('name', 'Anestezik Kartuş')->sole();

        Livewire::test('pages::stock.in')->call('openForm')
            ->set('product_id', (string) $this->product->id)->set('warehouse_id', (string) $this->merkez->id)
            ->set('quantity', '100')->set('lot_no', 'ANS-24')->set('expiry_date', now()->addYear()->toDateString())->set('unit_cost', '12')
            ->call('save')->assertHasNoErrors();

        // 1) Oluştur — stok değişmez.
        $this->actingAs($requester);
        Livewire::test('pages::transfer.index')->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('to_warehouse_id', (string) $this->besiktas->id)
            ->set('from_warehouse_id', (string) $this->merkez->id)
            ->set('quantity', '40')->set('reason', 'Haftalık ihtiyaç')
            ->call('save')->assertHasNoErrors();
        $transfer = TransferRequest::sole();
        $this->assertStock(100, 0, 'oluşturuldu');

        // 2) Onayla, 3) Hazırla — stok değişmez.
        $this->actingAs($shipper);
        Livewire::test('pages::transfer.index')->call('approve', $transfer->id);
        $this->assertStock(100, 0, 'onaylandı');
        Livewire::test('pages::transfer.index')->call('prepare', $transfer->id);
        $this->assertStock(100, 0, 'hazırlanıyor');

        // 4) Gönder — kaynaktan düşer, hedef henüz değişmez.
        Livewire::test('pages::transfer.index')->call('ship', $transfer->id);
        $this->assertStock(60, 0, 'gönderildi');

        // 5) Teslim al — hedefe aynı lot/SKT/fiyatla eklenir.
        $this->actingAs($requester);
        Livewire::test('pages::transfer.index')->call('receive', $transfer->id);
        $this->assertStock(60, 40, 'teslim alındı');
        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);

        $received = StockLot::where('warehouse_id', $this->besiktas->id)->sole();
        $this->assertSame('ANS-24', $received->lot_no);
        $this->assertSame(now()->addYear()->toDateString(), $received->expiry_date->toDateString());
        $this->assertSame('12.00', $received->unit_cost);

        // Kaynak stok yetersizken gönderim reddedilir.
        Livewire::test('pages::transfer.index')->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('to_warehouse_id', (string) $this->besiktas->id)
            ->set('from_warehouse_id', (string) $this->merkez->id)
            ->set('quantity', '75')
            ->call('save')->assertHasNoErrors();
        $tooMuch = TransferRequest::latest('id')->firstOrFail();

        $this->actingAs($shipper);
        Livewire::test('pages::transfer.index')->call('approve', $tooMuch->id);
        Livewire::test('pages::transfer.index')->call('prepare', $tooMuch->id);
        Livewire::test('pages::transfer.index')->call('ship', $tooMuch->id)->assertSee('Yeterli stok yok');

        $this->assertSame(TransferStatus::Preparing, $tooMuch->fresh()->status);
        $this->assertStock(60, 40, 'yetersiz stokla gönderim denemesi');

        // Depo Stokları ve dashboard aynı tabloyu görür.
        $this->actingAs($admin);
        Livewire::test('pages::reports.warehouse-stock')
            ->assertViewHas('branchSummaries', fn ($summaries) => $summaries->pluck('quantity', 'name')->all() === ['Beşiktaş Şubesi' => 40.0, 'Merkez Şube' => 60.0]);
        Livewire::test('pages::dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['totalStockQuantity'] === 100.0 && $summary['monthlyUsage'] === 0.0);
    }

    private function createStaff(string $name, string $email, Branch $branch): User
    {
        Livewire::test('pages::access.staff')->call('openForm')
            ->set('name', $name)->set('email', $email)->set('password', 'guclu-sifre-1')
            ->set('branch_id', (string) $branch->id)
            ->set('modules.transfer.read', true)->set('modules.transfer.write', true)
            ->call('save')->assertHasNoErrors();

        return User::where('email', $email)->sole();
    }

    private function assertStock(float $merkez, float $besiktas, string $moment): void
    {
        $this->assertSame($merkez, (float) StockLot::where('warehouse_id', $this->merkez->id)->sum('quantity'), "{$moment}: Merkez");
        $this->assertSame($besiktas, (float) StockLot::where('warehouse_id', $this->besiktas->id)->sum('quantity'), "{$moment}: Beşiktaş");
    }
}
