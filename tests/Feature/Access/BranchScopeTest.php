<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Services\DashboardMetricsService;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Notifications\StockLevelAlert;
use App\Domain\Stock\Services\StockAlertService;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * kurallar.md Bölüm 4: "Personel yalnızca kendisine yetkilendirilen şube/depo
 * verisini görebilir; kapsam dışı veriye hiçbir ekranda erişemez."
 */
class BranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branchA;

    private Branch $branchB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Product $product;

    private User $admin;

    private StockMovement $movementB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->branchA = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kadıköy Şubesi', 'status' => 'active']);
        $this->branchB = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Beşiktaş Şubesi', 'status' => 'active']);
        $this->warehouseA = Warehouse::create(['branch_id' => $this->branchA->id, 'name' => 'Kadıköy Deposu', 'is_default' => true, 'status' => 'active']);
        $this->warehouseB = Warehouse::create(['branch_id' => $this->branchB->id, 'name' => 'Beşiktaş Deposu', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($this->admin);
        $this->product = Product::create(['name' => 'Lateks Eldiven', 'base_unit' => 'Adet', 'min_stock' => 0, 'status' => 'active']);

        $service = app(StockMovementService::class);
        $service->in($this->product, $this->warehouseA, 50, ['lot_no' => 'LOT-KADIKOY', 'unit_cost' => 2], $this->admin);
        $this->movementB = $service->in($this->product, $this->warehouseB, 70, ['lot_no' => 'LOT-BESIKTAS', 'unit_cost' => 2], $this->admin);
        $service->out($this->product, $this->warehouseA, 10, null, $this->admin, 'Klinik içi kullanım', StockOutReason::ClinicalUse);
        $service->out($this->product, $this->warehouseB, 30, null, $this->admin, 'Klinik içi kullanım', StockOutReason::ClinicalUse);
    }

    /**
     * @param  array<int, Module>  $modules
     * @param  array<int, int>  $selectedBranchIds
     */
    private function staff(
        PermissionScope $scope,
        ?Branch $homeBranch = null,
        array $selectedBranchIds = [],
        array $modules = [Module::StockMovement, Module::Reports],
    ): User {
        $staff = User::factory()->create([
            'organization_id' => $this->organization->id,
            'branch_id' => ($homeBranch ?? $this->branchA)->id,
            'role' => User::ROLE_STAFF,
            'status' => 'active',
        ]);

        foreach ($modules as $module) {
            $permission = Permission::create([
                'user_id' => $staff->id,
                'module' => $module->value,
                'can_read' => true,
                'can_write' => true,
                'can_delete' => false,
                'scope' => $scope->value,
            ]);
            $permission->branches()->sync($selectedBranchIds);
        }

        return $staff->fresh();
    }

    public function test_accessible_branches_follow_role_and_permission_scope(): void
    {
        $this->assertNull($this->admin->accessibleBranchIds(Module::StockMovement));
        $this->assertNull($this->staff(PermissionScope::All)->accessibleBranchIds(Module::StockMovement));
        $this->assertSame([$this->branchA->id], $this->staff(PermissionScope::OwnBranch)->accessibleBranchIds(Module::StockMovement));
        $this->assertSame(
            [$this->branchB->id],
            $this->staff(PermissionScope::SelectedBranches, selectedBranchIds: [$this->branchB->id])->accessibleBranchIds(Module::StockMovement),
        );
        $this->assertSame([], $this->staff(PermissionScope::All, modules: [Module::Reports])->accessibleBranchIds(Module::StockMovement));
    }

    public function test_stock_screens_only_list_data_from_accessible_branches(): void
    {
        $this->actingAs($this->staff(PermissionScope::OwnBranch));

        foreach (['/stok-durumu', '/stok-girisleri', '/stok-cikislari', '/stok-hareketleri'] as $screen) {
            $this->get($screen)
                ->assertOk()
                ->assertSee('LOT-KADIKOY')
                ->assertDontSee('LOT-BESIKTAS')
                ->assertDontSee('Beşiktaş Deposu');
        }
    }

    public function test_selected_branches_scope_sees_only_the_selected_branches(): void
    {
        $this->actingAs($this->staff(PermissionScope::SelectedBranches, selectedBranchIds: [$this->branchB->id]));

        $this->get('/stok-durumu')->assertOk()->assertSee('LOT-BESIKTAS')->assertDontSee('LOT-KADIKOY');
    }

    public function test_stock_in_to_an_out_of_scope_warehouse_is_rejected(): void
    {
        $this->actingAs($this->staff(PermissionScope::OwnBranch));
        $before = StockMovement::count();

        Livewire::test('pages::stock.in')
            ->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouseB->id)
            ->set('quantity', '5')
            ->call('save')
            ->assertNotFound();

        $this->assertSame($before, StockMovement::count());
    }

    public function test_stock_out_from_an_out_of_scope_warehouse_is_rejected(): void
    {
        $this->actingAs($this->staff(PermissionScope::OwnBranch));
        $before = StockMovement::count();

        Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $this->product->id)
            ->set('warehouse_id', (string) $this->warehouseB->id)
            ->set('quantity', '5')
            ->set('reasonCategory', 'clinical_use')
            ->call('save')
            ->assertNotFound();

        $this->assertSame($before, StockMovement::count());
    }

    public function test_cancelling_an_out_of_scope_movement_is_rejected(): void
    {
        $this->actingAs($this->staff(PermissionScope::OwnBranch));

        Livewire::test('pages::stock.movements')
            ->call('cancel', $this->movementB->id)
            ->assertNotFound();

        $this->assertSame(0, StockMovement::where('related_entity_id', $this->movementB->id)->count());
    }

    public function test_report_quantities_only_include_accessible_branches(): void
    {
        $this->actingAs($this->staff(PermissionScope::OwnBranch));

        Livewire::test('pages::reports.stock')
            ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('product.id', $this->product->id)['quantity'] === 40.0)
            ->assertDontSee('Beşiktaş Deposu');
    }

    public function test_dashboard_shows_only_accessible_branches_and_each_scope_is_cached_separately(): void
    {
        $this->actingAs($this->admin);
        Livewire::test('pages::dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['totalStockQuantity'] === 80.0
                && $summary['monthlyUsage'] === 40.0
                && $summary['branchCount'] === 2);

        $this->actingAs($this->staff(PermissionScope::OwnBranch));
        Livewire::test('pages::dashboard')
            ->assertViewHas('summary', fn (array $summary) => $summary['totalStockQuantity'] === 40.0
                && $summary['monthlyUsage'] === 10.0
                && $summary['branchCount'] === 1
                && array_column($summary['branchDistribution'], 'name') === ['Kadıköy Şubesi'])
            ->assertDontSee('Beşiktaş Şubesi');
    }

    public function test_a_movement_invalidates_every_cached_scope_of_the_organization(): void
    {
        $staff = $this->staff(PermissionScope::OwnBranch);
        $metrics = app(DashboardMetricsService::class);

        $this->assertSame(80.0, $metrics->summaryFor($this->organization->id)['totalStockQuantity']);
        $this->assertSame(40.0, $metrics->summaryFor($this->organization->id, [$this->branchA->id])['totalStockQuantity']);

        app(StockMovementService::class)->in($this->product, $this->warehouseA, 5, ['lot_no' => 'LOT-KADIKOY'], $staff);

        $this->assertSame(85.0, $metrics->summaryFor($this->organization->id)['totalStockQuantity']);
        $this->assertSame(45.0, $metrics->summaryFor($this->organization->id, [$this->branchA->id])['totalStockQuantity']);
    }

    public function test_stock_alerts_reach_staff_only_when_the_product_is_stocked_in_their_branches(): void
    {
        $kadikoyStaff = $this->staff(PermissionScope::OwnBranch, $this->branchA);
        $besiktasStaff = $this->staff(PermissionScope::OwnBranch, $this->branchB);

        $onlyInBesiktas = Product::create(['name' => 'Kompozit', 'base_unit' => 'Adet', 'min_stock' => 20, 'status' => 'active']);
        app(StockMovementService::class)->in($onlyInBesiktas, $this->warehouseB, 3, ['lot_no' => 'LOT-KMP'], $this->admin);

        app(StockAlertService::class)->scan($this->organization);

        $alerted = fn (User $user) => $user->notifications()
            ->where('type', StockLevelAlert::class)
            ->where('data->product_id', $onlyInBesiktas->id)
            ->exists();

        $this->assertTrue($alerted($this->admin));
        $this->assertTrue($alerted($besiktasStaff));
        $this->assertFalse($alerted($kadikoyStaff));
    }

    public function test_staff_must_be_assigned_to_a_branch_of_the_organization(): void
    {
        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $foreignBranch = Branch::create(['organization_id' => $otherOrganization->id, 'name' => 'Yabancı', 'status' => 'active']);

        $form = fn () => Livewire::test('pages::access.staff')
            ->call('openForm')
            ->set('name', 'Ayşe')
            ->set('email', 'ayse@example.com')
            ->set('password', 'password123')
            ->set('modules.stock_movement.read', true);

        $form()->call('save')->assertHasErrors(['branch_id' => 'required']);
        $form()->set('branch_id', (string) $foreignBranch->id)->call('save')->assertHasErrors('branch_id');

        $form()->set('branch_id', (string) $this->branchB->id)->call('save')->assertHasNoErrors();

        $this->assertSame($this->branchB->id, User::where('email', 'ayse@example.com')->sole()->branch_id);
        $this->get('/personel')->assertSee('Beşiktaş Şubesi');
    }

    public function test_staff_manager_sees_and_assigns_only_staff_in_their_branches(): void
    {
        $this->staff(PermissionScope::OwnBranch, $this->branchB)->update(['name' => 'Beşiktaş Çalışanı']);
        $this->staff(PermissionScope::OwnBranch, $this->branchA)->update(['name' => 'Kadıköy Çalışanı']);

        $manager = $this->staff(PermissionScope::OwnBranch, $this->branchA, modules: [Module::StaffManagement]);
        $this->actingAs($manager);

        $this->get('/personel')->assertOk()->assertSee('Kadıköy Çalışanı')->assertDontSee('Beşiktaş Çalışanı');

        Livewire::test('pages::access.staff')
            ->call('openForm')
            ->set('name', 'Yeni')
            ->set('email', 'yeni@example.com')
            ->set('password', 'password123')
            ->set('branch_id', (string) $this->branchB->id)
            ->call('save')
            ->assertHasErrors('branch_id');
    }
}
