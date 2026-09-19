<?php

namespace Tests\Feature\Organization;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Support\ExpiredLotPolicy;
use App\Domain\Stock\Exceptions\ExpiredLotBlockedException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpiredLotPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $warehouse;

    private Product $product;

    private User $admin;

    private StockLot $expiredLot;

    private StockLot $validLot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->product = Product::create(['organization_id' => $this->organization->id, 'name' => 'Anestezik', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $service = app(StockMovementService::class);
        $this->expiredLot = $service->in($this->product, $this->warehouse, 10, ['lot_no' => 'ESKI', 'expiry_date' => now()->subDays(3)->toDateString()])->lot;
        $this->validLot = $service->in($this->product, $this->warehouse, 20, ['lot_no' => 'YENI', 'expiry_date' => now()->addYear()->toDateString()])->lot;
    }

    private function block(): void
    {
        $this->organization->update(['settings' => ['expired_lot_policy' => ExpiredLotPolicy::Block->value]]);
    }

    public function test_default_policy_is_warn(): void
    {
        $this->assertSame(ExpiredLotPolicy::Warn, $this->organization->fresh()->expiredLotPolicy());
    }

    public function test_warn_policy_allows_fefo_from_expired_lot_and_flags_it_on_screen(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '4')
            ->set('reasonCategory', StockOutReason::ClinicalUse->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Dikkat: son kullanma tarihi geçmiş lottan çıkış yapıldı (ESKI).');

        // FEFO önce SKT'si en yakın (geçmiş) lotu kullanır; engel yok, uyarı var.
        $this->assertEquals(6, $this->expiredLot->fresh()->quantity);
    }

    public function test_block_policy_fefo_skips_expired_lots(): void
    {
        $this->block();

        app(StockMovementService::class)->out($this->product, $this->warehouse, 5, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse);

        $this->assertEquals(10, $this->expiredLot->fresh()->quantity);
        $this->assertEquals(15, $this->validLot->fresh()->quantity);
    }

    public function test_block_policy_rejects_usage_when_only_expired_stock_would_cover_it(): void
    {
        $this->block();

        try {
            app(StockMovementService::class)->out($this->product, $this->warehouse, 25, null, $this->admin, 'Kullanım', StockOutReason::ClinicalUse);
            $this->fail('SKT\'si geçmiş stok kullanım için sayılmamalıydı.');
        } catch (ExpiredLotBlockedException $e) {
            $this->assertStringContainsString('engelli', $e->getMessage());
        }

        $this->assertEquals(10, $this->expiredLot->fresh()->quantity);
        $this->assertEquals(20, $this->validLot->fresh()->quantity);
    }

    public function test_block_policy_rejects_manually_selected_expired_lot_on_screen(): void
    {
        $this->block();
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('lot_id', (string) $this->expiredLot->id)
            ->set('quantity', '2')
            ->set('reasonCategory', StockOutReason::Consumption->value)
            ->call('save')
            ->assertHasErrors(['quantity']);

        $this->assertEquals(10, $this->expiredLot->fresh()->quantity);
    }

    public function test_block_policy_still_allows_disposal_of_expired_lot(): void
    {
        $this->block();
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.out')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('lot_id', (string) $this->expiredLot->id)
            ->set('quantity', '10')
            ->set('reasonCategory', StockOutReason::Expired->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDontSee('Dikkat: son kullanma tarihi geçmiş');

        $this->assertEquals(0, $this->expiredLot->fresh()->quantity);
    }

    public function test_block_policy_applies_to_quick_scan_screen(): void
    {
        $this->block();
        $this->product->update(['barcode' => '8690000000011']);
        $this->actingAs($this->admin);

        Livewire::test('pages::stock.quick')
            ->set('mode', 'out')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('scanCode', '8690000000011')
            ->call('scan')
            ->set('lot_id', (string) $this->expiredLot->id)
            ->set('quantity', '1')
            ->set('reasonCategory', StockOutReason::ClinicalUse->value)
            ->call('submit')
            ->assertHasErrors(['quantity']);

        $this->assertEquals(10, $this->expiredLot->fresh()->quantity);
    }

    public function test_admin_changes_policy_on_settings_screen_and_it_is_audited(): void
    {
        $this->actingAs($this->admin);

        $this->get('/ayarlar')->assertOk()->assertSee('Kullanımı tamamen engelle');

        Livewire::test('pages::settings.index')
            ->set('expiredLotPolicy', 'block')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(ExpiredLotPolicy::Block, $this->organization->fresh()->expiredLotPolicy());
        $this->assertTrue(AuditLog::where('entity_type', $this->organization->getMorphClass())->where('entity_id', $this->organization->id)->where('action', 'updated')->exists());
    }

    public function test_staff_with_read_only_settings_permission_cannot_change_policy(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create([
            'user_id' => $staff->id,
            'module' => Module::SystemSettings->value,
            'can_read' => true,
            'can_write' => false,
            'can_delete' => false,
            'scope' => PermissionScope::All->value,
        ]);

        $this->actingAs($staff);
        $this->get('/ayarlar')->assertOk();

        Livewire::test('pages::settings.index')
            ->set('expiredLotPolicy', 'block')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(ExpiredLotPolicy::Warn, $this->organization->fresh()->expiredLotPolicy());
    }

    public function test_staff_without_settings_permission_cannot_open_settings(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->actingAs($staff)->get('/ayarlar')->assertForbidden();
    }

    public function test_policy_of_one_organization_does_not_affect_another(): void
    {
        $this->block();

        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherBranch = Branch::create(['organization_id' => $other->id, 'name' => 'Merkez', 'status' => 'active']);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $otherProduct = Product::create(['organization_id' => $other->id, 'name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);

        $service = app(StockMovementService::class);
        $lot = $service->in($otherProduct, $otherWarehouse, 5, ['lot_no' => 'X', 'expiry_date' => now()->subDay()->toDateString()])->lot;
        $service->out($otherProduct, $otherWarehouse, 2, null, null, 'Kullanım', StockOutReason::ClinicalUse);

        $this->assertEquals(3, $lot->fresh()->quantity);
    }
}
