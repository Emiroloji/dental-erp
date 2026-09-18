<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * fazlar-adimlar.md Aşama 13 doğrulaması: proje.md Bölüm 10'daki örnek
 * senaryo (sistem 100, sayım 97, fark −3, neden "kullanım kaydı eksik
 * girilmiş", onaylayan Admin) uçtan uca çalıştırılır; stok 97'ye düzeltilir
 * ve denetim kaydına işlenir. Kurulum dahil her adım ekranlardan.
 */
class StockCountAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_documented_count_example_runs_end_to_end(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@dental-erp.test')->sole();
        $warehouse = Warehouse::where('is_default', true)->sole();
        $this->actingAs($admin);

        Livewire::test('pages::catalog.products')->call('openForm')->set('name', 'Kompozit A')->set('base_unit', 'Adet')->call('save')->assertHasNoErrors();
        $product = Product::sole();

        Livewire::test('pages::stock.in')->call('openForm')
            ->set('product_id', (string) $product->id)->set('warehouse_id', (string) $warehouse->id)
            ->set('quantity', '100')->set('lot_no', 'LOT001')->set('unit_cost', '500')
            ->call('save')->assertHasNoErrors();

        Livewire::test('pages::access.staff')->call('openForm')
            ->set('name', 'Sayım Görevlisi')->set('email', 'sayim@dental-erp.test')->set('password', 'guclu-sifre-1')
            ->set('branch_id', (string) $warehouse->branch_id)
            ->set('modules.stock_movement.read', true)->set('modules.stock_movement.write', true)
            ->call('save')->assertHasNoErrors();
        $counter = User::where('email', 'sayim@dental-erp.test')->sole();

        // Sayım başlatılır (depo seçilir) → sistem beklenen miktarı gösterir.
        $this->actingAs($counter);
        Livewire::test('pages::inventory.index')->call('openStart')
            ->set('warehouse_id', (string) $warehouse->id)->set('note', 'Ay sonu sayımı')
            ->call('start');
        $count = StockCount::sole();
        $line = $count->lines()->sole();
        $this->assertSame(100.0, (float) $line->system_quantity);

        // Sayan personel 97 girer → fark −3 → neden seçilir → onaya gönderilir.
        Livewire::test('pages::inventory.show', ['count' => $count->id])
            ->set("entries.{$line->id}.counted_quantity", '97')
            ->assertSee('-3,00')
            ->set("entries.{$line->id}.reason", 'record_error')
            ->set("entries.{$line->id}.note", 'kullanım kaydı eksik girilmiş')
            ->call('submit');
        $this->assertSame(StockCountStatus::PendingApproval, $count->fresh()->status);
        $this->assertSame(100.0, (float) StockLot::sole()->quantity, 'onaydan önce stok değişmez');

        // Admin onaylar → fark düzeltme hareketi olarak stoğa işlenir.
        $this->actingAs($admin);
        Livewire::test('pages::inventory.show', ['count' => $count->id])->call('approve');

        $this->assertSame(StockCountStatus::Approved, $count->fresh()->status);
        $this->assertSame(97.0, (float) StockLot::sole()->quantity);

        $movement = StockMovement::where('type', 'count_adjust')->sole();
        $this->assertSame(-3.0, (float) $movement->quantity);
        $this->assertSame($admin->id, $movement->actor_id);

        // Stok Hareketleri'nde düzeltme hareketi nedeniyle görünür.
        $this->get('/stok-hareketleri')->assertOk()->assertSee('Sayım Düzeltmesi')->assertSee('kullanım kaydı eksik girilmiş');

        // Denetim kaydında: kim onayladı, önceki ve yeni miktar.
        Livewire::test('pages::audit.index')
            ->set('entityFilter', StockCount::class)
            ->assertSee('Stok Sayımı')
            ->assertSee('Kompozit A / LOT001')
            ->assertSee('100')
            ->assertSee('97')
            ->assertSee($admin->name);

        // Dashboard toplamı da 97.
        Livewire::test('pages::dashboard')->assertViewHas('summary', fn (array $summary) => $summary['totalStockQuantity'] === 97.0);
    }
}
