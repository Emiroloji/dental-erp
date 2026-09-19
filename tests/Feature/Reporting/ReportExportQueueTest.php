<?php

namespace Tests\Feature\Reporting;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Reporting\Exports\TableExport;
use App\Domain\Reporting\Jobs\GenerateReportExportJob;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Services\ReportExportService;
use App\Domain\Reporting\Support\ReportExportStatus;
use App\Domain\Reporting\Support\ReportType;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Rapor dışa aktarımlarının kuyruğa alınması (mimari.md Bölüm 6).
 */
class ReportExportQueueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $central;

    private User $admin;

    private User $northStaff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Gerçek XLSX üretimi bellek yoğun; içerik testleri AdvancedReportScreenTest'te.
        Excel::fake();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'professional']);
        $this->central = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $north = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Kuzey', 'status' => 'active']);
        $centralWarehouse = Warehouse::create(['branch_id' => $this->central->id, 'name' => 'Merkez Depo', 'is_default' => true, 'status' => 'active']);
        $northWarehouse = Warehouse::create(['branch_id' => $north->id, 'name' => 'Kuzey Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);

        $this->actingAs($this->admin);
        $stock = app(StockMovementService::class);
        $stock->in(Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'status' => 'active']), $centralWarehouse, 10, ['lot_no' => 'M-1'], $this->admin);
        $stock->in(Product::create(['name' => 'Nitril Eldiven', 'base_unit' => 'Adet', 'status' => 'active']), $northWarehouse, 20, ['lot_no' => 'N-1'], $this->admin);

        $this->northStaff = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $north->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $this->northStaff->id, 'module' => Module::Reports->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::OwnBranch->value]);
    }

    public function test_export_button_only_queues_the_job_and_shows_pending_state(): void
    {
        Queue::fake();

        Livewire::test('pages::reports.movements')
            ->call('export', 'xlsx')
            ->assertSee('Rapor hazırlanıyor');

        $export = ReportExport::sole();
        $this->assertSame(ReportExportStatus::Pending, $export->status);
        $this->assertSame($this->admin->id, $export->user_id);
        Queue::assertPushed(GenerateReportExportJob::class, fn ($job) => $job->exportId === $export->id);

        Livewire::test('report-exports')->assertSee('Hazırlanıyor')->assertDontSee('İndir');
    }

    public function test_job_runs_as_the_requesting_user_with_their_branch_scope(): void
    {
        Queue::fake();

        $this->actingAs($this->northStaff);
        Livewire::test('pages::reports.movements')->call('export', 'xlsx');
        $export = ReportExport::sole();

        // Kuyruk işçisinde oturum yoktur; iş kullanıcı adına çalışır ve sonra oturumu bırakır.
        Auth::forgetUser();
        (new GenerateReportExportJob($export->id))->handle(app(ReportExportService::class));

        $this->assertNull(Auth::user());
        // Yalnızca kendi şubesinin (Kuzey) hareketi + toplam satırı; Merkez'in ürünü yok.
        Excel::assertExportedInRaw(TableExport::class, fn (TableExport $table) => $table->collection()->count() === 2
            && $table->collection()->first()[2] === 'Nitril Eldiven'
            && $table->collection()->last()[0] === 'Net değişim');
        $this->assertSame(ReportExportStatus::Completed, $export->fresh()->status);
    }

    public function test_completed_export_notifies_and_only_the_requester_can_download(): void
    {
        Livewire::test('pages::reports.usage')->call('export', 'pdf');
        $export = ReportExport::sole();

        $this->assertSame(ReportExportStatus::Completed, $export->status);
        $notification = $this->admin->notifications()->sole();
        $this->assertSame('Rapor hazır', $notification->data['title']);
        $this->assertSame(route('reports.exports.download', $export), $notification->data['url']);

        $this->get(route('reports.exports.download', $export))->assertOk()->assertDownload($export->file_name);
        Livewire::test('report-exports')->assertSee('İndir');

        $colleague = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($colleague)->get(route('reports.exports.download', $export))->assertNotFound();

        $foreign = User::factory()->create(['organization_id' => Organization::create(['name' => 'Rakip', 'status' => 'active', 'plan' => 'starter'])->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($foreign)->get(route('reports.exports.download', $export))->assertNotFound();
    }

    public function test_export_fails_if_permission_is_revoked_before_the_job_runs(): void
    {
        Queue::fake();

        $this->actingAs($this->northStaff);
        Livewire::test('pages::reports.usage')->call('export', 'xlsx');
        $export = ReportExport::sole();

        Permission::where('user_id', $this->northStaff->id)->delete();

        Auth::forgetUser();
        (new GenerateReportExportJob($export->id))->handle(app(ReportExportService::class));

        $export->refresh();
        $this->assertSame(ReportExportStatus::Failed, $export->status);
        $this->assertNull($export->file_path);
        $this->assertSame('Rapor hazırlanamadı', $this->northStaff->notifications()->sole()->data['title']);
    }

    public function test_read_only_organization_can_still_export_reports(): void
    {
        $this->organization->update(['status' => 'read_only']);

        Livewire::test('pages::reports.stock')->call('export', 'xlsx')->assertHasNoErrors();

        $this->assertSame(ReportExportStatus::Completed, ReportExport::sole()->status);
    }

    public function test_unsupported_format_creates_nothing(): void
    {
        Livewire::test('pages::reports.stock')->call('export', 'csv');

        $this->assertSame(0, ReportExport::count());
    }

    public function test_old_exports_and_their_files_are_pruned(): void
    {
        $export = app(ReportExportService::class)->request($this->admin, ReportType::Usage, 'xlsx', []);
        $path = $export->fresh()->file_path;
        Storage::disk('local')->assertExists($path);

        $this->travel(ReportExport::RETENTION_DAYS + 1)->days();
        $this->artisan('model:prune', ['--model' => [ReportExport::class]]);

        $this->assertSame(0, ReportExport::withoutGlobalScopes()->count());
        Storage::disk('local')->assertMissing($path);
    }
}
