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
use App\Domain\Patients\Services\PatientUsageService;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockOutReason;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Services\UtsRemovalService;
use App\Domain\Uts\Services\UtsUsageService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 55 — implant hastadan çıkarılınca ÜTS hastanın vücudundan çıkarma
 * bildirimi (rev. 1.99, 3.1.22).
 */
class PatientRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Patient $patient;

    private PatientUsage $usage;

    private FakeUtsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 10:00');
        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $branch = Branch::create(['organization_id' => $this->org->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);

        $implant = Product::create(['name' => 'Implant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true, 'gtin' => '08699999999994', 'uts_usage_report' => true]);
        $stock = app(StockMovementService::class);
        $stock->in($implant, $warehouse, 1, ['lot_no' => 'IMP-1'], $this->admin, tracking: ['serials' => ['S1']]);
        $movements = $stock->out($implant, $warehouse, 1, null, $this->admin, 'Klinik içi kullanım', StockOutReason::ClinicalUse, ['serials' => ['S1']]);

        $this->patient = app(PatientService::class)->create(['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'identity_type' => 'tc', 'identity_no' => '10000000146']);
        $this->usage = app(PatientUsageService::class)->record($movements, $this->patient, Carbon::parse('2026-09-01'))->sole();

        UtsConnection::create(['environment' => 'test', 'token' => 't']);
        $this->useClient(new FakeUtsClient);
        app(UtsUsageService::class)->report($this->usage);
    }

    private function useClient(FakeUtsClient $client): void
    {
        $this->client = $client;
        $this->app->instance(UtsClient::class, $client);
    }

    private function removals(): array
    {
        return array_values(array_filter($this->client->calls, fn ($c) => $c[0] === 'reportRemoval'));
    }

    private function remove(string $reason = 'REVIZYON', ?string $note = null, string $date = '2026-10-15'): array
    {
        return app(UtsRemovalService::class)->remove($this->usage->fresh(), $reason, $note, Carbon::parse($date));
    }

    public function test_reported_usage_is_removed_and_reported_with_patient_identity(): void
    {
        $this->assertSame([], $this->remove());

        $this->assertSame([['reportRemoval', [[
            'UNO' => '08699999999994', 'SNO' => 'S1', 'GIT' => '2026-10-15', 'HAA' => 'Ayşe', 'HAS' => 'Yılmaz',
            'TUR' => 'TC_KIMLIK_NUMARASI_VAR', 'TKN' => '10000000146', 'GRK' => 'REVIZYON',
        ]]]], $this->removals());

        $usage = $this->usage->fresh();
        $this->assertSame('2026-10-15', $usage->removed_on->toDateString());
        $this->assertSame('REVIZYON', $usage->removal_reason);
        $this->assertSame('sent', UtsNotification::where('type', 'vucuttan_cikarma')->sole()->status);
    }

    public function test_other_reason_needs_a_description_and_is_sent(): void
    {
        try {
            $this->remove('DIGER');
            $this->fail('Exception bekleniyordu.');
        } catch (UtsException $e) {
            $this->assertStringContainsString('açıklama', $e->getMessage());
        }

        $this->remove('DIGER', 'Hasta isteği');
        $this->assertSame('Hasta isteği', $this->removals()[0][1][0]['DGA']);
    }

    public function test_date_must_be_between_usage_and_today(): void
    {
        foreach (['2026-08-31', '2026-10-20'] as $date) {
            try {
                $this->remove(date: $date);
                $this->fail("{$date} kabul edilmemeliydi.");
            } catch (UtsException) {
            }
        }

        $this->assertNull($this->usage->fresh()->removed_on);
        $this->assertSame([], $this->removals());
    }

    public function test_unreported_usage_is_removed_locally_without_uts(): void
    {
        app(UtsUsageService::class)->cancel($this->usage->fresh());

        $this->assertSame([], $this->remove());

        $this->assertSame([], $this->removals());
        $this->assertNotNull($this->usage->fresh()->removed_on);
    }

    public function test_failure_keeps_the_local_removal_and_can_be_retried(): void
    {
        $this->useClient(new FakeUtsClient(writeFailure: new UtsException('ÜTS şu an yanıt vermiyor.')));

        $this->assertSame(['ÜTS şu an yanıt vermiyor.'], $this->remove());
        $this->assertNotNull($this->usage->fresh()->removed_on);
        $failed = UtsNotification::where('type', 'vucuttan_cikarma')->sole();

        $this->useClient(new FakeUtsClient);
        Livewire::test('pages::uts.history')->call('retry', $failed->id)->assertSee('Bildirim ÜTS\'ye gönderildi');

        $this->assertCount(1, $this->removals());
        $this->assertSame('GRK', array_key_last($this->removals()[0][1][0]));
    }

    public function test_cancelling_the_removal_in_uts_undoes_it(): void
    {
        $this->remove();
        $sent = UtsNotification::where('type', 'vucuttan_cikarma')->sole();

        Livewire::test('pages::uts.history')->call('confirmCancel', $sent->id)->call('cancelInUts');

        $this->assertSame(['cancelRemoval', [$sent->uts_notification_id]], end($this->client->calls));
        $this->assertNull($this->usage->fresh()->removed_on);
        $this->assertNull($this->usage->fresh()->removal_reason);
    }

    public function test_usage_cannot_be_cancelled_while_removal_is_active(): void
    {
        $this->remove();

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('çıkarma bildirimini iptal edin');

        app(UtsUsageService::class)->cancel($this->usage->fresh());
    }

    public function test_already_removed_usage_cannot_be_removed_again(): void
    {
        $this->remove();

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('zaten');

        $this->remove();
    }

    public function test_patient_card_removes_after_confirmation(): void
    {
        Livewire::test('pages::patients.show', ['patient' => $this->patient->id])
            ->assertSee('Vücuttan çıkar')
            ->call('openRemoval', $this->usage->id)
            ->assertSet('removalDate', '2026-10-19')
            ->set('removalReason', 'DOKU_UYUMSUZLUGU')
            ->call('remove')
            ->assertHasNoErrors()
            ->assertSee('Çıkarıldı')
            ->assertSee('doku uyumsuzluğu');

        $this->assertSame('DOKU_UYUMSUZLUGU', $this->usage->fresh()->removal_reason);
    }

    public function test_patient_card_action_needs_patient_write(): void
    {
        $reader = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $reader->id, 'module' => Module::Patients->value, 'can_read' => true, 'can_write' => false, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($reader);

        Livewire::test('pages::patients.show', ['patient' => $this->patient->id])
            ->assertDontSee('Vücuttan çıkar')
            ->call('openRemoval', $this->usage->id)
            ->assertForbidden();
    }

    public function test_serial_tracking_shows_the_removal(): void
    {
        $this->remove();

        Livewire::test('pages::stock.serials')->set('search', 'S1')->assertSee('Hastadan çıkarıldı');
    }
}
