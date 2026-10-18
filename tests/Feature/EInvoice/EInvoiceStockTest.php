<?php

namespace Tests\Feature\EInvoice;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\EInvoice\Clients\FakeEInvoiceProvider;
use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\EInvoice\Models\EInvoiceConnection;
use App\Domain\EInvoice\Services\EInvoiceProviderFactory;
use App\Domain\EInvoice\Services\EInvoiceStockService;
use App\Domain\EInvoice\Services\EInvoiceSyncService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 56 — gelen e-faturadan onaylı stok girişi.
 */
class EInvoiceStockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Warehouse $warehouse;

    private Product $implant;

    private Product $membrane;

    private EInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $branch = Branch::create(['organization_id' => $this->org->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $this->implant = Product::create(['name' => 'Implant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true, 'gtin' => '08699999999994']);
        $this->membrane = Product::create(['name' => 'Membran', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '04049999999991']);

        $connection = EInvoiceConnection::create(['organization_id' => $this->org->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'k']);
        $provider = new FakeEInvoiceProvider(['5d59e861-0ecc-11e7-a708-f52ed20ddd29' => file_get_contents(base_path('tests/Fixtures/einvoice/tibbi-cihaz.xml'))]);
        $this->app->instance(EInvoiceProviderFactory::class, new class($provider) extends EInvoiceProviderFactory
        {
            public function __construct(private readonly EInvoiceProvider $provider) {}

            public function for(EInvoiceConnection $connection): EInvoiceProvider
            {
                return $this->provider;
            }
        });
        app(EInvoiceSyncService::class)->sync($connection);
        $this->invoice = EInvoice::sole();
    }

    private function service(): EInvoiceStockService
    {
        return app(EInvoiceStockService::class);
    }

    public function test_draft_is_prefilled_from_the_invoice(): void
    {
        $rows = $this->service()->draft($this->invoice);

        $this->assertCount(3, $rows);
        [$implant, $membrane, $cargo] = $rows;

        $this->assertTrue($implant['include']);
        $this->assertSame($this->implant->id, $implant['product_id']);
        $this->assertSame('2', $implant['quantity']);
        $this->assertSame('IMP2026A', $implant['lot_no']);
        $this->assertSame(['SN-778812', 'SN-778813'], $implant['serials']);

        $this->assertTrue($membrane['include']);
        $this->assertSame('4', $membrane['quantity']);
        $this->assertSame('BG-55', $membrane['lot_no']);

        $this->assertFalse($cargo['include']);
        $this->assertNull($cargo['product_id']);
        $this->assertStringContainsString('eşleşmedi', $cargo['warning']);
        $this->assertNull($implant['warning']);
    }

    public function test_receive_creates_stock_with_lots_serials_cost_and_links_the_invoice(): void
    {
        $this->service()->receive($this->invoice, $this->warehouse, $this->service()->draft($this->invoice), $this->admin);

        $lot = StockLot::where('product_id', $this->implant->id)->sole();
        $this->assertSame('IMP2026A', $lot->lot_no);
        $this->assertSame(2.0, (float) $lot->quantity);
        $this->assertSame(9500.0, (float) $lot->unit_cost);
        $this->assertSame(['SN-778812', 'SN-778813'], StockSerial::where('lot_id', $lot->id)->orderBy('serial_no')->pluck('serial_no')->all());
        $this->assertSame(4.0, (float) StockLot::where('product_id', $this->membrane->id)->sole()->quantity);
        $this->assertSame(600.0, (float) StockLot::where('product_id', $this->membrane->id)->sole()->unit_cost);

        $movement = StockMovement::where('type', 'in')->first();
        $this->assertSame($this->invoice->getMorphClass(), $movement->related_entity_type);
        $this->assertStringContainsString('ADM2026000451', $movement->reason);
        $this->assertNotNull($this->invoice->fresh()->stocked_at);
    }

    public function test_invoice_cannot_be_received_twice(): void
    {
        $this->service()->receive($this->invoice, $this->warehouse, $this->service()->draft($this->invoice), $this->admin);

        $this->expectException(EInvoiceException::class);
        $this->expectExceptionMessage('zaten stoğa');

        $this->service()->receive($this->invoice->fresh(), $this->warehouse, $this->service()->draft($this->invoice), $this->admin);
    }

    public function test_invoice_matched_to_a_purchase_receipt_is_refused(): void
    {
        $this->invoice->forceFill(['purchase_receipt_id' => null])->save();
        DB::table('e_invoices')->where('id', $this->invoice->id)->update(['purchase_receipt_id' => $this->fakeReceiptId()]);

        $this->expectException(EInvoiceException::class);
        $this->expectExceptionMessage('satın alma teslim');

        $this->service()->receive($this->invoice->fresh(), $this->warehouse, $this->service()->draft($this->invoice), $this->admin);
    }

    public function test_return_and_foreign_currency_invoices_are_refused(): void
    {
        $draft = $this->service()->draft($this->invoice);

        $this->invoice->forceFill(['type' => 'IADE'])->save();
        $this->assertStringContainsString('İade faturası', $this->service()->refusal($this->invoice));

        $this->invoice->forceFill(['type' => 'SATIS', 'currency' => 'USD'])->save();

        $this->expectException(EInvoiceException::class);
        $this->expectExceptionMessage('Döviz faturası');

        $this->service()->receive($this->invoice, $this->warehouse, $draft, $this->admin);
    }

    public function test_a_failing_row_rolls_back_everything(): void
    {
        $rows = $this->service()->draft($this->invoice);
        $rows[0]['serials'] = ['SN-778812'];

        try {
            $this->service()->receive($this->invoice, $this->warehouse, $rows, $this->admin);
            $this->fail('Exception bekleniyordu.');
        } catch (EInvoiceException $e) {
            $this->assertStringContainsString('Implant 4.1', $e->getMessage());
        }

        $this->assertSame(0, StockMovement::count());
        $this->assertNull($this->invoice->fresh()->stocked_at);
    }

    public function test_included_row_needs_a_product_and_quantity(): void
    {
        $rows = $this->service()->draft($this->invoice);
        $rows[2]['include'] = true;

        $this->expectException(EInvoiceException::class);
        $this->expectExceptionMessage('Kargo bedeli');

        $this->service()->receive($this->invoice, $this->warehouse, $rows, $this->admin);
    }

    public function test_undo_cancels_the_movements_and_frees_the_invoice(): void
    {
        $this->service()->receive($this->invoice, $this->warehouse, $this->service()->draft($this->invoice), $this->admin);

        $this->service()->undo($this->invoice->fresh(), $this->admin);

        $this->assertSame(0.0, (float) StockLot::where('product_id', $this->membrane->id)->sole()->quantity);
        $this->assertSame(2, StockMovement::where('type', 'cancel')->count());
        $this->assertNull($this->invoice->fresh()->stocked_at);
    }

    public function test_undo_is_all_or_nothing_when_some_stock_was_used(): void
    {
        $this->service()->receive($this->invoice, $this->warehouse, $this->service()->draft($this->invoice), $this->admin);
        $lot = StockLot::where('product_id', $this->membrane->id)->sole();
        app(StockMovementService::class)->out($this->membrane, $this->warehouse, 1, $lot, $this->admin);

        try {
            $this->service()->undo($this->invoice->fresh(), $this->admin);
            $this->fail('Kullanılmış stok geri alınamamalı.');
        } catch (EInvoiceException $e) {
            $this->assertStringContainsString('geri alınamadı', $e->getMessage());
        }

        $this->assertSame(0, StockMovement::where('type', 'cancel')->count());
        $this->assertNotNull($this->invoice->fresh()->stocked_at);
    }

    public function test_screen_receives_after_choosing_a_warehouse(): void
    {
        Livewire::test('pages::einvoice.stock', ['invoice' => $this->invoice->id])
            ->assertSee('Implant 4.1')
            ->assertSee('SN-778812')
            ->set('warehouseId', (string) $this->warehouse->id)
            ->call('receive')
            ->assertRedirect(route('einvoice.show', $this->invoice->id));

        $this->assertNotNull($this->invoice->fresh()->stocked_at);
        $this->assertSame(2, StockMovement::where('type', 'in')->count());
    }

    public function test_screen_needs_stock_write(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Purchasing->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        $this->get(route('einvoice.stock', $this->invoice->id))->assertForbidden();
    }

    public function test_detail_shows_stock_status_and_undo(): void
    {
        Livewire::test('pages::einvoice.show', ['invoice' => $this->invoice->id])->assertSee('Stoğa al');

        $this->service()->receive($this->invoice, $this->warehouse, $this->service()->draft($this->invoice), $this->admin);

        Livewire::test('pages::einvoice.show', ['invoice' => $this->invoice->id])
            ->assertSee('Stoğa alındı')
            ->call('undoStock')
            ->assertSee('Stok girişi geri alındı');

        $this->assertNull($this->invoice->fresh()->stocked_at);
    }

    private function fakeReceiptId(): int
    {
        $supplier = Supplier::create(['name' => 'T', 'status' => 'active']);
        $order = PurchaseOrder::create(['organization_id' => $this->org->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $this->warehouse->id, 'status' => 'ordered']);

        return $order->receipts()->create(['warehouse_id' => $this->warehouse->id, 'invoice_number' => 'X'])->id;
    }
}
