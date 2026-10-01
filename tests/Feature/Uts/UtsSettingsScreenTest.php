<?php

namespace Tests\Feature\Uts;

use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsAuthException;
use App\Domain\Uts\Models\UtsConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UtsSettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    public function test_admin_saves_the_token_and_it_is_never_rendered_back(): void
    {
        Livewire::test('pages::settings.index')
            ->set('utsEnvironment', 'test')
            ->set('utsToken', 'cok-gizli-token')
            ->call('saveUts')
            ->assertHasNoErrors()
            ->assertSet('utsToken', '')
            ->assertDontSee('cok-gizli-token')
            ->assertSee('Token kayıtlı');

        $this->assertSame('cok-gizli-token', UtsConnection::first()->token);
    }

    public function test_token_is_required_and_environment_is_validated(): void
    {
        Livewire::test('pages::settings.index')
            ->set('utsEnvironment', 'sahte')
            ->set('utsToken', '')
            ->call('saveUts')
            ->assertHasErrors(['utsEnvironment', 'utsToken']);
    }

    public function test_connection_test_marks_verification_on_success(): void
    {
        UtsConnection::create(['organization_id' => $this->admin->organization_id, 'environment' => 'test', 'token' => 'tok']);
        $this->app->bind(UtsClient::class, fn () => new FakeUtsClient);

        Livewire::test('pages::settings.index')->call('testUts')->assertSee('Bağlantı başarılı');

        $this->assertNotNull(UtsConnection::first()->last_verified_at);
    }

    public function test_connection_test_shows_the_error_and_does_not_mark_verification(): void
    {
        UtsConnection::create(['organization_id' => $this->admin->organization_id, 'environment' => 'test', 'token' => 'tok']);
        $this->app->bind(UtsClient::class, fn () => new FakeUtsClient(failure: new UtsAuthException('ÜTS token\'ı geçersiz veya süresi dolmuş.')));

        Livewire::test('pages::settings.index')->call('testUts')->assertSee('geçersiz veya süresi dolmuş');

        $this->assertNull(UtsConnection::first()->last_verified_at);
    }

    public function test_remove_deletes_the_token(): void
    {
        UtsConnection::create(['organization_id' => $this->admin->organization_id, 'environment' => 'test', 'token' => 'tok']);

        Livewire::test('pages::settings.index')->call('removeUts');

        $this->assertSame(0, UtsConnection::count());
    }

    public function test_staff_cannot_manage_the_token(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        $this->actingAs($staff);

        Livewire::test('pages::settings.index')
            ->set('utsToken', 'x')
            ->call('saveUts')
            ->assertForbidden();
    }
}
