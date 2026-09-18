<?php

namespace Tests\Feature\Audit;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Services\StaffService;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Support\AuditAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditableTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    public function test_editing_a_product_logs_before_and_after_values_together(): void
    {
        $this->actingAs($this->admin);

        $product = Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'purchase_price' => 25.50, 'min_stock' => 10, 'status' => 'active']);

        app(ProductService::class)->update($product, ['name' => 'Kompozit A2', 'purchase_price' => 30]);

        $log = AuditLog::where('action', AuditAction::Updated->value)->sole();

        $this->assertSame(Product::class, $log->entity_type);
        $this->assertSame($product->id, $log->entity_id);
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame($this->organization->id, $log->organization_id);
        $this->assertSame(['name' => 'Kompozit A', 'purchase_price' => '25.50'], $log->before);
        $this->assertSame(['name' => 'Kompozit A2', 'purchase_price' => '30.00'], $log->after);
    }

    public function test_creating_a_product_logs_its_initial_values(): void
    {
        $this->actingAs($this->admin);

        $product = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);

        $log = AuditLog::where('action', AuditAction::Created->value)
            ->where('entity_type', Product::class)
            ->sole();

        $this->assertSame($product->id, $log->entity_id);
        $this->assertNull($log->before);
        $this->assertSame('Eldiven', $log->after['name']);
        $this->assertArrayNotHasKey('created_at', $log->after);
        $this->assertArrayNotHasKey('updated_at', $log->after);
    }

    public function test_deleting_a_model_logs_its_last_values(): void
    {
        $this->actingAs($this->admin);

        $product = Product::create(['name' => 'Silinecek', 'base_unit' => 'Adet', 'status' => 'active']);
        $product->delete();

        $log = AuditLog::where('action', AuditAction::Deleted->value)->sole();

        $this->assertSame('Silinecek', $log->before['name']);
        $this->assertNull($log->after);
    }

    public function test_saving_without_real_changes_writes_no_update_log(): void
    {
        $this->actingAs($this->admin);

        $product = Product::create(['name' => 'Ürün', 'base_unit' => 'Adet', 'status' => 'active']);
        $product->touch();
        $product->update(['name' => 'Ürün']);

        $this->assertSame(0, AuditLog::where('action', AuditAction::Updated->value)->count());
    }

    public function test_hidden_attributes_like_password_are_never_logged(): void
    {
        $this->actingAs($this->admin);

        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        $staff->update(['password' => 'yeni-sifre-123', 'remember_token' => 'abc']);

        $created = AuditLog::where('entity_type', User::class)->where('entity_id', $staff->id)->sole();

        $this->assertSame(AuditAction::Created, $created->action);
        $this->assertArrayNotHasKey('password', $created->after);
        $this->assertArrayNotHasKey('remember_token', $created->after);
    }

    public function test_granting_permissions_to_staff_is_logged_under_the_staff_organization(): void
    {
        $this->actingAs($this->admin);

        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);

        $staff = app(StaffService::class)->createStaff(
            ['name' => 'Ayşe', 'email' => 'ayse@example.com', 'password' => 'password', 'branch_id' => $branch->id],
            ['reports' => ['read' => true, 'write' => false, 'delete' => false]],
            PermissionScope::OwnBranch,
        );

        $permission = Permission::where('user_id', $staff->id)->sole();
        $log = AuditLog::where('entity_type', Permission::class)->sole();

        $this->assertSame($permission->id, $log->entity_id);
        $this->assertSame($this->organization->id, $log->organization_id);
        $this->assertSame('reports', $log->after['module']);
        $this->assertTrue($log->after['can_read']);
    }

    public function test_warehouse_logs_inherit_organization_from_branch_without_a_logged_in_user(): void
    {
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);

        $log = AuditLog::withoutGlobalScopes()->where('entity_type', Warehouse::class)->sole();

        $this->assertSame($this->organization->id, $log->organization_id);
        $this->assertNull($log->actor_id);
    }

    public function test_audit_logs_cannot_be_modified(): void
    {
        $this->actingAs($this->admin);

        Product::create(['name' => 'Ürün', 'base_unit' => 'Adet', 'status' => 'active']);
        $log = AuditLog::firstOrFail();

        $this->expectException(LogicException::class);

        $log->update(['action' => AuditAction::Deleted]);
    }

    public function test_audit_logs_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin);

        Product::create(['name' => 'Ürün', 'base_unit' => 'Adet', 'status' => 'active']);
        $log = AuditLog::firstOrFail();

        $this->expectException(LogicException::class);

        $log->delete();
    }

    public function test_audit_logs_are_isolated_per_organization(): void
    {
        $otherOrganization = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherAdmin = User::factory()->create(['organization_id' => $otherOrganization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($otherAdmin);
        Product::create(['name' => 'Diğer Ürün', 'base_unit' => 'Adet', 'status' => 'active']);

        $this->actingAs($this->admin);
        Product::create(['name' => 'Bizim Ürün', 'base_unit' => 'Adet', 'status' => 'active']);

        $visible = AuditLog::where('entity_type', Product::class)->get();

        $this->assertCount(1, $visible);
        $this->assertSame('Bizim Ürün', $visible->first()->after['name']);
    }
}
