<?php

namespace Tests\Feature\Purchasing;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Exceptions\PurchasingException;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Support\StockMovementType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Product $gloves;

    private Product $composite;

    private User $admin;

    private PurchaseOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Ortodonti Deposu', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->supplier = Supplier::create(['name' => 'Dental Tedarik A.Ş.', 'status' => 'active']);
        $this->gloves = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->composite = Product::create(['name' => 'Kompozit', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->orders = app(PurchaseOrderService::class);
    }

    private function orderedOrder(): PurchaseOrder
    {
        $order = $this->orders->create($this->supplier, $this->warehouse, [
            ['product_id' => $this->gloves->id, 'quantity' => 100, 'unit_price' => 2.5],
            ['product_id' => $this->composite->id, 'quantity' => 10, 'unit_price' => 40],
        ], now()->addWeek()->toDateString(), 'Ortodonti deposunda eldiven azaldı', $this->admin);

        $this->orders->submit($order, $this->admin);
        $this->orders->approve($order, $this->admin);
        $this->orders->markOrdered($order, $this->admin);

        return $order->refresh();
    }

    private function stock(Product $product): float
    {
        return (float) StockLot::where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->sum('quantity');
    }

    public function test_request_approval_and_order_follow_the_documented_flow(): void
    {
        $order = $this->orders->create($this->supplier, $this->warehouse, [
            ['product_id' => $this->gloves->id, 'quantity' => 100, 'unit_price' => 2.5],
        ], null, null, $this->admin);

        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame(250.0, $order->total());

        $this->orders->submit($order, $this->admin);
        $this->orders->approve($order, $this->admin);
        $this->orders->markOrdered($order, $this->admin);

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->status);
        $this->assertNotNull($order->ordered_at);
        $this->assertSame(0.0, $this->stock($this->gloves), 'sipariş vermek stoğu değiştirmez');
        $this->assertSame(['draft', 'pending_approval', 'approved', 'ordered'], $order->events()->orderBy('id')->pluck('status')->map->value->all());
    }

    public function test_only_admin_can_approve_or_reject(): void
    {
        $order = $this->orders->create($this->supplier, $this->warehouse, [['product_id' => $this->gloves->id, 'quantity' => 5, 'unit_price' => 1]], null, null, $this->admin);
        $this->orders->submit($order, $this->admin);

        $buyer = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $this->warehouse->branch_id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $buyer->id, 'module' => Module::Purchasing->value, 'can_read' => true, 'can_write' => true, 'can_delete' => true, 'scope' => 'all']);

        foreach (['approve', 'reject'] as $action) {
            try {
                $this->orders->{$action}($order, $buyer->fresh());
                $this->fail("Personel {$action} yapabildi.");
            } catch (AuthorizationException) {
            }
        }

        $this->orders->reject($order, $this->admin, 'Bütçe yok');
        $this->assertSame(PurchaseOrderStatus::Rejected, $order->fresh()->status);

        $this->expectException(PurchasingException::class);
        $this->orders->approve($order, $this->admin);
    }

    public function test_partial_receipt_books_stock_with_lot_and_leaves_the_rest_open(): void
    {
        $order = $this->orderedOrder();
        $glovesLine = $order->lines->firstWhere('product_id', $this->gloves->id);

        $receipt = $this->orders->receive($order, [
            $glovesLine->id => ['quantity' => 60, 'lot_no' => 'ELD-2409', 'expiry_date' => now()->addYears(2)->toDateString(), 'unit_cost' => 2.4],
        ], ['invoice_number' => 'FTR-1001', 'delivery_note_number' => 'IRS-55'], $this->admin);

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->status);
        $this->assertSame(60.0, (float) $glovesLine->fresh()->received_quantity);
        $this->assertSame(40.0, $glovesLine->fresh()->remaining());
        $this->assertSame(10.0, $order->lines->firstWhere('product_id', $this->composite->id)->remaining());

        $lot = StockLot::where('product_id', $this->gloves->id)->sole();
        $this->assertSame(60.0, (float) $lot->quantity);
        $this->assertSame('ELD-2409', $lot->lot_no);
        $this->assertSame(now()->addYears(2)->toDateString(), $lot->expiry_date->toDateString());
        $this->assertSame('2.40', $lot->unit_cost);

        // Stok girişi hem teslim kaydına (→ sipariş → tedarikçi) bağlı.
        $movement = StockMovement::sole();
        $this->assertSame(StockMovementType::In, $movement->type);
        $this->assertSame(PurchaseReceipt::class, $movement->related_entity_type);
        $this->assertSame($receipt->id, $movement->related_entity_id);
        $this->assertSame($movement->id, $receipt->lines()->sole()->stock_movement_id);
        $this->assertSame('Dental Tedarik A.Ş.', $movement->relatedEntity->order->supplier->name);
        $this->assertSame('FTR-1001', $receipt->invoice_number);
    }

    public function test_receiving_the_remainder_completes_the_order(): void
    {
        $order = $this->orderedOrder();
        [$gloves, $composite] = [$order->lines->firstWhere('product_id', $this->gloves->id), $order->lines->firstWhere('product_id', $this->composite->id)];

        $this->orders->receive($order, [$gloves->id => ['quantity' => 60]], [], $this->admin);
        $this->orders->receive($order, [$gloves->id => ['quantity' => 40], $composite->id => ['quantity' => 10, 'lot_no' => 'KMP-1']], [], $this->admin);

        $this->assertSame(PurchaseOrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(100.0, $this->stock($this->gloves));
        $this->assertSame(10.0, $this->stock($this->composite));
        $this->assertSame(2, $order->receipts()->count());
    }

    public function test_more_than_the_remaining_quantity_cannot_be_received(): void
    {
        $order = $this->orderedOrder();
        $gloves = $order->lines->firstWhere('product_id', $this->gloves->id);
        $this->orders->receive($order, [$gloves->id => ['quantity' => 70]], [], $this->admin);

        try {
            $this->orders->receive($order, [$gloves->id => ['quantity' => 31]], [], $this->admin);
            $this->fail('Kalan miktardan fazlası teslim alındı.');
        } catch (PurchasingException) {
        }

        $this->assertSame(70.0, $this->stock($this->gloves));
        $this->assertSame(1, $order->receipts()->count());
    }

    public function test_receiving_requires_an_open_order_and_at_least_one_quantity(): void
    {
        $order = $this->orders->create($this->supplier, $this->warehouse, [['product_id' => $this->gloves->id, 'quantity' => 5, 'unit_price' => 1]], null, null, $this->admin);
        $line = $order->lines()->sole();

        try {
            $this->orders->receive($order, [$line->id => ['quantity' => 5]], [], $this->admin);
            $this->fail('Sipariş verilmeden teslim alındı.');
        } catch (PurchasingException) {
        }

        $ordered = $this->orderedOrder();
        $this->expectException(PurchasingException::class);
        $this->orders->receive($ordered, [$ordered->lines->first()->id => ['quantity' => 0]], [], $this->admin);
    }

    public function test_a_line_of_another_order_cannot_be_received_through_this_order(): void
    {
        $first = $this->orderedOrder();
        $second = $this->orderedOrder();

        $this->expectException(PurchasingException::class);
        $this->orders->receive($first, [$second->lines->first()->id => ['quantity' => 1]], [], $this->admin);
    }

    public function test_closing_the_remainder_completes_a_partially_received_order(): void
    {
        $order = $this->orderedOrder();
        $this->orders->receive($order, [$order->lines->first()->id => ['quantity' => 30]], [], $this->admin);

        $this->orders->closeRemaining($order, $this->admin, 'Tedarikçi kalanı gönderemeyecek');

        $this->assertSame(PurchaseOrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(30.0, $this->stock($this->gloves), 'teslim alınan stok yerinde kalır');
        $this->assertSame(0, PurchaseOrder::whereIn('status', PurchaseOrderStatus::openStatuses())->count());
    }

    public function test_an_order_can_be_cancelled_until_something_is_received(): void
    {
        $order = $this->orderedOrder();
        $this->orders->cancel($order, $this->admin, 'Tedarikçi değişti');
        $this->assertSame(PurchaseOrderStatus::Cancelled, $order->fresh()->status);

        $partial = $this->orderedOrder();
        $this->orders->receive($partial, [$partial->lines->first()->id => ['quantity' => 1]], [], $this->admin);

        $this->expectException(PurchasingException::class);
        $this->orders->cancel($partial, $this->admin);
    }

    public function test_receipt_into_a_passive_warehouse_is_blocked(): void
    {
        $order = $this->orderedOrder();
        $this->warehouse->update(['status' => 'passive']);

        $this->expectException(InactiveLocationException::class);
        $this->orders->receive($order->refresh(), [$order->lines->first()->id => ['quantity' => 1]], [], $this->admin);
    }

    public function test_an_order_needs_at_least_one_valid_line(): void
    {
        $this->expectException(PurchasingException::class);
        $this->orders->create($this->supplier, $this->warehouse, [], null, null, $this->admin);
    }

    public function test_purchase_receipt_movements_cannot_be_cancelled_from_the_movements_screen(): void
    {
        $order = $this->orderedOrder();
        $this->orders->receive($order, [$order->lines->first()->id => ['quantity' => 10]], [], $this->admin);

        Livewire::test('pages::stock.movements')->call('cancel', StockMovement::sole()->id);

        $this->assertSame(0, StockMovement::where('type', StockMovementType::Cancel->value)->count());
        $this->assertSame(10.0, $this->stock($this->gloves));
    }
}
