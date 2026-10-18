<?php

namespace Tests\Feature\Uts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Services\ReturnService;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Services\UtsReturnService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 48 — tedarikçiye iade kargolanınca ÜTS Verme bildirimi.
 */
class UtsReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private FakeUtsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $this->supplier = Supplier::create(['name' => 'Anadolu Dental', 'tax_number' => '1234567890', 'status' => 'active']);
        UtsConnection::create(['environment' => 'test', 'token' => 't']);
        $this->useClient(new FakeUtsClient);
    }

    private function useClient(FakeUtsClient $client): void
    {
        $this->client = $client;
        $this->app->instance(UtsClient::class, $client);
    }

    private function shippedReturn(bool $serial = true): SupplierReturn
    {
        $stock = app(StockMovementService::class);
        $returns = app(ReturnService::class);

        if ($serial) {
            $product = Product::create(['name' => 'Implant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true, 'gtin' => '08699999999994', 'uts_usage_report' => true]);
            $stock->in($product, $this->warehouse, 2, ['lot_no' => 'IMP-1'], $this->admin, tracking: ['serials' => ['S1', 'S2']]);
            $lot = StockLot::where('product_id', $product->id)->sole();
            $return = $returns->request($lot, 2, ReturnReason::Defective, $this->supplier, $this->admin, ['serials' => ['S1', 'S2']]);
        } else {
            $product = Product::create(['name' => 'Membran', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '04049999999991', 'uts_usage_report' => true]);
            $stock->in($product, $this->warehouse, 5, ['lot_no' => 'BG-55'], $this->admin);
            $lot = StockLot::where('product_id', $product->id)->sole();
            $return = $returns->request($lot, 3, ReturnReason::Defective, $this->supplier, $this->admin);
        }

        $returns->approve($return, $this->admin);

        return $returns->ship($return->fresh(), $this->admin);
    }

    private function gives(): array
    {
        return array_values(array_filter($this->client->calls, fn ($c) => $c[0] === 'give'));
    }

    public function test_each_serial_is_reported_and_supplier_institution_is_remembered(): void
    {
        $return = $this->shippedReturn();

        $records = app(UtsReturnService::class)->report($return, 12345, 'IADE-2026-7', Carbon::parse('2026-10-18'));

        $this->assertCount(2, $records);
        $this->assertSame([
            ['give', [['UNO' => '08699999999994', 'SNO' => 'S1', 'KUN' => 12345, 'BNO' => 'IADE-2026-7', 'GIT' => '2026-10-18']]],
            ['give', [['UNO' => '08699999999994', 'SNO' => 'S2', 'KUN' => 12345, 'BNO' => 'IADE-2026-7', 'GIT' => '2026-10-18']]],
        ], $this->gives());
        $this->assertSame('verme', $records[0]->type);
        $this->assertSame($return->id, $records[0]->supplier_return_id);
        $this->assertSame(12345, $this->supplier->fresh()->uts_institution_no);
        $this->assertSame('sent', app(UtsReturnService::class)->status($return->fresh()));
    }

    public function test_lot_return_sends_lot_and_quantity(): void
    {
        app(UtsReturnService::class)->report($this->shippedReturn(serial: false), 12345, 'IADE-1', Carbon::parse('2026-10-18'));

        $this->assertSame([['give', [['UNO' => '04049999999991', 'LNO' => 'BG-55', 'ADT' => 3, 'KUN' => 12345, 'BNO' => 'IADE-1', 'GIT' => '2026-10-18']]]], $this->gives());
    }

    public function test_partial_failure_can_be_completed_without_resending(): void
    {
        $return = $this->shippedReturn();
        $this->useClient(new FakeUtsClient(writeFailure: new UtsException('ÜTS şu an yanıt vermiyor.')));

        try {
            app(UtsReturnService::class)->report($return, 12345, 'IADE-1', now());
        } catch (UtsException) {
        }
        $this->assertSame('failed', app(UtsReturnService::class)->status($return->fresh()));

        $this->useClient(new FakeUtsClient);
        app(UtsReturnService::class)->report($return->fresh(), 12345, 'IADE-1', now());

        $this->assertCount(2, $this->gives());
        $this->assertSame('sent', app(UtsReturnService::class)->status($return->fresh()));

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('zaten bildirildi');
        app(UtsReturnService::class)->report($return->fresh(), 12345, 'IADE-1', now());
    }

    public function test_only_shipped_returns_of_tracked_products_can_be_reported(): void
    {
        $product = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        app(StockMovementService::class)->in($product, $this->warehouse, 5, ['lot_no' => 'E-1'], $this->admin);
        $return = app(ReturnService::class)->request(StockLot::where('product_id', $product->id)->sole(), 1, ReturnReason::Defective, $this->supplier, $this->admin);

        $this->expectException(UtsException::class);

        app(UtsReturnService::class)->report($return, 12345, 'IADE-1', now());
    }

    public function test_cancel_sends_verme_iptal_for_each_reported_unit(): void
    {
        $return = $this->shippedReturn();
        $records = app(UtsReturnService::class)->report($return, 12345, 'IADE-1', now());

        app(UtsReturnService::class)->cancel($return->fresh());

        $cancels = array_values(array_filter($this->client->calls, fn ($c) => $c[0] === 'cancelGive'));
        $this->assertSame([['cancelGive', [$records[0]->uts_notification_id]], ['cancelGive', [$records[1]->uts_notification_id]]], $cancels);
        $this->assertSame('cancelled', app(UtsReturnService::class)->status($return->fresh()));
    }

    public function test_production_lock_applies(): void
    {
        UtsConnection::first()->update(['environment' => 'production']);

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('gerçek ortamda');

        app(UtsReturnService::class)->report($this->shippedReturn(), 12345, 'IADE-1', now());
    }

    public function test_screen_reports_with_prefilled_institution_number(): void
    {
        $this->supplier->update(['uts_institution_no' => 777]);
        $return = $this->shippedReturn();

        Livewire::test('pages::returns.show', ['return' => $return->id])
            ->assertSee('ÜTS\'ye iade bildir', false)
            ->assertSet('utsInstitutionNo', '777')
            ->set('utsDocumentNo', 'IADE-9')
            ->call('reportToUts')
            ->assertHasNoErrors()
            ->assertSee('ÜTS\'ye bildirildi');

        $this->assertSame(2, UtsNotification::where('type', 'verme')->where('status', 'sent')->count());
    }

    public function test_supplier_rejection_is_blocked_until_uts_cancel(): void
    {
        $return = $this->shippedReturn();
        app(UtsReturnService::class)->report($return, 12345, 'IADE-1', now());

        Livewire::test('pages::returns.show', ['return' => $return->id])
            ->set('actionNote', 'Ürün hasarsız geldi')
            ->call('supplierReject')
            ->assertSee('Önce ÜTS iade bildirimini iptal edin');

        $this->assertSame('shipped', $return->fresh()->status->value);

        Livewire::test('pages::returns.show', ['return' => $return->id])->call('cancelUtsReturn');
        Livewire::test('pages::returns.show', ['return' => $return->id])->set('actionNote', 'Ürün hasarsız geldi')->call('supplierReject');

        $this->assertSame('rejected', $return->fresh()->status->value);
    }
}
