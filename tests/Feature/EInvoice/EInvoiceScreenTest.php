<?php

namespace Tests\Feature\EInvoice;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Access\Support\PermissionScope;
use App\Domain\EInvoice\Clients\FakeEInvoiceProvider;
use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\EInvoice\Models\EInvoiceConnection;
use App\Domain\EInvoice\Services\EInvoiceProviderFactory;
use App\Domain\EInvoice\Services\EInvoiceSyncService;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EInvoiceScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private EInvoiceConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->connection = EInvoiceConnection::create(['organization_id' => $this->org->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'k']);
    }

    private function useProvider(EInvoiceProvider $provider): void
    {
        $this->app->instance(EInvoiceProviderFactory::class, new class($provider) extends EInvoiceProviderFactory
        {
            public function __construct(private readonly EInvoiceProvider $provider) {}

            public function for(EInvoiceConnection $connection): EInvoiceProvider
            {
                return $this->provider;
            }
        });
    }

    private function documents(): array
    {
        return [
            '5d59e861-0ecc-11e7-a708-f52ed20ddd29' => file_get_contents(base_path('tests/Fixtures/einvoice/tibbi-cihaz.xml')),
            'a68b5aa1-f7d7-11e8-a3a9-f783d4ccac99' => file_get_contents(base_path('tests/Fixtures/einvoice/temel-ilac.xml')),
        ];
    }

    private function seedInvoices(): void
    {
        $this->useProvider(new FakeEInvoiceProvider($this->documents()));
        app(EInvoiceSyncService::class)->sync($this->connection);
    }

    private function staff(Module $module, bool $write = false): User
    {
        $staff = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => $module->value, 'can_read' => true, 'can_write' => $write, 'can_delete' => false, 'scope' => PermissionScope::All->value]);

        return $staff;
    }

    public function test_list_requires_purchasing_read(): void
    {
        $this->actingAs($this->staff(Module::StockMovement))->get('/e-fatura')->assertForbidden();
        $this->actingAs($this->staff(Module::Purchasing))->get('/e-fatura')->assertOk()->assertSee('Gelen e-Faturalar');
    }

    public function test_list_shows_invoices_with_totals(): void
    {
        $this->seedInvoices();

        Livewire::test('pages::einvoice.index')
            ->assertSee('ADM2026000451')
            ->assertSee('Anadolu Dental Medikal Ltd. Şti.')
            ->assertSee('23.705,00 ₺')
            ->assertSee('İlaç ve tıbbi cihaz')
            ->assertSee('EGE2026000088');
    }

    public function test_search_filters_by_number_or_sender(): void
    {
        $this->seedInvoices();

        Livewire::test('pages::einvoice.index')
            ->set('search', 'anadolu')
            ->assertSee('ADM2026000451')
            ->assertDontSee('EGE2026000088');
    }

    public function test_sync_now_pulls_new_invoices_and_reports_the_result(): void
    {
        $this->useProvider(new FakeEInvoiceProvider($this->documents()));

        Livewire::test('pages::einvoice.index')
            ->call('syncNow')
            ->assertSee('2 yeni fatura alındı')
            ->assertSee('ADM2026000451');
    }

    public function test_sync_now_shows_provider_error(): void
    {
        $this->useProvider(new FakeEInvoiceProvider(failure: new EInvoiceException('e-Fatura servisi şu an yanıt vermiyor.')));

        Livewire::test('pages::einvoice.index')->call('syncNow')->assertSee('e-Fatura servisi şu an yanıt vermiyor.');
    }

    public function test_sync_now_needs_purchasing_write_and_a_connection(): void
    {
        $this->actingAs($this->staff(Module::Purchasing));
        Livewire::test('pages::einvoice.index')->assertDontSee('Şimdi çek')->call('syncNow')->assertForbidden();

        $this->actingAs($this->admin);
        $this->connection->delete();
        Livewire::test('pages::einvoice.index')->assertSee('e-Fatura bağlantısı kurulmamış');
    }

    public function test_detail_shows_lines_and_tracking_identities(): void
    {
        $this->seedInvoices();
        $invoice = EInvoice::where('invoice_number', 'ADM2026000451')->sole();

        $this->get("/e-fatura/{$invoice->id}")
            ->assertOk()
            ->assertSee('Straumann BLT Ø4.1 RC SLActive 10mm')
            ->assertSee('SN-778812')
            ->assertSee('IMP2026A')
            ->assertSee('08699999999994')
            ->assertSee('Kargo bedeli')
            ->assertSee('19.000,00 ₺');
    }

    public function test_pdf_is_served_from_the_provider(): void
    {
        $this->seedInvoices();
        $invoice = EInvoice::where('invoice_number', 'ADM2026000451')->sole();
        $this->app->bind(EInvoiceProvider::class, fn () => new FakeEInvoiceProvider);

        $this->get("/e-fatura/{$invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_other_organization_cannot_open_detail_or_pdf(): void
    {
        $this->seedInvoices();
        $invoice = EInvoice::where('invoice_number', 'ADM2026000451')->sole();
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));

        $this->get("/e-fatura/{$invoice->id}")->assertNotFound();
        $this->get("/e-fatura/{$invoice->id}/pdf")->assertNotFound();
    }
}
