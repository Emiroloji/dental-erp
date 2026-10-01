<?php

namespace Tests\Feature\Uts;

use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Clients\HttpUtsClient;
use App\Domain\Uts\Clients\UnconfiguredUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Services\UtsConnectionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UtsConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $name = 'Klinik'): Organization
    {
        return Organization::create(['name' => $name, 'status' => 'active', 'plan' => 'starter']);
    }

    private function admin(Organization $org): User
    {
        return User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    public function test_token_is_stored_encrypted(): void
    {
        $this->actingAs($this->admin($this->org()));

        app(UtsConnectionService::class)->save('test', 'cok-gizli-token');

        $raw = DB::table('uts_connections')->value('token');
        $this->assertStringNotContainsString('cok-gizli-token', $raw);
        $this->assertSame('cok-gizli-token', UtsConnection::first()->token);
    }

    public function test_saving_again_replaces_the_token_and_resets_verification(): void
    {
        $this->actingAs($this->admin($this->org()));
        $service = app(UtsConnectionService::class);

        $service->save('test', 'ilk');
        UtsConnection::first()->update(['last_verified_at' => now()]);
        $service->save('production', 'ikinci');

        $this->assertSame(1, UtsConnection::count());
        $connection = UtsConnection::first();
        $this->assertSame('production', $connection->environment);
        $this->assertSame('ikinci', $connection->token);
        $this->assertNull($connection->last_verified_at);
    }

    public function test_client_is_unconfigured_without_a_token_and_http_with_one(): void
    {
        $this->actingAs($this->admin($this->org()));
        $this->assertInstanceOf(UnconfiguredUtsClient::class, app(UtsClient::class));

        app(UtsConnectionService::class)->save('test', 'tok');

        $this->assertInstanceOf(HttpUtsClient::class, app(UtsClient::class));
    }

    public function test_another_organizations_token_is_never_used(): void
    {
        $this->actingAs($this->admin($this->org('A')));
        app(UtsConnectionService::class)->save('test', 'a-token');

        $this->actingAs($this->admin($this->org('B')));

        $this->assertNull(app(UtsConnectionService::class)->current());
        $this->assertInstanceOf(UnconfiguredUtsClient::class, app(UtsClient::class));
    }

    public function test_only_admin_may_manage_the_connection_and_read_only_org_may_not(): void
    {
        $org = $this->org();
        $staff = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->assertTrue(Gate::forUser($this->admin($org))->allows('uts_connection.update'));
        $this->assertFalse(Gate::forUser($staff)->allows('uts_connection.update'));

        $org->update(['status' => 'read_only']);
        $this->assertFalse(Gate::forUser($this->admin($org->fresh()))->allows('uts_connection.update'));
    }

    public function test_remove_deletes_the_connection(): void
    {
        $this->actingAs($this->admin($this->org()));
        $service = app(UtsConnectionService::class);
        $service->save('test', 'tok');

        $service->remove();

        $this->assertSame(0, UtsConnection::count());
    }
}
