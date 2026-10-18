<?php

namespace Tests\Feature\Patients;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\Organization\Models\Organization;
use App\Domain\Patients\Models\Patient;
use App\Domain\Patients\Services\PatientService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PatientScreenTest extends TestCase
{
    use RefreshDatabase;

    private const TC = '10000000146';

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function staff(bool $read, bool $write = false): User
    {
        $staff = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Patients->value, 'can_read' => $read, 'can_write' => $write, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        return $staff;
    }

    private function patient(): Patient
    {
        return app(PatientService::class)->create(['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'identity_type' => 'tc', 'identity_no' => self::TC, 'birth_year' => 1985]);
    }

    public function test_page_requires_patient_read_permission(): void
    {
        $this->actingAs($this->staff(read: false))->get('/hastalar')->assertForbidden();
        $this->actingAs($this->staff(read: true))->get('/hastalar')->assertOk()->assertSee('Hastalar');
    }

    public function test_list_shows_masked_identity_only(): void
    {
        $this->patient();

        Livewire::test('pages::patients.index')
            ->assertSee('Ayşe Yılmaz')
            ->assertSee('100******46')
            ->assertDontSee(self::TC);
    }

    public function test_search_by_exact_identity_number(): void
    {
        $this->patient();
        app(PatientService::class)->create(['first_name' => 'Mehmet', 'last_name' => 'Kaya', 'identity_type' => 'unknown']);

        Livewire::test('pages::patients.index')
            ->set('search', self::TC)
            ->assertSee('Ayşe Yılmaz')
            ->assertDontSee('Mehmet Kaya');
    }

    public function test_create_patient(): void
    {
        Livewire::test('pages::patients.index')
            ->call('openForm')
            ->set('first_name', 'Ayşe')
            ->set('last_name', 'Yılmaz')
            ->set('identity_type', 'tc')
            ->set('identity_no', self::TC)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Hasta kaydedildi.');

        $this->assertSame(self::TC, Patient::sole()->identity_no);
    }

    public function test_invalid_identity_is_shown_on_the_field(): void
    {
        Livewire::test('pages::patients.index')
            ->call('openForm')
            ->set('first_name', 'Ayşe')
            ->set('last_name', 'Yılmaz')
            ->set('identity_type', 'tc')
            ->set('identity_no', '12345678901')
            ->call('save')
            ->assertHasErrors(['identity_no']);

        $this->assertSame(0, Patient::count());
    }

    public function test_edit_shows_full_number_to_writers_only(): void
    {
        $patient = $this->patient();

        Livewire::test('pages::patients.index')
            ->call('edit', $patient->id)
            ->assertSet('identity_no', self::TC)
            ->set('last_name', 'Demir')
            ->call('save');

        $this->assertSame('Demir', $patient->fresh()->last_name);

        $this->actingAs($this->staff(read: true));
        Livewire::test('pages::patients.index')->call('edit', $patient->id)->assertForbidden();
    }

    public function test_reader_cannot_create(): void
    {
        $this->actingAs($this->staff(read: true));

        Livewire::test('pages::patients.index')
            ->assertDontSee('Yeni Hasta')
            ->call('openForm')
            ->assertForbidden();
    }

    public function test_detail_page_shows_patient(): void
    {
        $patient = $this->patient();

        $this->get("/hastalar/{$patient->id}")->assertOk()->assertSee('Ayşe Yılmaz')->assertSee('100******46')->assertDontSee(self::TC);
    }

    public function test_detail_page_of_another_organization_is_not_found(): void
    {
        $patient = $this->patient();
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->get("/hastalar/{$patient->id}")->assertNotFound();
    }
}
