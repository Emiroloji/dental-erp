<?php

namespace Tests\Feature\Uts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Models\UtsNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 49 — SKT geçmiş / hasarlı çıkışta ÜTS İmha veya HEK/Zayiat bildirimi.
 */
class UtsDisposalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    private Product $implant;

    private Product $membrane;

    private FakeUtsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);

        $stock = app(StockMovementService::class);
        $this->implant = Product::create(['name' => 'Implant 4.1', 'base_unit' => 'Adet', 'status' => 'active', 'tracks_serials' => true, 'gtin' => '08699999999994', 'uts_usage_report' => true]);
        $stock->in($this->implant, $this->warehouse, 2, ['lot_no' => 'IMP-1', 'expiry_date' => '2026-01-01'], $this->admin, tracking: ['serials' => ['S1', 'S2']]);
        $this->membrane = Product::create(['name' => 'Membran', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '04049999999991', 'uts_usage_report' => true]);
        $stock->in($this->membrane, $this->warehouse, 5, ['lot_no' => 'BG-55'], $this->admin);

        UtsConnection::create(['environment' => 'test', 'token' => 't']);
        $this->useClient(new FakeUtsClient);
    }

    private function useClient(FakeUtsClient $client): void
    {
        $this->client = $client;
        $this->app->instance(UtsClient::class, $client);
    }

    private function disposals(): array
    {
        return array_values(array_filter($this->client->calls, fn ($c) => $c[0] === 'dispose'));
    }

    private function out(Product $product, string $reason): Testable
    {
        $test = Livewire::test('pages::stock.out')
            ->call('openForm')
            ->set('product_id', (string) $product->id)
            ->set('warehouse_id', (string) $this->warehouse->id);

        if ($product->tracks_serials) {
            $test->set('selectedSerials', ['S1', 'S2']);
        } else {
            $test->set('quantity', '3');
        }

        return $test->set('reasonCategory', $reason);
    }

    public function test_section_appears_for_tracked_products_disposed_as_expired_or_damaged(): void
    {
        $this->out($this->implant, 'expired')->assertSee('ÜTS bildirimi')->assertSet('disposalType', 'imha')->assertSet('disposalCode', 'SON_KULLANMA_TARIHI_GECMIS');
        $this->out($this->implant, 'damaged')->assertSee('ÜTS bildirimi');
        $this->out($this->implant, 'clinical_use')->assertDontSee('İmha tutanağı');
    }

    public function test_expired_serials_are_reported_for_imha_with_document_number(): void
    {
        $this->out($this->implant, 'expired')
            ->set('disposalDocumentNo', 'IMHA-2026-3')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([
            ['dispose', ['imha', ['UNO' => '08699999999994', 'SNO' => 'S1', 'GRK' => 'SON_KULLANMA_TARIHI_GECMIS', 'BNO' => 'IMHA-2026-3']]],
            ['dispose', ['imha', ['UNO' => '08699999999994', 'SNO' => 'S2', 'GRK' => 'SON_KULLANMA_TARIHI_GECMIS', 'BNO' => 'IMHA-2026-3']]],
        ], $this->disposals());

        $record = UtsNotification::where('type', 'imha')->first();
        $this->assertSame(StockMovement::where('type', 'out')->value('id'), $record->stock_movement_id);
        $this->assertSame('IMHA-2026-3', $record->details['document_no']);
    }

    public function test_imha_needs_a_document_number_and_other_needs_a_description(): void
    {
        $this->out($this->implant, 'expired')->call('save')->assertHasErrors(['disposalDocumentNo']);

        $this->out($this->implant, 'expired')
            ->set('disposalDocumentNo', 'IMHA-1')
            ->set('disposalCode', 'DIGER')
            ->call('save')
            ->assertHasErrors(['disposalDescription']);

        $this->assertSame(0, StockMovement::where('type', 'out')->count());
    }

    public function test_damaged_lot_is_reported_as_hek_zayiat(): void
    {
        $this->out($this->membrane, 'damaged')
            ->set('disposalType', 'hek')
            ->set('disposalCode', 'CALINMA')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([['dispose', ['hek', ['UNO' => '04049999999991', 'LNO' => 'BG-55', 'ADT' => 3, 'TUR' => 'CALINMA']]]], $this->disposals());
    }

    public function test_unchecking_skips_the_uts_report(): void
    {
        $this->out($this->membrane, 'damaged')->set('reportDisposal', false)->call('save')->assertHasNoErrors();

        $this->assertSame([], $this->disposals());
        $this->assertSame(1, StockMovement::where('type', 'out')->count());
    }

    public function test_failure_keeps_the_stock_out_and_can_be_retried_from_history(): void
    {
        $this->useClient(new FakeUtsClient(writeFailure: new UtsException('ÜTS şu an yanıt vermiyor.')));

        $this->out($this->membrane, 'expired')
            ->set('disposalDocumentNo', 'IMHA-1')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('ÜTS bildirimi gönderilemedi');

        $failed = UtsNotification::sole();
        $this->assertSame('failed', $failed->status);

        $this->useClient(new FakeUtsClient);
        Livewire::test('pages::uts.history')->call('retry', $failed->id)->assertSee('Bildirim ÜTS\'ye gönderildi');

        $this->assertSame([['dispose', ['imha', ['UNO' => '04049999999991', 'LNO' => 'BG-55', 'ADT' => 3, 'GRK' => 'SON_KULLANMA_TARIHI_GECMIS', 'BNO' => 'IMHA-1']]]], $this->disposals());
    }

    public function test_movement_with_active_disposal_needs_uts_cancel_first(): void
    {
        $this->out($this->membrane, 'expired')->set('disposalDocumentNo', 'IMHA-1')->call('save');
        $movement = StockMovement::where('type', 'out')->sole();
        $sent = UtsNotification::sole();

        Livewire::test('pages::stock.movements')->call('cancel', $movement->id)->assertSee('Önce ÜTS bildirimini iptal edin');

        Livewire::test('pages::uts.history')->call('confirmCancel', $sent->id)->call('cancelInUts');

        $this->assertSame(['cancelDisposal', ['imha', $sent->uts_notification_id]], end($this->client->calls));

        Livewire::test('pages::stock.movements')->call('cancel', $movement->id);
        $this->assertSame(1, StockMovement::where('type', 'cancel')->count());
    }
}
