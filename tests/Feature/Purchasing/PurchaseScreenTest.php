<?php

namespace Tests\Feature\Purchasing;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Domain\Stock\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $kadikoy;

    private Branch $besiktas;

    private Warehouse $kadikoyDepot;

    private Warehouse $besiktasDepot;

    private Supplier $supplier;

    private Product $gloves;

    private Product $masks;

    private User $admin;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->kadikoy = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kadıköy Şubesi', 'status' => 'active']);
        $this->besiktas = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Beşiktaş Şubesi', 'status' => 'active']);
        $this->kadikoyDepot = Warehouse::create(['branch_id' => $this->kadikoy->id, 'name' => 'Kadıköy Deposu', 'is_default' => true, 'status' => 'active']);
        $this->besiktasDepot = Warehouse::create(['branch_id' => $this->besiktas->id, 'name' => 'Beşiktaş Deposu', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($this->admin);
        $this->supplier = Supplier::create(['name' => 'Dental Tedarik A.Ş.', 'status' => 'active']);
        $this->gloves = Product::create(['name' => 'Lateks Eldiven', 'base_unit' => 'Adet', 'purchase_price' => 2.5, 'status' => 'active']);
        $this->masks = Product::create(['name' => 'Cerrahi Maske', 'base_unit' => 'Adet', 'purchase_price' => 1, 'status' => 'active']);

        $this->buyer = $this->staff('Kadıköy Satınalmacısı', $this->kadikoy);
    }

    private function staff(string $name, Branch $branch, bool $write = true): User
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'name' => $name, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Purchasing->value, 'can_read' => true, 'can_write' => $write, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);

        return $staff->fresh();
    }

    private function fillForm()
    {
        return Livewire::test('pages::purchasing.index')
            ->call('create')
            ->set('supplier_id', (string) $this->supplier->id)
            ->set('warehouse_id', (string) $this->kadikoyDepot->id)
            ->set('expected_delivery_date', now()->addWeek()->toDateString())
            ->set('note', 'Aylık sarf')
            ->set('lines.0.product_id', (string) $this->gloves->id)
            ->set('lines.0.quantity', '100')
            ->set('lines.0.unit_price', '2.5')
            ->call('addLine')
            ->set('lines.1.product_id', (string) $this->masks->id)
            ->set('lines.1.quantity', '50')
            ->set('lines.1.unit_price', '1');
    }

    public function test_the_full_flow_runs_through_the_screen(): void
    {
        Storage::fake('local');
        $this->actingAs($this->buyer);

        $this->fillForm()->call('save', true)->assertHasNoErrors();
        $order = PurchaseOrder::sole();
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $order->status);
        $this->assertSame(300.0, $order->total());

        Livewire::test('pages::purchasing.index')->call('approve', $order->id)->assertForbidden();

        $this->actingAs($this->admin);
        Livewire::test('pages::purchasing.index')->call('approve', $order->id);

        $this->actingAs($this->buyer);
        Livewire::test('pages::purchasing.index')->call('markOrdered', $order->id);
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->fresh()->status);

        $glovesLine = $order->lines->firstWhere('product_id', $this->gloves->id);
        $masksLine = $order->lines->firstWhere('product_id', $this->masks->id);

        Livewire::test('pages::purchasing.index')
            ->call('openReceipt', $order->id)
            ->assertSet("receiptLines.{$glovesLine->id}.quantity", '100')
            ->set('invoice_number', 'FTR-2024-77')
            ->set('delivery_note_number', 'IRS-12')
            ->set('document', UploadedFile::fake()->create('fatura.pdf', 120, 'application/pdf'))
            ->set("receiptLines.{$glovesLine->id}.quantity", '60')
            ->set("receiptLines.{$glovesLine->id}.lot_no", 'ELD-99')
            ->set("receiptLines.{$glovesLine->id}.expiry_date", now()->addYears(2)->toDateString())
            ->set("receiptLines.{$masksLine->id}.quantity", '0')
            ->call('receive')
            ->assertHasNoErrors();

        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);
        $this->assertSame(60.0, (float) StockLot::where('lot_no', 'ELD-99')->where('warehouse_id', $this->kadikoyDepot->id)->value('quantity'));

        $receipt = PurchaseReceipt::sole();
        $this->assertSame('FTR-2024-77', $receipt->invoice_number);
        Storage::disk('local')->assertExists($receipt->document_path);

        Livewire::test('pages::purchasing.index')
            ->call('showDetail', $order->id)
            ->assertSee('FTR-2024-77')
            ->assertSee('ELD-99')
            ->assertSee('Kısmi Teslim');

        $this->get(route('purchasing.receipts.document', $receipt))->assertOk()->assertDownload('fatura.pdf');
    }

    public function test_open_orders_filter_lists_remaining_quantities(): void
    {
        $orders = app(PurchaseOrderService::class);
        $open = $orders->create($this->supplier, $this->kadikoyDepot, [['product_id' => $this->gloves->id, 'quantity' => 100, 'unit_price' => 2]], null, 'Açık olan', $this->admin);
        $orders->submit($open, $this->admin);
        $orders->approve($open, $this->admin);
        $orders->markOrdered($open, $this->admin);
        $orders->receive($open, [$open->lines()->sole()->id => ['quantity' => 60]], [], $this->admin);
        $orders->create($this->supplier, $this->kadikoyDepot, [['product_id' => $this->masks->id, 'quantity' => 5, 'unit_price' => 1]], null, 'Taslak olan', $this->admin);

        Livewire::test('pages::purchasing.index')
            ->set('statusFilter', 'open')
            ->assertSee($open->number())
            ->assertSee('40,00 kalan')
            ->assertDontSee('SA-00002');
    }

    public function test_receiving_more_than_remaining_shows_an_error(): void
    {
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->supplier, $this->kadikoyDepot, [['product_id' => $this->gloves->id, 'quantity' => 10, 'unit_price' => 2]], null, null, $this->admin);
        $orders->submit($order, $this->admin);
        $orders->approve($order, $this->admin);
        $orders->markOrdered($order, $this->admin);
        $line = $order->lines()->sole();

        Livewire::test('pages::purchasing.index')
            ->call('openReceipt', $order->id)
            ->set("receiptLines.{$line->id}.quantity", '11')
            ->call('receive')
            ->assertHasErrors("receiptLines.{$line->id}.quantity");

        $this->assertSame(0, StockLot::count());
    }

    public function test_form_validation_rejects_duplicates_empty_lines_and_out_of_scope_warehouses(): void
    {
        $this->actingAs($this->buyer);

        $this->fillForm()->set('lines.1.product_id', (string) $this->gloves->id)->call('save', false)->assertHasErrors('lines.1.product_id');
        $this->fillForm()->set('warehouse_id', (string) $this->besiktasDepot->id)->call('save', false)->assertHasErrors('warehouse_id');
        $this->fillForm()->call('removeLine', 1)->call('removeLine', 0)->call('save', false)->assertHasErrors('lines');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_a_draft_can_be_edited_until_it_is_submitted(): void
    {
        $this->actingAs($this->buyer);
        $this->fillForm()->call('save', false)->assertHasNoErrors();
        $order = PurchaseOrder::sole();
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);

        Livewire::test('pages::purchasing.index')
            ->call('edit', $order->id)
            ->assertSet('lines.0.quantity', '100.00')
            ->set('lines.0.quantity', '120')
            ->call('removeLine', 1)
            ->call('save', true)
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $order->status);
        $this->assertSame([120.0], $order->lines->pluck('quantity')->map(fn ($q) => (float) $q)->all());

        Livewire::test('pages::purchasing.index')->call('edit', $order->id)->assertSee('Yalnızca taslak');
    }

    public function test_orders_and_documents_are_only_visible_within_scope(): void
    {
        Storage::fake('local');
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->supplier, $this->kadikoyDepot, [['product_id' => $this->gloves->id, 'quantity' => 10, 'unit_price' => 2]], null, null, $this->admin);
        $orders->submit($order, $this->admin);
        $orders->approve($order, $this->admin);
        $orders->markOrdered($order, $this->admin);
        Storage::disk('local')->put('purchase-documents/x/fatura.pdf', 'pdf');
        $receipt = $orders->receive($order, [$order->lines()->sole()->id => ['quantity' => 5]], ['document_path' => 'purchase-documents/x/fatura.pdf', 'document_name' => 'fatura.pdf'], $this->admin);

        $outsider = $this->staff('Beşiktaş Satınalmacısı', $this->besiktas);
        $this->actingAs($outsider)->get('/satin-alma')->assertOk()->assertDontSee($order->number());
        Livewire::test('pages::purchasing.index')->call('showDetail', $order->id)->assertNotFound();
        $this->get(route('purchasing.receipts.document', $receipt))->assertNotFound();

        $otherOrgAdmin = User::factory()->create(['organization_id' => Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter'])->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($otherOrgAdmin)->get('/satin-alma')->assertOk()->assertDontSee($order->number());
        $this->get(route('purchasing.receipts.document', $receipt))->assertNotFound();
    }

    public function test_access_requires_the_purchasing_module(): void
    {
        $reader = $this->staff('Okur', $this->kadikoy, write: false);
        $this->actingAs($reader)->get('/satin-alma')->assertOk()->assertDontSee('Yeni Talep');

        $none = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->kadikoy->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        $this->actingAs($none)->get('/satin-alma')->assertForbidden();
    }
}
