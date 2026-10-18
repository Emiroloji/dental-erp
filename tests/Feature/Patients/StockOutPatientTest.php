<?php

namespace Tests\Feature\Patients;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Patients\Models\Patient;
use App\Domain\Patients\Models\PatientUsage;
use App\Domain\Patients\Services\PatientService;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Services\UtsUsageService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 43 — Stok Çıkışı'nda hasta seçimi, kullanım kaydı ve ÜTS bildirimi;
 * Hızlı İşlem engeli ve hareket iptali kuralı.
 */
class StockOutPatientTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Warehouse $warehouse;

    private Product $implant;

    private Product $gloves;

    private Patient $patient;

    private FakeUtsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $branch = Branch::create(['organization_id' => $this->org->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);

        $stock = app(StockMovementService::class);
        $this->implant = Product::create(['name' => 'Implant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true, 'gtin' => '08699999999994', 'uts_usage_report' => true]);
        $stock->in($this->implant, $this->warehouse, 2, ['lot_no' => 'IMP-1'], $this->admin, tracking: ['serials' => ['S1', 'S2']]);
        $this->gloves = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        $stock->in($this->gloves, $this->warehouse, 100, ['lot_no' => 'E-1'], $this->admin);

        $this->patient = app(PatientService::class)->create(['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'identity_type' => 'tc', 'identity_no' => '10000000146']);
        UtsConnection::create(['environment' => 'test', 'token' => 't']);
        $this->useClient(new FakeUtsClient);
    }

    private function useClient(FakeUtsClient $client): void
    {
        $this->client = $client;
        $this->app->instance(UtsClient::class, $client);
    }

    private function implantOut(): Testable
    {
        return Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $this->implant->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('selectedSerials', ['S1'])
            ->set('reasonCategory', 'clinical_use');
    }

    public function test_patient_section_appears_only_for_flagged_product_in_clinical_use(): void
    {
        $this->implantOut()->assertSee('Hasta')->assertSee('Kullanım tarihi');

        Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $this->gloves->id)
            ->set('reasonCategory', 'clinical_use')
            ->assertDontSee('Kullanım tarihi');

        $this->implantOut()->set('reasonCategory', 'damaged')->assertDontSee('Kullanım tarihi');
    }

    public function test_patient_is_required(): void
    {
        $this->implantOut()->call('save')->assertHasErrors(['patient_id']);

        $this->assertSame(0, PatientUsage::count());
        $this->assertSame(0, StockMovement::where('type', 'out')->count());
    }

    public function test_search_and_select_patient(): void
    {
        $this->implantOut()
            ->set('patientSearch', 'yılm')
            ->assertSee('Ayşe Yılmaz')
            ->assertSee('100******46')
            ->call('selectPatient', $this->patient->id)
            ->assertSet('patient_id', (string) $this->patient->id);
    }

    public function test_save_records_usage_and_reports_to_uts(): void
    {
        $this->implantOut()
            ->call('selectPatient', $this->patient->id)
            ->set('used_on', '2026-10-17')
            ->call('save')
            ->assertHasNoErrors();

        $usage = PatientUsage::sole();
        $this->assertSame($this->patient->id, $usage->patient_id);
        $this->assertSame('2026-10-17', $usage->used_on->toDateString());
        $this->assertSame('S1', $usage->serial->serial_no);
        $this->assertSame('sent', $usage->utsStatus());
        $this->assertSame('reportUsage', $this->client->calls[0][0]);
    }

    public function test_uts_failure_keeps_the_stock_out_and_warns(): void
    {
        $this->useClient(new FakeUtsClient(writeFailure: new UtsException('ÜTS şu an yanıt vermiyor.')));

        $this->implantOut()
            ->call('selectPatient', $this->patient->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('ÜTS kullanım bildirimi gönderilemedi: ÜTS şu an yanıt vermiyor.');

        $this->assertSame(1, StockMovement::where('type', 'out')->count());
        $this->assertSame('failed', PatientUsage::sole()->utsStatus());
    }

    public function test_future_usage_date_is_rejected(): void
    {
        $this->implantOut()
            ->call('selectPatient', $this->patient->id)
            ->set('used_on', now()->addDay()->toDateString())
            ->call('save')
            ->assertHasErrors(['used_on']);
    }

    public function test_new_patient_can_be_added_from_the_form(): void
    {
        $this->implantOut()
            ->call('startNewPatient')
            ->set('first_name', 'Mehmet')
            ->set('last_name', 'Kaya')
            ->set('identity_type', 'tc')
            ->set('identity_no', '10000000078')
            ->call('addPatient')
            ->assertHasNoErrors()
            ->assertSet('patient_id', (string) Patient::where('first_name', 'Mehmet')->value('id'));
    }

    public function test_staff_without_patient_read_cannot_do_reportable_clinical_use(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        $this->implantOut()
            ->assertSee('hasta görme yetkiniz yok')
            ->call('save')
            ->assertHasErrors(['patient_id']);

        $this->assertSame(0, StockMovement::where('type', 'out')->count());
    }

    public function test_quick_screen_refuses_flagged_clinical_use(): void
    {
        Livewire::test('pages::stock.quick')
            ->set('mode', 'out')
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('productId', $this->implant->id)
            ->set('unit', 'Adet')
            ->set('serialsText', 'S1')
            ->set('quantity', '1')
            ->set('reasonCategory', 'clinical_use')
            ->call('submit')
            ->assertSee('Stok Çıkışı ekranını kullanın');

        $this->assertSame(0, StockMovement::where('type', 'out')->count());
    }

    public function test_movement_with_reported_usage_cannot_be_cancelled_until_uts_cancel(): void
    {
        $this->implantOut()->call('selectPatient', $this->patient->id)->call('save');
        $movement = StockMovement::where('type', 'out')->sole();

        Livewire::test('pages::stock.movements')->call('cancel', $movement->id)->assertSee('Önce ÜTS kullanım bildirimini iptal edin');
        $this->assertSame(0, StockMovement::where('type', 'cancel')->count());

        app(UtsUsageService::class)->cancel(PatientUsage::sole());

        Livewire::test('pages::stock.movements')->call('cancel', $movement->id);
        $this->assertSame(1, StockMovement::where('type', 'cancel')->count());
        $this->assertNotNull(PatientUsage::sole()->cancelled_at);
    }

    public function test_movement_with_unreported_usage_cancels_the_usage(): void
    {
        $this->useClient(new FakeUtsClient(writeFailure: new UtsException('ÜTS şu an yanıt vermiyor.')));
        $this->implantOut()->call('selectPatient', $this->patient->id)->call('save');

        Livewire::test('pages::stock.movements')->call('cancel', StockMovement::where('type', 'out')->sole()->id);

        $this->assertNotNull(PatientUsage::sole()->cancelled_at);
        $this->assertSame(1, UtsNotification::count());
    }

    public function test_patient_detail_lists_used_products_with_uts_status(): void
    {
        $this->implantOut()->call('selectPatient', $this->patient->id)->call('save');

        $this->get("/hastalar/{$this->patient->id}")
            ->assertOk()
            ->assertSee('Implant 4.1')
            ->assertSee('S1')
            ->assertSee('ÜTS\'ye bildirildi');
    }

    public function test_history_retries_a_failed_usage(): void
    {
        $this->useClient(new FakeUtsClient(writeFailure: new UtsException('ÜTS şu an yanıt vermiyor.')));
        $this->implantOut()->call('selectPatient', $this->patient->id)->call('save');
        $failed = UtsNotification::sole();

        $this->useClient(new FakeUtsClient);
        Livewire::test('pages::uts.history')
            ->assertSee('Kullanım')
            ->assertSee('Ayşe Yılmaz')
            ->call('retry', $failed->id)
            ->assertSee('ÜTS\'ye bildirildi');

        $this->assertSame('sent', PatientUsage::sole()->utsStatus());
    }

    public function test_history_cancels_a_reported_usage_after_confirmation(): void
    {
        $this->implantOut()->call('selectPatient', $this->patient->id)->call('save');
        $sent = UtsNotification::sole();

        Livewire::test('pages::uts.history')
            ->call('confirmCancel', $sent->id)
            ->assertSee('ÜTS\'deki kullanım bildirimi iptal edilecek', false)
            ->call('cancelInUts')
            ->assertSee('Kullanım iptali');

        $this->assertSame('cancelled', PatientUsage::sole()->utsStatus());
        $this->assertSame(['cancelUsage', [$sent->uts_notification_id]], $this->client->calls[1]);
    }

    public function test_history_actions_need_stock_write(): void
    {
        $this->implantOut()->call('selectPatient', $this->patient->id)->call('save');
        $staff = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        Livewire::test('pages::uts.history')
            ->assertDontSee('ÜTS\'de iptal et', false)
            ->assertDontSee('Ayşe Yılmaz')
            ->call('confirmCancel', UtsNotification::sole()->id)
            ->assertForbidden();
    }

    public function test_serial_tracking_shows_the_patient_to_patient_readers_only(): void
    {
        $this->implantOut()->call('selectPatient', $this->patient->id)->call('save');

        Livewire::test('pages::stock.serials')
            ->set('search', 'S1')
            ->assertSee('Hastaya kullanıldı')
            ->assertSee('Ayşe Yılmaz')
            ->assertSee('100******46')
            ->assertSee(route('patients.show', $this->patient->id), false);

        $staff = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        Livewire::test('pages::stock.serials')
            ->set('search', 'S1')
            ->assertSee('Hastaya kullanıldı')
            ->assertDontSee('Ayşe Yılmaz');
    }

    public function test_fractional_quantity_is_refused_before_the_stock_out(): void
    {
        $graft = Product::create(['name' => 'Kemik grefti', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '04049999999991', 'uts_usage_report' => true]);
        app(StockMovementService::class)->in($graft, $this->warehouse, 5, ['lot_no' => 'G-7'], $this->admin);

        Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $graft->id)
            ->set('warehouse_id', (string) $this->warehouse->id)
            ->set('quantity', '1.5')
            ->set('reasonCategory', 'clinical_use')
            ->call('selectPatient', $this->patient->id)
            ->call('save')
            ->assertHasErrors(['quantity']);

        $this->assertSame(0, StockMovement::where('type', 'out')->count());
        $this->assertSame(0, PatientUsage::count());
    }
}
