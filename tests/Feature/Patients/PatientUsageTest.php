<?php

namespace Tests\Feature\Patients;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Patients\Exceptions\PatientException;
use App\Domain\Patients\Models\Patient;
use App\Domain\Patients\Models\PatientUsage;
use App\Domain\Patients\Services\PatientService;
use App\Domain\Patients\Services\PatientUsageService;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Services\UtsUsageService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Aşama 43 — hastaya kullanım kaydı ve ÜTS Kullanım bildirimi.
 */
class PatientUsageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    private Product $implant;

    private Product $graft;

    private Patient $patient;

    private FakeUtsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);

        $stock = app(StockMovementService::class);
        $this->implant = Product::create(['name' => 'Implant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true, 'gtin' => '08699999999994', 'uts_usage_report' => true]);
        $stock->in($this->implant, $this->warehouse, 2, ['lot_no' => 'IMP-1'], $this->admin, tracking: ['serials' => ['S1', 'S2']]);
        $this->graft = Product::create(['name' => 'Kemik grefti', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '04049999999991', 'uts_usage_report' => true]);
        $stock->in($this->graft, $this->warehouse, 5, ['lot_no' => 'G-7'], $this->admin);

        $this->patient = app(PatientService::class)->create(['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'identity_type' => 'tc', 'identity_no' => '10000000146']);
        UtsConnection::create(['environment' => 'test', 'token' => 't']);
        $this->useClient(new FakeUtsClient);
    }

    private function useClient(FakeUtsClient $client): void
    {
        $this->client = $client;
        $this->app->instance(UtsClient::class, $client);
    }

    private function useImplant(array $serials = ['S1']): Collection
    {
        $movements = app(StockMovementService::class)->out($this->implant, $this->warehouse, count($serials), null, $this->admin, 'Klinik içi kullanım', StockOutReason::ClinicalUse, ['serials' => $serials]);

        return app(PatientUsageService::class)->record($movements, $this->patient, Carbon::parse('2026-10-18'));
    }

    public function test_each_serial_becomes_its_own_usage(): void
    {
        $usages = $this->useImplant(['S1', 'S2']);

        $this->assertCount(2, $usages);
        $this->assertSame(['S1', 'S2'], $usages->map(fn ($u) => $u->serial->serial_no)->sort()->values()->all());
        $this->assertSame(1.0, (float) $usages->first()->quantity);
        $this->assertSame('2026-10-18', $usages->first()->used_on->toDateString());
        $this->assertSame($this->admin->id, $usages->first()->user_id);
    }

    public function test_lot_usage_keeps_the_quantity(): void
    {
        $movements = app(StockMovementService::class)->out($this->graft, $this->warehouse, 2, null, $this->admin, 'Klinik içi kullanım', StockOutReason::ClinicalUse);

        $usage = app(PatientUsageService::class)->record($movements, $this->patient, Carbon::parse('2026-10-18'))->sole();

        $this->assertSame(2.0, (float) $usage->quantity);
        $this->assertNull($usage->stock_serial_id);
        $this->assertSame('G-7', $usage->lot->lot_no);
    }

    public function test_report_sends_serial_usage_with_patient_identity(): void
    {
        $usage = $this->useImplant()->sole();

        $record = app(UtsUsageService::class)->report($usage);

        $this->assertSame('sent', $record->status);
        $this->assertSame('kullanim', $record->type);
        $this->assertSame($usage->id, $record->patient_usage_id);
        $this->assertSame([['reportUsage', [[
            'UNO' => '08699999999994', 'SNO' => 'S1', 'GIT' => '2026-10-18', 'HAA' => 'Ayşe', 'HAS' => 'Yılmaz',
            'TUR' => 'TC_KIMLIK_NUMARASI_VAR', 'TKN' => '10000000146',
        ]]]], $this->client->calls);
        $this->assertSame('sent', $usage->fresh()->utsStatus());
    }

    public function test_notification_record_never_contains_the_identity_number(): void
    {
        app(UtsUsageService::class)->report($this->useImplant()->sole());

        $this->assertStringNotContainsString('10000000146', json_encode(UtsNotification::sole()->getAttributes()));
    }

    public function test_lot_usage_sends_lot_and_quantity(): void
    {
        $movements = app(StockMovementService::class)->out($this->graft, $this->warehouse, 2, null, $this->admin, 'Klinik içi kullanım', StockOutReason::ClinicalUse);
        $usage = app(PatientUsageService::class)->record($movements, $this->patient, Carbon::parse('2026-10-18'))->sole();

        app(UtsUsageService::class)->report($usage);

        $payload = $this->client->calls[0][1][0];
        $this->assertSame('G-7', $payload['LNO']);
        $this->assertSame(2, $payload['ADT']);
        $this->assertArrayNotHasKey('SNO', $payload);
    }

    public function test_failed_report_is_recorded_and_can_be_retried(): void
    {
        $usage = $this->useImplant()->sole();
        $this->useClient(new FakeUtsClient(writeFailure: new UtsException('ÜTS şu an yanıt vermiyor.')));

        try {
            app(UtsUsageService::class)->report($usage);
            $this->fail('Exception bekleniyordu.');
        } catch (UtsException) {
        }

        $this->assertSame('failed', $usage->fresh()->utsStatus());

        $this->useClient(new FakeUtsClient);
        app(UtsUsageService::class)->report($usage->fresh());

        $this->assertSame('sent', $usage->fresh()->utsStatus());
        $this->assertSame(2, UtsNotification::count());
    }

    public function test_a_reported_usage_is_not_reported_again(): void
    {
        $usage = $this->useImplant()->sole();
        app(UtsUsageService::class)->report($usage);

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('zaten bildirildi');

        app(UtsUsageService::class)->report($usage->fresh());
    }

    public function test_cancel_sends_the_reported_code_and_marks_cancelled(): void
    {
        $usage = $this->useImplant()->sole();
        $sent = app(UtsUsageService::class)->report($usage);

        $record = app(UtsUsageService::class)->cancel($usage->fresh());

        $this->assertSame('kullanim_iptal', $record->type);
        $this->assertSame($sent->uts_notification_id, $record->source_notification_id);
        $this->assertSame(['cancelUsage', [$sent->uts_notification_id]], $this->client->calls[1]);
        $this->assertSame('cancelled', $usage->fresh()->utsStatus());
    }

    public function test_only_reported_usage_can_be_cancelled(): void
    {
        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('bildirilmiş bir kullanım değil');

        app(UtsUsageService::class)->cancel($this->useImplant()->sole());
    }

    public function test_unknown_identity_sends_the_record_number(): void
    {
        $unknown = app(PatientService::class)->create(['first_name' => 'Kimliksiz', 'last_name' => 'Hasta', 'identity_type' => 'unknown']);
        $movements = app(StockMovementService::class)->out($this->implant, $this->warehouse, 1, null, $this->admin, 'Klinik içi kullanım', StockOutReason::ClinicalUse, ['serials' => ['S2']]);

        app(UtsUsageService::class)->report(app(PatientUsageService::class)->record($movements, $unknown, Carbon::parse('2026-10-18'))->sole());

        $payload = $this->client->calls[0][1][0];
        $this->assertSame('KIMLIGI_BELIRSIZ', $payload['TUR']);
        $this->assertSame($unknown->id, $payload['KTN']);
    }

    public function test_production_lock_applies_to_usage(): void
    {
        UtsConnection::first()->update(['environment' => 'production']);

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('gerçek ortamda');

        app(UtsUsageService::class)->report($this->useImplant()->sole());
    }

    public function test_patient_with_usage_cannot_be_deleted(): void
    {
        $this->useImplant();

        $this->expectException(PatientException::class);

        app(PatientService::class)->delete($this->patient);
    }

    public function test_patient_without_usage_can_be_deleted(): void
    {
        $other = app(PatientService::class)->create(['first_name' => 'Silinecek', 'last_name' => 'Hasta', 'identity_type' => 'unknown']);

        app(PatientService::class)->delete($other);

        $this->assertNull(Patient::find($other->id));
        $this->assertSame(0, PatientUsage::where('patient_id', $other->id)->count());
    }
}
