<?php

namespace Tests\Feature\Purchasing;

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
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 30 — teslim alma düzeltmesi.
 *
 * Yanlış girilen bir teslim alma kaydı silinmez, iptal edilir: stok hareketleri
 * ters kayıtla geri alınır, "gelen" miktar düşer ve sipariş durumu yeniden
 * hesaplanır. Teslim alınan stok kullanılmışsa iptal edilemez.
 */
class PurchaseReceiptCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Product $gloves;

    private User $admin;

    private PurchaseOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Ana Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);

        $this->supplier = Supplier::create(['name' => 'Dental Tedarik A.Ş.', 'status' => 'active']);
        $this->gloves = Product::create(['name' => 'Eldiven', 'base_unit' => 'Adet', 'status' => 'active']);
        $this->orders = app(PurchaseOrderService::class);
    }

    private function orderedOrder(float $quantity = 100): PurchaseOrder
    {
        $order = $this->orders->create($this->supplier, $this->warehouse, [
            ['product_id' => $this->gloves->id, 'quantity' => $quantity, 'unit_price' => 2.5],
        ], null, null, $this->admin);

        $this->orders->submit($order, $this->admin);
        $this->orders->approve($order, $this->admin);
        $this->orders->markOrdered($order, $this->admin);

        return $order->refresh();
    }

    private function stock(): float
    {
        return (float) StockLot::where('product_id', $this->gloves->id)->where('warehouse_id', $this->warehouse->id)->sum('quantity');
    }

    public function test_cancelling_a_full_receipt_reverses_the_stock_and_reopens_the_order(): void
    {
        $order = $this->orderedOrder();
        $line = $order->lines->first();

        $receipt = $this->orders->receive($order, [
            $line->id => ['quantity' => 100, 'lot_no' => 'ELD-1', 'unit_cost' => 2.4],
        ], ['invoice_number' => 'FTR-1'], $this->admin);

        $this->assertSame(100.0, $this->stock());
        $this->assertSame(PurchaseOrderStatus::Completed, $order->fresh()->status);

        $this->orders->cancelReceipt($receipt, $this->admin, 'Fatura yanlış siparişe girildi');

        $this->assertSame(0.0, $this->stock(), 'iptal edilen teslimatın stoğu geri alınmalı');
        $this->assertSame(0.0, (float) $line->fresh()->received_quantity);
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->fresh()->status, 'hiç mal gelmemiş sayılır');

        $receipt->refresh();
        $this->assertTrue($receipt->isCancelled());
        $this->assertSame($this->admin->id, $receipt->cancelled_by);
        $this->assertSame('Fatura yanlış siparişe girildi', $receipt->cancellation_reason);
    }

    public function test_the_reversal_is_recorded_as_its_own_movement_not_a_deletion(): void
    {
        $order = $this->orderedOrder();
        $receipt = $this->orders->receive($order, [
            $order->lines->first()->id => ['quantity' => 40],
        ], [], $this->admin);

        $this->orders->cancelReceipt($receipt, $this->admin, 'Yanlış depoya girildi');

        // Giriş hareketi duruyor, üstüne bir iptal hareketi yazılmış:
        // stok geçmişi geriye dönük değiştirilmez.
        $lotIds = StockLot::where('product_id', $this->gloves->id)->pluck('id');
        $movements = StockMovement::whereIn('lot_id', $lotIds)->orderBy('id')->get();
        $this->assertSame(
            [StockMovementType::In->value, StockMovementType::Cancel->value],
            $movements->pluck('type')->map(fn ($type) => $type->value)->all(),
        );
        $this->assertSame(40.0, (float) $movements[0]->quantity);
        $this->assertSame(-40.0, (float) $movements[1]->quantity);

        // Kayıt silinmedi, iptal olarak duruyor.
        $this->assertSame(1, PurchaseReceipt::where('purchase_order_id', $order->id)->count());
    }

    public function test_cancelling_one_of_two_receipts_leaves_the_order_partially_received(): void
    {
        $order = $this->orderedOrder();
        $line = $order->lines->first();

        $first = $this->orders->receive($order, [$line->id => ['quantity' => 60, 'lot_no' => 'A']], [], $this->admin);
        $this->orders->receive($order, [$line->id => ['quantity' => 40, 'lot_no' => 'B']], [], $this->admin);

        $this->assertSame(PurchaseOrderStatus::Completed, $order->fresh()->status);

        $this->orders->cancelReceipt($first, $this->admin, 'İlk irsaliye mükerrer girilmiş');

        $this->assertSame(40.0, $this->stock());
        $this->assertSame(40.0, (float) $line->fresh()->received_quantity);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);
    }

    public function test_a_receipt_whose_stock_was_already_used_cannot_be_cancelled(): void
    {
        $order = $this->orderedOrder();
        $receipt = $this->orders->receive($order, [
            $order->lines->first()->id => ['quantity' => 10, 'lot_no' => 'ELD-1'],
        ], [], $this->admin);

        app(StockMovementService::class)->out(
            $this->gloves,
            $this->warehouse,
            4,
            null,
            $this->admin,
            'Tedavide kullanıldı',
            StockOutReason::ClinicalUse,
        );

        try {
            $this->orders->cancelReceipt($receipt, $this->admin, 'Yanlış girildi');
            $this->fail('Kullanılmış stok geri alınabildi.');
        } catch (PurchasingException $exception) {
            $this->assertStringContainsString('Eldiven', $exception->getMessage());
            $this->assertStringContainsString('stok sayımıyla', $exception->getMessage());
        }

        // Hiçbir şey yarım kalmamalı: miktar ve durum aynen duruyor.
        $this->assertSame(6.0, $this->stock());
        $this->assertSame(10.0, (float) $order->lines->first()->fresh()->received_quantity);
        $this->assertFalse($receipt->fresh()->isCancelled());
    }

    public function test_a_receipt_cannot_be_cancelled_twice(): void
    {
        $order = $this->orderedOrder();
        $receipt = $this->orders->receive($order, [$order->lines->first()->id => ['quantity' => 5]], [], $this->admin);

        $this->orders->cancelReceipt($receipt, $this->admin, 'Yanlış girildi');

        $this->expectException(PurchasingException::class);
        $this->expectExceptionMessage('zaten iptal edilmiş');
        $this->orders->cancelReceipt($receipt->fresh(), $this->admin, 'Tekrar');
    }

    public function test_cancellation_requires_a_reason(): void
    {
        $order = $this->orderedOrder();
        $receipt = $this->orders->receive($order, [$order->lines->first()->id => ['quantity' => 5]], [], $this->admin);

        $this->expectException(PurchasingException::class);
        $this->expectExceptionMessage('gerekçe zorunludur');
        $this->orders->cancelReceipt($receipt, $this->admin, '   ');
    }

    public function test_the_order_screen_cancels_a_receipt_with_a_reason(): void
    {
        $order = $this->orderedOrder();
        $receipt = $this->orders->receive($order, [
            $order->lines->first()->id => ['quantity' => 25, 'lot_no' => 'ELD-1'],
        ], [], $this->admin);

        Livewire::test('pages::purchasing.show', ['order' => $order->id])
            ->assertSee('Teslim Alımını İptal Et')
            ->call('startReceiptCancel', $receipt->id)
            ->set('receiptCancelReason', '')
            ->call('confirmReceiptCancel')
            ->assertHasErrors(['receiptCancelReason' => 'required'])
            ->set('receiptCancelReason', 'Fatura yanlış kaleme girildi')
            ->call('confirmReceiptCancel')
            ->assertHasNoErrors()
            ->assertSee('İptal edildi')
            ->assertSee('Fatura yanlış kaleme girildi');

        $this->assertSame(0.0, $this->stock());
        $this->assertTrue($receipt->fresh()->isCancelled());
    }

    public function test_staff_without_purchasing_access_cannot_cancel_a_receipt(): void
    {
        $order = $this->orderedOrder();
        $receipt = $this->orders->receive($order, [$order->lines->first()->id => ['quantity' => 5]], [], $this->admin);

        $staff = User::factory()->create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->warehouse->branch_id,
            'role' => User::ROLE_STAFF,
            'status' => 'active',
        ]);

        $this->expectException(AuthorizationException::class);
        $this->orders->cancelReceipt($receipt, $staff, 'Yanlış girildi');
    }
}
