<?php

namespace Tests\Feature\Access;

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_can_login_and_reach_dashboard(): void
    {
        $organization = Organization::create(['name' => 'Test Klinik', 'status' => 'active', 'plan' => 'starter']);

        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        Livewire::test('pages::access.login')
            ->set('email', 'admin@test.com')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $organization = Organization::create(['name' => 'Test Klinik', 'status' => 'active', 'plan' => 'starter']);

        User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'passive@test.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_STAFF,
            'status' => 'passive',
        ]);

        Livewire::test('pages::access.login')
            ->set('email', 'passive@test.com')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_of_passive_organization_cannot_login(): void
    {
        $organization = Organization::create(['name' => 'Pasif Klinik', 'status' => 'passive', 'plan' => 'starter']);

        User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'admin@passive-org.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        Livewire::test('pages::access.login')
            ->set('email', 'admin@passive-org.com')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_resolving_the_authenticated_user_from_a_fresh_session_does_not_recurse(): void
    {
        $organization = Organization::create(['name' => 'Test Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        Livewire::test('pages::access.login')
            ->set('email', 'admin@test.com')
            ->set('password', 'password')
            ->call('login');

        // Bir sonraki gerçek HTTP isteğinde (php artisan serve altında her istek
        // taze bir process ile başlar) guard'ın kullanıcıyı session'dan yeniden
        // çözmesini (retrieveById) simüle ediyoruz. User modeli üzerinde
        // auth()->user() çağıran bir global scope varsa bu satır sonsuz
        // özyinelemeye / stack overflow'a yol açar (bkz. Aşama 4 sonrası bulunan
        // gerçek bug).
        Auth::forgetGuards();

        $this->get('/dashboard')->assertOk();
    }
}
