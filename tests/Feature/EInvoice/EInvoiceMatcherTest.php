<?php

namespace Tests\Feature\EInvoice;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\EInvoice\Clients\FakeEInvoiceProvider;
use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Models\EInvoice;
use App\Domain\EInvoice\Models\EInvoiceConnection;
use App\Domain\EInvoice\Services\EInvoiceMatcher;
use App\Domain\EInvoice\Services\EInvoiceProviderFactory;
use App\Domain\EInvoice\Services\EInvoiceSyncService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Aşama 45 — gelen faturanın tedarikçi, satın alma teslimi ve ürünle
 * eşleştirilmesi. Eşitleme kuyrukta oturumsuz çalıştığı için testler de
 * oturum açmadan yürür: organizasyon kapsamı açıkça verilmelidir.
 */
class EInvoiceMatcherTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private EInvoiceConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->connection = EInvoiceConnection::create(['organization_id' => $this->org->id, 'provider' => 'nilvera', 'environment' => 'test', 'api_key' => 'k']);

        $provider = new FakeEInvoiceProvider(['5d59e861-0ecc-11e7-a708-f52ed20ddd29' => file_get_contents(base_path('tests/Fixtures/einvoice/tibbi-cihaz.xml'))]);
        $this->app->instance(EInvoiceProviderFactory::class, new class($provider) extends EInvoiceProviderFactory
        {
            public function __construct(private readonly EInvoiceProvider $provider) {}

            public function for(EInvoiceConnection $connection): EInvoiceProvider
            {
                return $this->provider;
            }
        });
    }

    private function supplier(Organization $org, string $taxNumber = '123 456 7890'): Supplier
    {
        return Supplier::create(['organization_id' => $org->id, 'name' => 'Anadolu Dental', 'tax_number' => $taxNumber, 'status' => 'active']);
    }

    private function receipt(Organization $org, Supplier $supplier, string $invoiceNumber = 'ADM2026000451', bool $cancelled = false): PurchaseReceipt
    {
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $order = PurchaseOrder::create(['organization_id' => $org->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'status' => 'ordered']);

        return $order->receipts()->create(['warehouse_id' => $warehouse->id, 'invoice_number' => $invoiceNumber, 'cancelled_at' => $cancelled ? now() : null]);
    }

    private function product(Organization $org, array $attributes): Product
    {
        return Product::create(['organization_id' => $org->id, 'name' => 'Ürün', 'base_unit' => 'Adet', 'status' => 'active'] + $attributes);
    }

    private function sync(): EInvoice
    {
        app(EInvoiceSyncService::class)->sync($this->connection);

        return EInvoice::withoutGlobalScopes()->where('organization_id', $this->org->id)->sole();
    }

    public function test_sync_matches_supplier_receipt_and_products(): void
    {
        $supplier = $this->supplier($this->org);
        $receipt = $this->receipt($this->org, $supplier);
        $implant = $this->product($this->org, ['gtin' => '08699999999994']);
        $membrane = $this->product($this->org, ['barcode' => '04049999999991']);

        $invoice = $this->sync();

        $this->assertSame($supplier->id, $invoice->supplier_id);
        $this->assertSame($receipt->id, $invoice->purchase_receipt_id);
        $this->assertSame([$implant->id, $membrane->id, null], $invoice->lines->pluck('product_id')->all());
    }

    public function test_seller_code_matches_product_code_when_there_is_no_gtin(): void
    {
        $this->supplier($this->org);
        $byCode = $this->product($this->org, ['code' => 'STR-021.4510']);

        $this->assertSame($byCode->id, $this->sync()->lines[0]->product_id);
    }

    public function test_other_organizations_records_never_match(): void
    {
        $other = Organization::create(['name' => 'Diğer', 'status' => 'active', 'plan' => 'starter']);
        $otherSupplier = $this->supplier($other);
        $this->receipt($other, $otherSupplier);
        $this->product($other, ['gtin' => '08699999999994']);

        $invoice = $this->sync();

        $this->assertNull($invoice->supplier_id);
        $this->assertNull($invoice->purchase_receipt_id);
        $this->assertSame([null, null, null], $invoice->lines->pluck('product_id')->all());
    }

    public function test_cancelled_receipt_and_other_suppliers_receipt_do_not_match(): void
    {
        $supplier = $this->supplier($this->org);
        $this->receipt($this->org, $supplier, cancelled: true);
        $this->receipt($this->org, $this->supplier($this->org, '999'));

        $this->assertNull($this->sync()->purchase_receipt_id);
    }

    public function test_rematch_fills_only_missing_links(): void
    {
        $invoice = $this->sync();
        $manual = $this->supplier($this->org, '5555555555');
        $invoice->update(['supplier_id' => $manual->id]);

        $this->supplier($this->org);
        $receipt = $this->receipt($this->org, $manual);

        app(EInvoiceMatcher::class)->match($invoice->fresh());

        $invoice = $invoice->fresh();
        $this->assertSame($manual->id, $invoice->supplier_id);
        $this->assertSame($receipt->id, $invoice->purchase_receipt_id);
    }
}
