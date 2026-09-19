<?php

namespace Tests\Feature\Stock;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 20 — barkod/QR ile hızlı stok giriş-çıkışı. Doğrulama: okutulan ürün
 * tek adımda stoğa işlenir; kutu olarak girilen miktar ana birime çevrilir;
 * lot etiketi okutulunca o lottan işlem yapılır; hareketler diğer ekranlarla
 * tutarlıdır (tek giriş noktası StockMovementService).
 */
class QuickScanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Warehouse $warehouse;

    private Warehouse $surgery;

    private User $admin;

    private Product $composite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $this->surgery = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Cerrahi Depo', 'is_default' => false, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->composite = Product::create([
            'name' => 'Kompozit A', 'code' => 'KMP-A', 'barcode' => '8690000000011', 'base_unit' => 'Adet', 'status' => 'active',
            'purchase_price' => 40, 'conversion_rules' => [['unit' => 'Kutu', 'factor' => 50]],
        ]);
    }

    private function screen()
    {
        return Livewire::test('pages::stock.quick');
    }

    public function test_scanning_a_barcode_books_a_stock_in_with_unit_conversion(): void
    {
        $this->screen()
            ->assertSet('warehouse_id', (string) $this->warehouse->id)
            ->set('mode', 'in')
            ->set('scanCode', '8690000000011')->call('scan')
            ->assertSee('Kompozit A')->assertSet('unit', 'Adet')->assertSet('unit_cost', '40.00')
            ->set('quantity', '3')->set('unit', 'Kutu')->set('lot_no', 'L-1')->set('expiry_date', now()->addYear()->toDateString())
            ->call('submit')->assertHasNoErrors()
            ->assertSee('Giriş kaydedildi: 150 Adet Kompozit A')
            ->assertSet('productId', null)
            ->assertDispatched('scan-ready');

        $lot = StockLot::sole();
        $this->assertSame(150.0, (float) $lot->quantity, '3 Kutu = 150 Adet (kurallar.md Bölüm 3)');
        $this->assertSame('L-1', $lot->lot_no);
        $this->assertSame('40.00', $lot->unit_cost);

        $movement = StockMovement::sole();
        $this->assertSame('in', $movement->type->value);
        $this->assertStringContainsString('(3 Kutu)', $movement->reason);
        $this->assertSame($this->admin->id, $movement->actor_id);

        // Diğer ekranlarla tutarlı.
        $this->get('/stok-hareketleri')->assertOk()->assertSee('Hızlı giriş (barkod)');
        $this->get('/stok-durumu')->assertOk()->assertSee('L-1');
    }

    public function test_scanning_a_product_code_books_a_stock_out_with_fefo(): void
    {
        $stock = app(StockMovementService::class);
        $stock->in($this->composite, $this->warehouse, 20, ['lot_no' => 'EARLY', 'expiry_date' => now()->addMonths(2)->toDateString()], $this->admin);
        $stock->in($this->composite, $this->warehouse, 20, ['lot_no' => 'LATE', 'expiry_date' => now()->addYear()->toDateString()], $this->admin);

        $this->screen()->set('mode', 'out')
            ->set('scanCode', ' KMP-A ')->call('scan')
            ->assertSeeText('Bu depoda: 40 Adet')
            ->set('quantity', '5')->set('reasonCategory', 'clinical_use')
            ->call('submit')->assertHasNoErrors()
            ->assertSee('Depoda kalan: 35 Adet');

        $this->assertSame(15.0, (float) StockLot::where('lot_no', 'EARLY')->sole()->quantity, 'lot seçilmezse SKT\'si en yakın lot');
        $this->assertSame(20.0, (float) StockLot::where('lot_no', 'LATE')->sole()->quantity);
        $this->assertSame('clinical_use', StockMovement::where('type', 'out')->sole()->reason_code->value);
    }

    public function test_scanning_a_lot_label_selects_that_lot_and_its_warehouse(): void
    {
        $stock = app(StockMovementService::class);
        $stock->in($this->composite, $this->surgery, 20, ['lot_no' => 'EARLY', 'expiry_date' => now()->addMonths(2)->toDateString()], $this->admin);
        $stock->in($this->composite, $this->surgery, 20, ['lot_no' => 'LATE', 'expiry_date' => now()->addYear()->toDateString()], $this->admin);
        $late = StockLot::where('lot_no', 'LATE')->sole();

        // Çıkış: FEFO yerine okutulan lottan düşülür; depo lotun deposuna geçer.
        $this->screen()->set('mode', 'out')
            ->set('scanCode', "DERP:L:{$late->id}")->call('scan')
            ->assertSet('warehouse_id', (string) $this->surgery->id)
            ->assertSet('lot_id', (string) $late->id)
            ->set('quantity', '4')->call('submit')->assertHasNoErrors();

        $this->assertSame(16.0, (float) $late->fresh()->quantity);
        $this->assertSame(20.0, (float) StockLot::where('lot_no', 'EARLY')->sole()->quantity);

        // Giriş: aynı lota eklenir (lot no, SKT ve maliyet etiketten gelir).
        $this->screen()->set('mode', 'in')
            ->set('scanCode', "DERP:L:{$late->id}")->call('scan')
            ->assertSet('lot_no', 'LATE')
            ->set('quantity', '1')->set('unit', 'Kutu')->call('submit')->assertHasNoErrors();

        $this->assertSame(66.0, (float) $late->fresh()->quantity);
        $this->assertSame(2, StockLot::count(), 'yeni lot açılmadı');
    }

    public function test_scan_errors_are_explained(): void
    {
        $screen = $this->screen();

        $screen->set('scanCode', '0000')->call('scan')->assertSet('productId', null)->assertSee('kayıtlı aktif bir ürün yok');

        Product::create(['name' => 'Kompozit B', 'barcode' => '5550001', 'base_unit' => 'Adet', 'status' => 'active']);
        Product::create(['name' => 'Kompozit C', 'barcode' => '5550001', 'base_unit' => 'Adet', 'status' => 'active']);
        $screen->set('scanCode', '5550001')->call('scan')->assertSee('birden fazla üründe kayıtlı (Kompozit B, Kompozit C)');

        $passive = Product::create(['name' => 'Eski Ürün', 'base_unit' => 'Adet', 'status' => 'passive']);
        $screen->set('scanCode', "DERP:P:{$passive->id}")->call('scan')->assertSee('pasif bir ürün');

        // Yetersiz stok: hata miktar alanında, stok değişmez.
        $screen->set('mode', 'out')->set('scanCode', '8690000000011')->call('scan')
            ->set('quantity', '1')->call('submit')->assertHasErrors('quantity');
        $this->assertSame(0, StockMovement::count());

        // Tanımsız birim reddedilir.
        $screen->set('scanCode', '8690000000011')->call('scan')->set('unit', 'Palet')->call('submit')->assertHasErrors('unit');
    }

    public function test_other_organizations_and_out_of_scope_lots_cannot_be_scanned(): void
    {
        $other = Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter']);
        $otherBranch = Branch::create(['organization_id' => $other->id, 'name' => 'Rakip Şube', 'status' => 'active']);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'name' => 'Rakip Depo', 'is_default' => true, 'status' => 'active']);
        $otherAdmin = User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($otherAdmin);
        $rivalProduct = Product::create(['name' => 'Rakip Ürün', 'barcode' => '7770001', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($rivalProduct, $otherWarehouse, 10, ['lot_no' => 'R-1'], $otherAdmin);
        $rivalLot = StockLot::where('lot_no', 'R-1')->sole();

        $this->actingAs($this->admin);
        $this->screen()->set('scanCode', '7770001')->call('scan')->assertSet('productId', null)->assertDontSee('Rakip Ürün');
        $this->screen()->set('scanCode', "DERP:L:{$rivalLot->id}")->call('scan')->assertSet('productId', null)->assertSee('lot etiketi bulunamadı');
        $this->screen()->set('scanCode', "DERP:P:{$rivalProduct->id}")->call('scan')->assertSet('productId', null);

        // Aynı organizasyonda ama kapsam dışı şubenin lotu.
        $north = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kuzey', 'status' => 'active']);
        $northWarehouse = Warehouse::create(['branch_id' => $north->id, 'name' => 'Kuzey Depo', 'is_default' => true, 'status' => 'active']);
        app(StockMovementService::class)->in($this->composite, $northWarehouse, 10, ['lot_no' => 'N-1'], $this->admin);
        $northLot = StockLot::where('lot_no', 'N-1')->sole();

        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => 'own_branch']);
        $this->actingAs($staff->fresh());
        $this->screen()->set('scanCode', "DERP:L:{$northLot->id}")->call('scan')->assertSet('productId', null)->assertSee('erişiminiz olan bir depoya ait değil');
        $this->screen()->assertViewHas('warehouses', fn ($warehouses) => ! $warehouses->contains('id', $northWarehouse->id));
    }

    public function test_read_only_stock_permission_can_scan_but_not_book(): void
    {
        $reader = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $reader->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => 'own_branch']);
        $this->actingAs($reader->fresh());

        $this->get('/hizli-islem')->assertOk();
        $this->screen()->set('scanCode', '8690000000011')->call('scan')->assertSee('Kompozit A')->assertDontSee('Çıkışı Kaydet')
            ->call('submit')->assertForbidden();

        $this->actingAs(User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']));
        $this->get('/hizli-islem')->assertForbidden();
    }

    public function test_labels_encode_the_scan_payload_and_respect_isolation(): void
    {
        app(StockMovementService::class)->in($this->composite, $this->warehouse, 10, ['lot_no' => 'L-9', 'expiry_date' => '2027-05-01'], $this->admin);
        $lot = StockLot::sole();

        $this->get("/etiket/urun/{$this->composite->id}?adet=3")->assertOk()
            ->assertSee('<svg', false)->assertSee("DERP:P:{$this->composite->id}")->assertSee('Barkod 8690000000011');
        $this->assertSame(3, substr_count($this->get("/etiket/urun/{$this->composite->id}?adet=3")->getContent(), 'class="label"'));

        $this->get("/etiket/lot/{$lot->id}")->assertOk()->assertSee("DERP:L:{$lot->id}")->assertSee('Lot L-9')->assertSee('SKT 01.05.2027')->assertSee('Ana Depo');

        $otherAdmin = User::factory()->create(['organization_id' => Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter'])->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($otherAdmin);
        $this->get("/etiket/urun/{$this->composite->id}")->assertNotFound();
        $this->get("/etiket/lot/{$lot->id}")->assertNotFound();
    }

    public function test_product_barcode_is_unique_within_the_organization(): void
    {
        $create = fn (string $name) => Livewire::test('pages::catalog.products')->call('openForm')
            ->set('name', $name)->set('base_unit', 'Adet')->set('barcode', '8690000000011')->call('save');

        $create('Kopya Ürün')->assertHasErrors('barcode');

        $other = Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        $create('Rakibin Ürünü')->assertHasNoErrors();
    }
}
