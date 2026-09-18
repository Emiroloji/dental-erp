<?php

namespace Tests\Feature\Returns;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Services\ReturnService;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Returns\Support\ReturnStatus;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Warehouse $warehouse;

    private Product $product;

    private Supplier $supplier;

    private User $admin;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->clerk = $this->staff($this->branch, write: true);

        $this->actingAs($this->admin);
        $this->supplier = Supplier::create(['name' => 'Dental Tedarik A.Ş.', 'status' => 'active']);
        $this->product = Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'supplier_id' => $this->supplier->id, 'status' => 'active']);
        app(StockMovementService::class)->in($this->product, $this->warehouse, 50, ['lot_no' => 'LOT001', 'unit_cost' => 500], $this->admin);
    }

    private function staff(Branch $branch, bool $write): User
    {
        $user = User::factory()->create(['organization_id' => $branch->organization_id, 'branch_id' => $branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $user->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => $write, 'can_delete' => false, 'scope' => 'own_branch']);

        return $user->fresh();
    }

    private function lot(): StockLot
    {
        return StockLot::where('lot_no', 'LOT001')->sole();
    }

    private function fillForm(): Testable
    {
        return Livewire::test('pages::returns.index')->call('openForm')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('product_id', (string) $this->product->id)
            ->set('lot_id', (string) $this->lot()->id);
    }

    public function test_a_return_is_followed_through_every_status_on_screen(): void
    {
        $this->actingAs($this->clerk);
        $this->fillForm()
            ->assertSet('supplier_id', (string) $this->supplier->id)
            ->set('quantity', '10')
            ->set('reason', 'damaged')
            ->set('reason_note', 'ambalaj yırtık')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('returns.show', SupplierReturn::sole()));
        $return = SupplierReturn::sole();

        Livewire::test('pages::returns.index')->assertSee($return->number())->assertSee('Talep Edildi');

        // Personel onaylayamaz; buton da görünmez.
        Livewire::test('pages::returns.show', ['return' => $return->id])->assertDontSee('Onayla')->call('approve');
        $this->assertSame(ReturnStatus::Requested, $return->fresh()->status);

        $this->actingAs($this->admin);
        Livewire::test('pages::returns.show', ['return' => $return->id])->assertSee('Onayla')->call('approve');
        $this->assertSame(ReturnStatus::Approved, $return->fresh()->status);

        $this->actingAs($this->clerk);
        Livewire::test('pages::returns.show', ['return' => $return->id])
            ->set('actionNote', 'Yurtiçi Kargo 123456')->call('ship')
            ->assertSee('Kargoya Verildi')->assertSee('Yurtiçi Kargo 123456');
        $this->assertSame(40.0, (float) $this->lot()->quantity);

        Livewire::test('pages::returns.show', ['return' => $return->id])
            ->set('actionNote', 'Ürün kabul edildi')->call('supplierApprove')
            ->set('credit_note_number', 'KN-77')->set('credit_amount', '5000')->call('complete')
            ->assertSee('Tamamlandı')->assertSee('KN-77')->assertSee('Ürün kabul edildi');

        $this->assertSame(ReturnStatus::Completed, $return->fresh()->status);
        $this->assertSame(40.0, (float) $this->lot()->quantity);
    }

    public function test_supplier_order_and_invoice_are_suggested_from_the_purchase_receipt_of_the_lot(): void
    {
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->supplier, $this->warehouse, [['product_id' => $this->product->id, 'quantity' => 20, 'unit_price' => 400]], null, null, $this->admin);
        $orders->submit($order, $this->admin);
        $orders->approve($order, $this->admin);
        $orders->markOrdered($order, $this->admin);
        $orders->receive($order, [$order->lines()->sole()->id => ['quantity' => 20, 'lot_no' => 'SA-LOT']], ['invoice_number' => 'FTR-2026-1'], $this->admin);
        $lot = StockLot::where('lot_no', 'SA-LOT')->sole();

        $this->actingAs($this->clerk);
        Livewire::test('pages::returns.index')->call('openForm')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('product_id', (string) $this->product->id)
            ->set('supplier_id', '')
            ->set('lot_id', (string) $lot->id)
            ->assertSet('supplier_id', (string) $this->supplier->id)
            ->assertSet('purchase_order_id', (string) $order->id)
            ->assertSet('invoice_number', 'FTR-2026-1')
            ->set('quantity', '5')->set('reason', 'defective')
            ->call('save')->assertHasNoErrors();

        $return = SupplierReturn::sole();
        $this->assertSame($order->id, $return->purchase_order_id);
        $this->assertSame('FTR-2026-1', $return->invoice_number);

        Livewire::test('pages::returns.show', ['return' => $return->id])->assertSee($order->number())->assertSee('FTR-2026-1');
    }

    public function test_form_errors_are_shown_to_the_user(): void
    {
        $this->actingAs($this->clerk);

        $this->fillForm()->set('quantity', '5')->set('reason', 'other')->call('save')->assertHasErrors(['reason_note' => 'required']);
        $this->fillForm()->set('quantity', '60')->set('reason', 'damaged')->call('save')->assertHasErrors('quantity');

        $otherWarehouse = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Cerrahi Depo', 'is_default' => false, 'status' => 'active']);
        $this->fillForm()->set('warehouse_id', (string) $otherWarehouse->id)->set('product_id', (string) $this->product->id)
            ->set('lot_id', (string) $this->lot()->id)->set('quantity', '5')->set('reason', 'damaged')
            ->call('save')->assertHasErrors('lot_id');

        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_shipping_with_insufficient_lot_stock_shows_an_error(): void
    {
        $returns = app(ReturnService::class);
        $return = $returns->request($this->lot(), 30, ReturnReason::Damaged, $this->supplier, $this->clerk);
        $returns->approve($return, $this->admin);
        app(StockMovementService::class)->out($this->product, $this->warehouse, 25, $this->lot(), $this->admin, 'Kullanım', StockOutReason::ClinicalUse);

        $this->actingAs($this->clerk);
        Livewire::test('pages::returns.show', ['return' => $return->id])->call('ship')->assertSee('yeterli stok yok');

        $this->assertSame(ReturnStatus::Approved, $return->fresh()->status);
    }

    public function test_scope_read_only_access_and_organization_isolation(): void
    {
        $return = app(ReturnService::class)->request($this->lot(), 5, ReturnReason::Damaged, $this->supplier, $this->clerk);

        // Salt okur: görür ama talep açamaz, aksiyon göremez.
        $reader = $this->staff($this->branch, write: false);
        $this->actingAs($reader);
        $this->get('/iadeler')->assertOk()->assertSee($return->number());
        Livewire::test('pages::returns.index')->call('openForm')->assertForbidden();
        $this->actingAs($this->admin);
        app(ReturnService::class)->approve($return, $this->admin);
        $this->actingAs($reader);
        Livewire::test('pages::returns.show', ['return' => $return->id])->assertDontSee('Kargoya Verildi')->call('ship');
        $this->assertSame(ReturnStatus::Approved, $return->fresh()->status);

        // Başka şube kapsamındaki personel iadeyi göremez.
        $otherBranch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Şube B', 'status' => 'active']);
        $this->actingAs($this->staff($otherBranch, write: true));
        $this->get('/iadeler')->assertOk()->assertDontSee($return->number());
        $this->get("/iadeler/{$return->id}")->assertNotFound();

        // Başka organizasyon hiç göremez.
        $foreignOrg = Organization::create(['name' => 'Başka Klinik', 'status' => 'active', 'plan' => 'starter']);
        $foreignAdmin = User::factory()->create(['organization_id' => $foreignOrg->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($foreignAdmin);
        $this->get('/iadeler')->assertOk()->assertDontSee($return->number());
        $this->get("/iadeler/{$return->id}")->assertNotFound();

        // Stok modülü yetkisi olmayan personel sayfaya giremez.
        $this->actingAs(User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']));
        $this->get('/iadeler')->assertForbidden();
    }
}
