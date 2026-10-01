<?php

namespace Tests\Feature\Uts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\UtsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UtsVerifyScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $product = Product::create(['organization_id' => $org->id, 'name' => 'Dolgu', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '08699999999994']);
        $this->actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        app(StockMovementService::class)->in($product, $warehouse, 5, ['lot_no' => 'L1', 'expiry_date' => '2028-01-31']);
    }

    private function fake(array $items = [], ?UtsException $failure = null): FakeUtsClient
    {
        $client = new FakeUtsClient(items: $items, failure: $failure);
        $this->app->bind(UtsClient::class, fn () => $client);

        return $client;
    }

    public function test_page_opens_through_the_route(): void
    {
        $this->fake();

        $this->get('/uts/dogrula')->assertOk()->assertSee('Lot/Seri Doğrula');
    }

    public function test_verifies_a_lot_and_shows_matching_expiry(): void
    {
        $this->fake([UtsItem::fromArray(['UTP' => 'TIBBI_CIHAZ', 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 5, 'SKT' => '2028-01-31', 'UAK' => 'LOT', 'MME' => 'Marka'])]);

        Livewire::test('pages::uts.verify')
            ->set('uno', '08699999999994')
            ->set('lot', 'L1')
            ->call('check')
            ->assertSee('ÜTS\'de kayıtlı', false)
            ->assertSee('31.01.2028')
            ->assertSee('SKT uyuşuyor');
    }

    public function test_flags_expiry_mismatch(): void
    {
        $this->fake([UtsItem::fromArray(['UNO' => '08699999999994', 'LNO' => 'L1', 'SKT' => '2027-06-30'])]);

        Livewire::test('pages::uts.verify')->set('uno', '08699999999994')->set('lot', 'L1')->call('check')->assertSee('SKT uyuşmuyor');
    }

    public function test_reports_when_uts_has_no_record(): void
    {
        $this->fake([]);

        Livewire::test('pages::uts.verify')->set('uno', '08699999999994')->set('lot', 'YOK')->call('check')->assertSee('ÜTS\'de kayıt bulunamadı', false);
    }

    public function test_gs1_scan_fills_the_fields(): void
    {
        $client = $this->fake([]);

        Livewire::test('pages::uts.verify')
            ->set('scan', '(01)08699999999994(17)280131(10)L1(21)S9')
            ->assertSet('uno', '08699999999994')
            ->assertSet('lot', 'L1')
            ->assertSet('serial', 'S9');

        $this->assertSame(['lookup', ['08699999999994', 'L1', 'S9']], $client->calls[0]);
    }

    public function test_requires_a_product_number(): void
    {
        $this->fake();

        Livewire::test('pages::uts.verify')->set('uno', '')->call('check')->assertHasErrors(['uno']);
    }

    public function test_shows_uts_errors(): void
    {
        $this->fake(failure: new UtsException('ÜTS şu an yanıt vermiyor.'));

        Livewire::test('pages::uts.verify')->set('uno', '1')->call('check')->assertSee('ÜTS şu an yanıt vermiyor.');
    }
}
