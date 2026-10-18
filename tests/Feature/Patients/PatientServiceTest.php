<?php

namespace Tests\Feature\Patients;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Models\Organization;
use App\Domain\Patients\Exceptions\PatientException;
use App\Domain\Patients\Models\Patient;
use App\Domain\Patients\Services\PatientService;
use App\Domain\Patients\Support\IdentityType;
use App\Domain\Patients\Support\PatientIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatientServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Kontrol haneleri geçerli bir T.C. kimlik numarası. */
    private const TC = '10000000146';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
    }

    private function service(): PatientService
    {
        return app(PatientService::class);
    }

    private function create(array $overrides = []): Patient
    {
        return $this->service()->create($overrides + ['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'identity_type' => 'tc', 'identity_no' => self::TC, 'birth_year' => 1985]);
    }

    public function test_tc_number_checksum_is_validated(): void
    {
        $this->assertTrue(PatientIdentity::isValidTc(self::TC));
        $this->assertFalse(PatientIdentity::isValidTc('10000000147'));
        $this->assertFalse(PatientIdentity::isValidTc('00000000146'));
        $this->assertFalse(PatientIdentity::isValidTc('1234'));
    }

    public function test_identity_number_is_stored_encrypted_and_hidden(): void
    {
        $patient = $this->create();

        $row = (array) DB::table('patients')->first();
        $this->assertStringNotContainsString(self::TC, $row['identity_no']);
        $this->assertStringNotContainsString(self::TC, $row['identity_hash']);
        $this->assertSame(self::TC, $patient->fresh()->identity_no);
        $this->assertArrayNotHasKey('identity_no', $patient->toArray());
        $this->assertArrayNotHasKey('identity_hash', $patient->toArray());
    }

    public function test_audit_log_never_contains_the_identity_number(): void
    {
        $patient = $this->create();
        $this->service()->update($patient, ['first_name' => 'Ayşe', 'last_name' => 'Demir', 'identity_type' => 'tc', 'identity_no' => '10000000078', 'birth_year' => 1985]);

        $logs = AuditLog::where('entity_type', $patient->getMorphClass())->get();
        $this->assertCount(2, $logs);
        $this->assertStringNotContainsString(self::TC, json_encode($logs->toArray()));
        $this->assertStringNotContainsString('10000000078', json_encode($logs->toArray()));
    }

    public function test_masked_identity(): void
    {
        $this->assertSame('100******46', $this->create()->maskedIdentity());
        $this->assertSame('Kimliği belirsiz', $this->create(['identity_type' => 'unknown', 'identity_no' => null])->maskedIdentity());
    }

    public function test_invalid_tc_is_rejected(): void
    {
        $this->expectException(PatientException::class);
        $this->expectExceptionMessage('T.C. kimlik numarası geçersiz');

        $this->create(['identity_no' => '12345678901']);
    }

    public function test_same_identity_cannot_be_registered_twice_in_an_organization(): void
    {
        $this->create();

        $this->expectException(PatientException::class);
        $this->expectExceptionMessage('zaten var');

        $this->create(['first_name' => 'Başka']);
    }

    public function test_unknown_identity_needs_no_number_and_may_repeat(): void
    {
        $this->create(['identity_type' => 'unknown', 'identity_no' => null]);
        $patient = $this->create(['identity_type' => 'unknown', 'identity_no' => null]);

        $this->assertSame(IdentityType::Unknown, $patient->identity_type);
        $this->assertNull($patient->identity_no);
    }

    public function test_passport_and_foreign_identity(): void
    {
        $this->assertSame(IdentityType::Passport, $this->create(['identity_type' => 'passport', 'identity_no' => 'u1234567'])->identity_type);
        $this->assertSame('U1234567', Patient::latest('id')->first()->identity_no);

        $this->expectException(PatientException::class);
        $this->create(['identity_type' => 'foreign', 'identity_no' => '12345']);
    }

    public function test_search_by_name_or_exact_identity_number(): void
    {
        $this->create();
        $this->create(['first_name' => 'Mehmet', 'last_name' => 'Kaya', 'identity_no' => '10000000078']);

        $this->assertSame(['Mehmet'], Patient::search('kaya')->pluck('first_name')->all());
        $this->assertSame(['Ayşe'], Patient::search(self::TC)->pluck('first_name')->all());
        $this->assertSame([], Patient::search('1000000')->pluck('first_name')->all());
    }

    public function test_patients_are_isolated_per_organization(): void
    {
        $this->create();

        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->assertSame(0, Patient::count());
        $this->assertSame([], Patient::search(self::TC)->get()->all());
        $this->assertSame('Ayşe', $this->create()->first_name);
    }
}
