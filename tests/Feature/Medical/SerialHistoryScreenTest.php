<?php

namespace Tests\Feature\Medical;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 26 — Seri Takibi ekranı: güncel durum ve geçmiş, şube kapsamı ve
 * organizasyon izolasyonu.
 */
class SerialHistoryScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $north;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $central = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->north = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kuzey', 'status' => 'active']);
        $centralWarehouse = Warehouse::create(['branch_id' => $central->id, 'name' => 'Merkez Depo', 'is_default' => true, 'status' => 'active']);
        $northWarehouse = Warehouse::create(['branch_id' => $this->north->id, 'name' => 'Kuzey Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Dr. Admin', 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $implant = Product::create(['name' => 'İmplant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true, 'gtin' => '04006381333931']);
        $stock = app(StockMovementService::class);
        $stock->in($implant, $centralWarehouse, 2, ['lot_no' => 'IMP-1', 'expiry_date' => '2030-01-31'], $this->admin, 'Satın alma', tracking: ['serials' => ['IMP-SN-001', 'IMP-SN-002']]);
        $stock->out($implant, $centralWarehouse, 1, null, $this->admin, 'Hastaya uygulandı', StockOutReason::ClinicalUse, tracking: ['serials' => ['IMP-SN-001']]);
        $stock->in($implant, $northWarehouse, 1, ['lot_no' => 'IMP-K'], $this->admin, 'Kuzey girişi', tracking: ['serials' => ['KZY-SN-9']]);
    }

    public function test_history_shows_current_status_and_timeline(): void
    {
        $this->get('/seri-takibi?seri=IMP-SN-001')
            ->assertOk()
            ->assertSee('İmplant 4.1')
            ->assertSee('Çıktı')
            ->assertSee('Lot IMP-1 · SKT 31.01.2030')
            ->assertSee('Satın alma')
            ->assertSee('Hastaya uygulandı')
            ->assertSee('Dr. Admin');

        // Kutudaki GS1 kodu okutulunca seri numarası alınır.
        Livewire::test('pages::stock.serials')
            ->set('search', "010400638133393110IMP-1\x1D21IMP-SN-002")
            ->assertSet('search', 'IMP-SN-002')
            ->assertSee('Stokta');
    }

    public function test_branch_scope_and_organization_isolation(): void
    {
        $northStaff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->north->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $northStaff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);

        $this->actingAs($northStaff)->get('/seri-takibi?seri=SN')
            ->assertOk()
            ->assertSee('KZY-SN-9')
            ->assertDontSee('IMP-SN-001');

        $rival = Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $rival->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']))
            ->get('/seri-takibi?seri=SN')
            ->assertOk()
            ->assertDontSee('IMP-SN')
            ->assertSee('eşleşen seri numarası bulunamadı');
    }
}
