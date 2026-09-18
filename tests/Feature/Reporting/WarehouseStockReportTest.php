<?php

namespace Tests\Feature\Reporting;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WarehouseStockReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $kadikoy;

    private Branch $besiktas;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->kadikoy = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kadıköy Şubesi', 'status' => 'active']);
        $this->besiktas = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Beşiktaş Şubesi', 'status' => 'active']);
        $kadikoyDepot = Warehouse::create(['branch_id' => $this->kadikoy->id, 'name' => 'Kadıköy Deposu', 'is_default' => true, 'status' => 'active']);
        $besiktasDepot = Warehouse::create(['branch_id' => $this->besiktas->id, 'name' => 'Beşiktaş Deposu', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($this->admin);
        $product = Product::create(['name' => 'Kompozit', 'base_unit' => 'Adet', 'status' => 'active']);
        Product::create(['name' => 'Stoksuz Ürün', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($product, $kadikoyDepot, 10, ['lot_no' => 'LOT-KDK'], $this->admin);
        app(StockMovementService::class)->in($product, $besiktasDepot, 70, ['lot_no' => 'LOT-BJK'], $this->admin);
    }

    private function staff(array $modules, Branch $branch): User
    {
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        foreach ($modules as $module) {
            Permission::create(['user_id' => $staff->id, 'module' => $module->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);
        }

        return $staff->fresh();
    }

    public function test_admin_sees_every_branch_summary_and_only_products_stocked_in_the_selected_branch(): void
    {
        Livewire::test('pages::reports.warehouse-stock')
            ->assertViewHas('branchSummaries', fn ($summaries) => $summaries->pluck('quantity', 'name')->all() === ['Beşiktaş Şubesi' => 70.0, 'Kadıköy Şubesi' => 10.0])
            ->set('branchId', (string) $this->kadikoy->id)
            ->assertSee('Kadıköy Deposu')
            ->assertDontSee('Beşiktaş Deposu')
            ->assertDontSee('Stoksuz Ürün')
            ->assertViewHas('report', fn (array $report) => $report['branchTotal'] === 10.0);
    }

    public function test_staff_only_sees_branches_in_scope_and_cannot_switch_to_another(): void
    {
        $this->actingAs($this->staff([Module::Reports], $this->kadikoy));

        $this->get('/depo-stoklari')->assertOk()->assertSee('Kadıköy Deposu')->assertDontSee('Beşiktaş');

        Livewire::test('pages::reports.warehouse-stock')
            ->set('branchId', (string) $this->besiktas->id)
            ->assertSet('branchId', (string) $this->kadikoy->id)
            ->assertDontSee('Beşiktaş Deposu');
    }

    public function test_stock_readers_can_open_the_report_but_others_cannot(): void
    {
        $this->actingAs($this->staff([Module::StockMovement], $this->kadikoy))->get('/depo-stoklari')->assertOk();
        $this->actingAs($this->staff([Module::ProductManagement], $this->kadikoy))->get('/depo-stoklari')->assertForbidden();
    }

    public function test_search_narrows_products(): void
    {
        Livewire::test('pages::reports.warehouse-stock')
            ->set('branchId', (string) $this->besiktas->id)
            ->set('search', 'yok-boyle-urun')
            ->assertViewHas('report', fn (array $report) => $report['rows'] === [] && $report['branchTotal'] === 70.0);
    }
}
