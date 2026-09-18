<?php

namespace Tests\Feature\Platform;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Platform\Services\PlanLimitService;
use App\Domain\Platform\Support\Plan;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 3 / Aşama 17 — paket limitleri (proje.md Bölüm 12). Doğrulama: limit
 * dolduğunda yeni şube, personel veya belge eklenemez ve sebep ekranda net
 * görünür; mevcut kayıtlar hiçbir zaman silinmez veya pasife alınmaz.
 */
class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Varsayılan Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function createBranch(string $name)
    {
        return Livewire::test('pages::organization.branches')->call('create')->set('name', $name)->call('save');
    }

    private function createStaff(int $index)
    {
        return Livewire::test('pages::access.staff')->call('openForm')
            ->set('name', "Personel {$index}")->set('email', "personel{$index}@klinik.test")->set('password', 'guclu-sifre-1')
            ->set('branch_id', (string) $this->branch->id)->set('modules.stock_movement.read', true)
            ->call('save');
    }

    public function test_starter_plan_allows_one_active_branch_and_the_reason_is_shown(): void
    {
        $this->createBranch('Kadıköy')
            ->assertHasErrors('name')
            ->assertSee('Paket limitine ulaşıldı: 1/1 aktif şube (Başlangıç paketi)');
        $this->assertSame(1, Branch::count());

        // Platform Sahibi organizasyona özel limit girebilir (paketin varsayılanını ezer).
        $this->organization->update(['max_branches' => 2]);
        $this->createBranch('Kadıköy')->assertHasNoErrors();
        $this->assertSame(2, Branch::count());
        $this->createBranch('Beşiktaş')->assertHasErrors('name');
    }

    public function test_a_passive_branch_is_not_counted_but_reactivating_it_needs_room(): void
    {
        $this->organization->update(['plan' => Plan::Professional->value]);
        $this->createBranch('Kadıköy')->assertHasNoErrors();
        $kadikoy = Branch::where('name', 'Kadıköy')->sole();
        Livewire::test('pages::organization.branches')->call('deactivate', $kadikoy->id);
        $this->assertSame('passive', $kadikoy->fresh()->status);

        // Başlangıç'a düşülür: aktif 1 şube, limit dolu; pasif şube aktifleştirilemez.
        $this->organization->update(['plan' => Plan::Starter->value]);
        Livewire::test('pages::organization.branches')->call('activate', $kadikoy->id)
            ->assertSee('Paket limitine ulaşıldı: 1/1 aktif şube');
        $this->assertSame('passive', $kadikoy->fresh()->status);
    }

    public function test_starter_plan_allows_five_active_users_including_the_admin(): void
    {
        foreach (range(1, 4) as $index) {
            $this->createStaff($index)->assertHasNoErrors();
        }

        $this->createStaff(5)->assertHasErrors('name')->assertSee('Paket limitine ulaşıldı: 5/5 aktif kullanıcı');
        $this->assertSame(5, User::where('organization_id', $this->organization->id)->count());

        // Pasif kullanıcı sayılmaz.
        User::where('email', 'personel1@klinik.test')->update(['status' => 'passive']);
        $this->createStaff(5)->assertHasNoErrors();
    }

    public function test_storage_limit_blocks_an_upload_before_the_file_is_written(): void
    {
        Storage::fake('local');
        $this->organization->update(['max_storage_mb' => 1]);
        $supplier = Supplier::create(['name' => 'Tedarikçi', 'status' => 'active']);
        $product = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($supplier, Warehouse::sole(), [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 1]], null, null, $this->admin);
        $orders->submit($order, $this->admin);
        $orders->approve($order, $this->admin);
        $orders->markOrdered($order, $this->admin);
        $line = $order->lines()->sole();

        $receive = fn (string $file, int $quantity) => Livewire::test('pages::purchasing.index')->call('openReceipt', $order->id)
            ->set("receiptLines.{$line->id}.quantity", (string) $quantity)
            ->set('document', UploadedFile::fake()->create($file, 600, 'application/pdf'))
            ->call('receive');

        $receive('irsaliye-1.pdf', 4)->assertHasNoErrors();
        $this->assertSame(600 * 1024, (int) PurchaseReceipt::sole()->document_size);
        $this->assertSame(0.59, app(PlanLimitService::class)->usage($this->organization)['storage_mb']);

        $receive('irsaliye-2.pdf', 4)->assertHasErrors('document')->assertSee('Depolama limiti aşılıyor');
        $this->assertSame(1, PurchaseReceipt::count(), 'teslim alınmadı');
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'dosya diske yazılmadı');
        $this->assertSame(4.0, (float) $line->fresh()->received_quantity);
    }

    public function test_enterprise_plan_is_unlimited(): void
    {
        $this->organization->update(['plan' => Plan::Enterprise->value]);

        foreach (range(1, 6) as $index) {
            $this->createBranch("Şube {$index}")->assertHasNoErrors();
        }

        $this->assertSame(7, Branch::where('status', 'active')->count());
        $this->assertSame(['branches' => null, 'users' => null, 'storage_mb' => null], $this->organization->fresh()->limits());
    }

    public function test_downgrading_keeps_existing_records_and_reports_the_overage(): void
    {
        $this->organization->update(['plan' => Plan::Professional->value]);
        $this->createBranch('Kadıköy')->assertHasNoErrors();
        $this->createBranch('Beşiktaş')->assertHasNoErrors();

        $limits = app(PlanLimitService::class);
        $this->assertSame(['branches' => ['used' => 3, 'limit' => 1]], $limits->overagesFor($this->organization->fresh(), Plan::Starter));
        $this->assertSame([], $limits->overagesFor($this->organization->fresh(), Plan::Professional));

        $this->organization->update(['plan' => Plan::Starter->value]);

        $this->assertSame(3, Branch::where('status', 'active')->count(), 'mevcut şubeler pasife alınmaz');
        $this->createBranch('Üsküdar')->assertHasErrors('name')->assertSee('3/1 aktif şube');
    }

    public function test_admin_sees_plan_and_usage_and_staff_cannot(): void
    {
        $this->createStaff(1)->assertHasNoErrors();

        $this->get('/paket')->assertOk()
            ->assertSee('Başlangıç')
            ->assertSee('1 / 1')
            ->assertSee('limit dolu')
            ->assertSee('2 / 5')
            ->assertSee('Kurumsal');

        $staff = User::where('email', 'personel1@klinik.test')->sole();
        Permission::where('user_id', $staff->id)->update(['module' => Module::Reports->value]);
        $this->actingAs($staff->fresh());
        $this->get('/paket')->assertForbidden();
    }
}
