<?php

namespace Tests\Feature\Access;

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_html_after_login(): void
    {
        $organization = Organization::create(['name' => 'Test Klinik', 'status' => 'active', 'plan' => 'starter']);

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Kontrol Paneli');
        $response->assertSee('Çıkış Yap');
    }
}
