<?php

namespace Tests\Feature\EInvoice;

use App\Domain\EInvoice\Clients\FakeEInvoiceProvider;
use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Exceptions\EInvoiceAuthException;
use App\Domain\EInvoice\Jobs\SyncEInvoicesJob;
use App\Domain\EInvoice\Models\EInvoiceConnection;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class EInvoiceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    public function test_admin_saves_the_api_key_and_it_is_never_rendered_back(): void
    {
        Livewire::test('pages::settings.index')
            ->set('einvoiceEnvironment', 'test')
            ->set('einvoiceApiKey', 'cok-gizli-anahtar')
            ->call('saveEInvoice')
            ->assertHasNoErrors()
            ->assertSet('einvoiceApiKey', '')
            ->assertDontSee('cok-gizli-anahtar')
            ->assertSee('Anahtar kayıtlı');

        $connection = EInvoiceConnection::sole();
        $this->assertSame('nilvera', $connection->provider);
        $this->assertSame('cok-gizli-anahtar', $connection->api_key);
    }

    public function test_connection_test_success_and_auth_failure(): void
    {
        EInvoiceConnection::create(['organization_id' => $this->org->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'k']);

        $this->app->bind(EInvoiceProvider::class, fn () => new FakeEInvoiceProvider);
        Livewire::test('pages::settings.index')->call('testEInvoice')->assertSee('Bağlantı başarılı');
        $this->assertNotNull(EInvoiceConnection::sole()->last_verified_at);

        $this->app->bind(EInvoiceProvider::class, fn () => new FakeEInvoiceProvider(failure: new EInvoiceAuthException('e-Fatura API anahtarı geçersiz')));
        Livewire::test('pages::settings.index')->call('testEInvoice')->assertSee('e-Fatura API anahtarı geçersiz');
    }

    public function test_remove(): void
    {
        EInvoiceConnection::create(['organization_id' => $this->org->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'k']);

        Livewire::test('pages::settings.index')->call('removeEInvoice')->assertSee('e-Fatura bağlantısı kaldırıldı');

        $this->assertSame(0, EInvoiceConnection::count());
    }

    public function test_only_admin_and_not_in_read_only_organization(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        $this->assertFalse(Gate::forUser($staff)->allows('einvoice_connection.update'));
        $this->assertTrue(Gate::forUser($this->admin)->allows('einvoice_connection.update'));

        $this->org->update(['status' => 'read_only']);
        $this->assertFalse(Gate::forUser($this->admin->fresh())->allows('einvoice_connection.update'));
    }

    public function test_scheduled_command_queues_a_job_per_connection(): void
    {
        Queue::fake();
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        EInvoiceConnection::create(['organization_id' => $this->org->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'k']);
        EInvoiceConnection::create(['organization_id' => $other->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'k2']);
        $passive = Organization::create(['name' => 'Pasif', 'status' => 'passive', 'plan' => 'starter']);
        EInvoiceConnection::create(['organization_id' => $passive->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'k3']);

        $this->artisan('einvoice:sync')->assertSuccessful();

        Queue::assertPushed(SyncEInvoicesJob::class, 2);
    }
}
