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
use App\Domain\EInvoice\Services\EInvoiceFinanceService;
use App\Domain\EInvoice\Services\EInvoiceProviderFactory;
use App\Domain\EInvoice\Services\EInvoiceSyncService;
use App\Domain\Finance\Models\FinanceObligation;
use App\Domain\Finance\Models\FinanceParty;
use App\Domain\Finance\Services\FinancePartyService;
use App\Domain\Finance\Services\ObligationService;
use App\Domain\Finance\Support\PartyKind;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 46 — gelen faturayı Finans'a cari borç (ödenecek) olarak elle aktarma.
 */
class EInvoiceFinanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private EInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $connection = EInvoiceConnection::create(['organization_id' => $this->org->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'k']);

        $provider = new FakeEInvoiceProvider([
            '5d59e861-0ecc-11e7-a708-f52ed20ddd29' => file_get_contents(base_path('tests/Fixtures/einvoice/tibbi-cihaz.xml')),
        ]);
        $this->app->instance(EInvoiceProviderFactory::class, new class($provider) extends EInvoiceProviderFactory
        {
            public function __construct(private readonly EInvoiceProvider $provider) {}

            public function for(EInvoiceConnection $connection): EInvoiceProvider
            {
                return $this->provider;
            }
        });
        app(EInvoiceSyncService::class)->sync($connection);
        $this->invoice = EInvoice::sole();
    }

    private function service(): EInvoiceFinanceService
    {
        return app(EInvoiceFinanceService::class);
    }

    public function test_transfer_creates_an_open_payable_with_a_new_supplier_party(): void
    {
        $obligation = $this->service()->transfer($this->invoice, Carbon::parse('2026-11-16'));

        $this->assertSame('payable', $obligation->direction->value);
        $this->assertSame(2370500, $obligation->amount);
        $this->assertSame('2026-11-16', $obligation->due_on->toDateString());
        $this->assertSame('ADM2026000451', $obligation->document_no);
        $this->assertStringContainsString('ADM2026000451', $obligation->description);

        $party = FinanceParty::sole();
        $this->assertSame('Anadolu Dental Medikal Ltd. Şti.', $party->name);
        $this->assertSame('1234567890', $party->tax_number);
        $this->assertSame(PartyKind::Supplier, $party->kind);
        $this->assertSame($obligation->id, $this->invoice->fresh()->finance_obligation_id);
    }

    public function test_existing_party_is_found_by_tax_number(): void
    {
        $party = app(FinancePartyService::class)->create(['name' => 'Anadolu (eski ad)', 'kind' => PartyKind::Supplier, 'tax_number' => '123 456 7890', 'default_term_days' => 45]);

        $obligation = $this->service()->transfer($this->invoice);

        $this->assertSame($party->id, $obligation->party_id);
        $this->assertSame('2026-12-01', $obligation->due_on->toDateString());
        $this->assertSame(1, FinanceParty::count());
    }

    public function test_name_collision_with_another_tax_number_gets_a_distinct_name(): void
    {
        app(FinancePartyService::class)->create(['name' => 'Anadolu Dental Medikal Ltd. Şti.', 'kind' => PartyKind::Supplier, 'tax_number' => '9999999999']);

        $obligation = $this->service()->transfer($this->invoice);

        $this->assertSame('Anadolu Dental Medikal Ltd. Şti. (1234567890)', $obligation->party->name);
    }

    public function test_default_due_date_is_the_invoice_date(): void
    {
        $this->assertSame('2026-10-17', $this->service()->transfer($this->invoice)->due_on->toDateString());
    }

    public function test_same_invoice_cannot_be_transferred_twice(): void
    {
        $this->service()->transfer($this->invoice);

        $this->expectException(EInvoiceException::class);
        $this->expectExceptionMessage('zaten Finans');

        $this->service()->transfer($this->invoice->fresh());
    }

    public function test_cancelled_obligation_frees_the_invoice(): void
    {
        $first = $this->service()->transfer($this->invoice);
        app(ObligationService::class)->cancel($first, 'Yanlış vade');

        $second = $this->service()->transfer($this->invoice->fresh());

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, FinanceObligation::count());
    }

    public function test_foreign_currency_and_return_invoices_are_refused(): void
    {
        $this->invoice->update(['currency' => 'USD']);
        try {
            $this->service()->transfer($this->invoice);
            $this->fail('Exception bekleniyordu.');
        } catch (EInvoiceException $e) {
            $this->assertStringContainsString('TL', $e->getMessage());
        }

        $this->invoice->update(['currency' => 'TRY', 'type' => 'IADE']);
        $this->expectException(EInvoiceException::class);
        $this->service()->transfer($this->invoice);
    }

    public function test_screen_transfers_after_confirmation(): void
    {
        Livewire::test('pages::einvoice.show', ['invoice' => $this->invoice->id])
            ->assertSee('Cari borç olarak aktar')
            ->call('openTransfer')
            ->assertSet('dueOn', '2026-10-17')
            ->set('dueOn', '2026-11-30')
            ->call('transfer')
            ->assertHasNoErrors()
            ->assertSee('Finans\'a ödenecek olarak aktarıldı');

        $this->assertSame('2026-11-30', FinanceObligation::sole()->due_on->toDateString());
    }

    public function test_transfer_needs_finance_write(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $staff->id, 'module' => Module::Purchasing->value, 'can_read' => true, 'can_write' => true, 'can_delete' => false, 'scope' => PermissionScope::All->value]);
        $this->actingAs($staff);

        Livewire::test('pages::einvoice.show', ['invoice' => $this->invoice->id])
            ->assertDontSee('Cari borç olarak aktar')
            ->call('openTransfer')
            ->assertForbidden();
    }
}
