<?php

namespace Tests\Feature\EInvoice;

use App\Domain\EInvoice\Clients\FakeEInvoiceProvider;
use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\EInvoice\Models\EInvoiceConnection;
use App\Domain\EInvoice\Services\EInvoiceProviderFactory;
use App\Domain\EInvoice\Services\EInvoiceSyncService;
use App\Domain\EInvoice\Support\SyncResult;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EInvoiceSyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private EInvoiceConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->connection = EInvoiceConnection::create(['organization_id' => $this->org->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'anahtar']);
    }

    private function xml(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/einvoice/'.$name));
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

    private function sync(): SyncResult
    {
        return app(EInvoiceSyncService::class)->sync($this->connection);
    }

    public function test_new_invoices_are_stored_with_lines_identities_and_xml(): void
    {
        $this->useProvider(new FakeEInvoiceProvider(['5d59e861-0ecc-11e7-a708-f52ed20ddd29' => $this->xml('tibbi-cihaz.xml')]));

        $result = $this->sync();

        $this->assertSame(1, $result->created);
        $invoice = EInvoice::withoutGlobalScopes()->sole();
        $this->assertSame($this->org->id, $invoice->organization_id);
        $this->assertSame('ADM2026000451', $invoice->invoice_number);
        $this->assertSame('ILAC_TIBBICIHAZ', $invoice->profile);
        $this->assertSame('1234567890', $invoice->sender_tax_number);
        $this->assertSame(2370500, $invoice->payable);
        $this->assertSame('2026-10-17', $invoice->issue_date->toDateString());
        $this->assertCount(3, $invoice->lines);
        $this->assertSame(['SN-778812', 'SN-778813'], $invoice->lines[0]->identities->pluck('serial')->all());
        $this->assertSame('2026-01-15', $invoice->lines[0]->identities[0]->production_date->toDateString());
        Storage::disk('local')->assertExists($invoice->xml_path);
        $this->assertNotNull($this->connection->fresh()->last_synced_at);
    }

    public function test_same_invoice_is_not_stored_twice(): void
    {
        $this->useProvider(new FakeEInvoiceProvider(['5d59e861-0ecc-11e7-a708-f52ed20ddd29' => $this->xml('tibbi-cihaz.xml')]));

        $this->sync();
        $result = $this->sync();

        $this->assertSame(0, $result->created);
        $this->assertSame(1, DB::table('e_invoices')->count());
    }

    public function test_broken_invoice_is_skipped_and_others_are_stored(): void
    {
        $this->useProvider(new FakeEInvoiceProvider([
            'bozuk-uuid' => '<bozuk',
            'a68b5aa1-f7d7-11e8-a3a9-f783d4ccac99' => $this->xml('temel-ilac.xml'),
        ]));

        $result = $this->sync();

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->failed);
        $this->assertSame(['a68b5aa1-f7d7-11e8-a3a9-f783d4ccac99'], DB::table('e_invoices')->pluck('provider_uuid')->all());
    }

    public function test_listed_uuid_must_match_the_document(): void
    {
        $this->useProvider(new FakeEInvoiceProvider(['baska-uuid' => $this->xml('temel-ilac.xml')]));

        $this->assertSame(1, $this->sync()->failed);
        $this->assertSame(0, DB::table('e_invoices')->count());
    }

    public function test_provider_failure_keeps_last_sync_time(): void
    {
        $this->connection->update(['last_synced_at' => Carbon::parse('2026-10-01 09:00')]);
        $this->useProvider(new FakeEInvoiceProvider(failure: new EInvoiceException('e-Fatura servisi şu an yanıt vermiyor.')));

        $this->expectException(EInvoiceException::class);

        try {
            $this->sync();
        } finally {
            $this->assertSame('2026-10-01 09:00:00', $this->connection->fresh()->last_synced_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_first_sync_looks_back_thirty_days_then_from_last_sync_minus_a_day(): void
    {
        Carbon::setTestNow('2026-10-18 12:00');
        $fake = new FakeEInvoiceProvider([]);
        $this->useProvider($fake);

        $this->sync();
        $this->assertSame('2026-09-18', $fake->sinceDates[0]->toDateString());

        $this->sync();
        $this->assertSame('2026-10-17', $fake->sinceDates[1]->toDateString());
    }

    public function test_invoices_are_isolated_per_organization(): void
    {
        $this->useProvider(new FakeEInvoiceProvider(['5d59e861-0ecc-11e7-a708-f52ed20ddd29' => $this->xml('tibbi-cihaz.xml')]));
        $this->sync();

        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        $this->assertSame(0, EInvoice::count());

        $otherConnection = EInvoiceConnection::create(['organization_id' => $other->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'k2']);
        $this->assertSame(1, app(EInvoiceSyncService::class)->sync($otherConnection)->created);
        $this->assertSame(1, EInvoice::count());
    }

    public function test_api_key_is_encrypted_and_hidden(): void
    {
        $this->assertStringNotContainsString('anahtar', (string) DB::table('e_invoice_connections')->value('api_key'));
        $this->assertSame('anahtar', $this->connection->fresh()->api_key);
        $this->assertArrayNotHasKey('api_key', $this->connection->toArray());
    }
}
