<?php

namespace Tests\Feature\Uts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Uts\Services\UtsMatcher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UtsMatcherTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $this->product = Product::create(['organization_id' => $org->id, 'name' => 'Implant', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '08699999999994', 'tracks_serials' => true]);
        $this->actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
    }

    public function test_matches_product_by_gtin_even_without_leading_zero(): void
    {
        $match = app(UtsMatcher::class)->match('8699999999994', null, null);

        $this->assertTrue($match['product']?->is($this->product));
    }

    public function test_unknown_product_returns_nulls(): void
    {
        $match = app(UtsMatcher::class)->match('99999999999999', 'L1', 'S1');

        $this->assertNull($match['product']);
        $this->assertNull($match['lot']);
        $this->assertNull($match['serial']);
        $this->assertNull($match['receipt']);
    }

    public function test_finds_lot_and_serial_of_the_product(): void
    {
        app(StockMovementService::class)->in($this->product, $this->warehouse, 1, ['lot_no' => 'L1', 'expiry_date' => '2028-01-31'], tracking: ['serials' => ['S1']]);

        $match = app(UtsMatcher::class)->match('08699999999994', 'L1', 'S1');

        $this->assertSame('L1', $match['lot']?->lot_no);
        $this->assertSame('S1', $match['serial']?->serial_no);
    }
}
