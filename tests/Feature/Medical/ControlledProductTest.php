<?php

namespace Tests\Feature\Medical;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Exports\TableExport;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Services\ControlledLedgerService;
use App\Domain\Reporting\Support\ReportExportStatus;
use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Stock\Exceptions\ControlledProductException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Aşama 26 — kontrollü ürün: her çıkışta açıklama zorunlu; Kontrollü Ürün
 * Defteri açılış/kapanış bakiyesiyle tüm hareketleri listeler.
 */
class ControlledProductTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $central;

    private Warehouse $centralWarehouse;

    private Warehouse $northWarehouse;

    private Product $midazolam;

    private User $admin;

    private StockMovementService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-18 12:00:00');

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $this->central = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $north = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kuzey', 'status' => 'active']);
        $this->centralWarehouse = Warehouse::create(['branch_id' => $this->central->id, 'name' => 'Merkez Depo', 'is_default' => true, 'status' => 'active']);
        $this->northWarehouse = Warehouse::create(['branch_id' => $north->id, 'name' => 'Kuzey Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->stock = app(StockMovementService::class);
        $this->midazolam = Product::create(['name' => 'Midazolam Ampul', 'base_unit' => 'Ampul', 'status' => 'active', 'product_type' => 'medicine', 'is_controlled' => true, 'license_number' => 'RUH-77']);

        // Ağustos: 20 giriş, 3 kullanım → Eylül açılışı 17.
        $this->travelTo('2026-08-10 10:00:00');
        $this->stock->in($this->midazolam, $this->centralWarehouse, 20, ['lot_no' => 'MDZ-1'], $this->admin);
        $this->stock->out($this->midazolam, $this->centralWarehouse, 3, null, $this->admin, 'Kullanım: sedasyon', StockOutReason::ClinicalUse, tracking: ['note' => 'Hasta sedasyonu']);
        $this->travelTo('2026-09-18 12:00:00');
    }

    public function test_stock_out_of_controlled_product_requires_a_note(): void
    {
        $this->expectException(ControlledProductException::class);

        $this->stock->out($this->midazolam, $this->centralWarehouse, 1, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse);
    }

    public function test_stock_out_screen_and_quick_screen_require_a_note(): void
    {
        Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $this->midazolam->id)
            ->assertSee('kontrollü ürün — zorunlu')
            ->set('warehouse_id', (string) $this->centralWarehouse->id)
            ->set('quantity', '1')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertHasErrors(['reasonNote'])
            ->set('reasonNote', 'Dr. Ayşe — cerrahi sedasyon')
            ->call('save')
            ->assertHasNoErrors();

        $this->midazolam->update(['barcode' => 'MDZ']);
        Livewire::test('pages::stock.quick')
            ->set('mode', 'out')
            ->set('warehouse_id', (string) $this->centralWarehouse->id)
            ->set('scanCode', 'MDZ')
            ->call('scan')
            ->assertSee('kontrollü ürün — zorunlu')
            ->set('quantity', '1')
            ->call('submit')
            ->assertHasErrors(['note'])
            ->set('note', 'Acil vaka')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertEquals(15, StockLot::sole()->quantity);
    }

    public function test_non_controlled_products_need_no_note(): void
    {
        $gloves = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->stock->in($gloves, $this->centralWarehouse, 5, [], $this->admin);

        $this->assertCount(1, $this->stock->out($gloves, $this->centralWarehouse, 1, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse));
    }

    public function test_ledger_shows_opening_balance_movements_running_balance_and_closing(): void
    {
        $this->travelTo('2026-09-05 09:00:00');
        $this->stock->in($this->midazolam, $this->centralWarehouse, 10, ['lot_no' => 'MDZ-2'], $this->admin);
        $this->travelTo('2026-09-06 09:00:00');
        $cancelled = $this->stock->out($this->midazolam, $this->centralWarehouse, 2, null, $this->admin, 'Kullanım: yanlış', StockOutReason::ClinicalUse, tracking: ['note' => 'Yanlış kayıt'])->first();
        $this->stock->cancel($cancelled, $this->admin);
        $this->stock->in($this->midazolam, $this->northWarehouse, 4, ['lot_no' => 'MDZ-K'], $this->admin);
        $this->travelTo('2026-09-18 12:00:00');

        $ledger = app(ControlledLedgerService::class)
            ->ledger($this->organization->id, ReportFilters::fromArray(['from' => '2026-09-01', 'to' => '2026-09-18']), null)
            ->first();

        $this->assertSame(17.0, $ledger['opening']);
        // +10, −2, +2 (iptal), +4 → 31; bakiye her satırda yürür.
        $this->assertSame([27.0, 25.0, 27.0, 31.0], $ledger['entries']->pluck('balance')->all());
        $this->assertSame(31.0, $ledger['closing']);
        $this->assertEquals(31, StockLot::sum('quantity'));

        // Şube filtresi: yalnızca Merkez.
        $central = app(ControlledLedgerService::class)
            ->ledger($this->organization->id, ReportFilters::fromArray(['from' => '2026-09-01', 'to' => '2026-09-18', 'branch_id' => $this->central->id]), null)
            ->first();
        $this->assertSame(27.0, $central['closing']);
    }

    public function test_ledger_screen_scope_and_export(): void
    {
        $this->get('/raporlar/kontrollu?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertSee('Kontrollü Ürün Defteri')
            ->assertSee('Midazolam Ampul')
            ->assertSee('Ruhsat RUH-77')
            ->assertSee('Hasta sedasyonu');

        Storage::fake('local');
        Excel::fake();
        Livewire::test('pages::reports.controlled')->set('from', '2026-08-01')->call('export', 'xlsx');
        $this->assertSame(ReportExportStatus::Completed, ReportExport::sole()->status);
        Excel::assertExportedInRaw(TableExport::class, fn (TableExport $table) => $table->collection()->first()[2] === 'Açılış bakiyesi'
            && $table->collection()->last()[2] === 'Kapanış bakiyesi'
            && $table->collection()->last()[6] === 17.0);

        // Raporlar yetkisi olmayan personel defteri göremez; Kuzey personeli Merkez hareketlerini görmez.
        $stockOnly = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $stockOnly->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($stockOnly)->get('/raporlar/kontrollu')->assertForbidden();

        $northStaff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->northWarehouse->branch_id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $northStaff->id, 'module' => Module::Reports->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);
        $this->actingAs($northStaff)->get('/raporlar/kontrollu?from=2026-08-01&to=2026-08-31')->assertOk()->assertDontSee('Hasta sedasyonu');
    }
}
