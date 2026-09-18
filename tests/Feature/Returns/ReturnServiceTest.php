<?php

namespace Tests\Feature\Returns;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Support\Module;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Reporting\Services\DashboardMetricsService;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Services\ReturnService;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Returns\Support\ReturnStatus;
use App\Domain\Stock\Exceptions\InsufficientStockException;
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

class ReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Warehouse $warehouse;

    private Product $product;

    private Supplier $supplier;

    private User $admin;

    private User $clerk;

    private ReturnService $returns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->branch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Merkezi Depo', 'is_default' => true, 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->clerk = $this->staff($this->branch, write: true);

        $this->actingAs($this->admin);
        $this->supplier = Supplier::create(['name' => 'Dental Tedarik A.Ş.', 'status' => 'active']);
        $this->product = Product::create(['name' => 'Kompozit A', 'base_unit' => 'Adet', 'supplier_id' => $this->supplier->id, 'status' => 'active']);
        app(StockMovementService::class)->in($this->product, $this->warehouse, 50, ['lot_no' => 'LOT001', 'unit_cost' => 500], $this->admin);

        $this->returns = app(ReturnService::class);
    }

    private function staff(Branch $branch, bool $write): User
    {
        $user = User::factory()->create(['organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        Permission::create(['user_id' => $user->id, 'module' => Module::StockMovement->value, 'can_read' => true, 'can_write' => $write, 'can_delete' => false, 'scope' => 'own_branch']);

        return $user->fresh();
    }

    private function lot(): StockLot
    {
        return StockLot::where('lot_no', 'LOT001')->sole();
    }

    private function requested(float $quantity = 10, ReturnReason $reason = ReturnReason::Damaged, array $details = []): SupplierReturn
    {
        return $this->returns->request($this->lot(), $quantity, $reason, $this->supplier, $this->clerk, $details);
    }

    private function shipped(float $quantity = 10): SupplierReturn
    {
        $return = $this->requested($quantity);
        $this->returns->approve($return, $this->admin);

        return $this->returns->ship($return, $this->clerk, 'Yurtiçi Kargo 123456');
    }

    public function test_a_return_is_tracked_from_requested_to_completed_and_stock_drops_only_when_shipped(): void
    {
        $return = $this->requested(10, ReturnReason::Damaged, ['reason_note' => 'ambalaj yırtık']);
        $this->assertSame(ReturnStatus::Requested, $return->status);
        $this->assertSame(50.0, (float) $this->lot()->quantity, 'talep stoğa dokunmaz');

        $this->returns->approve($return, $this->admin);
        $this->assertSame(50.0, (float) $this->lot()->quantity, 'onay stoğa dokunmaz');

        $this->returns->ship($return, $this->clerk, 'Yurtiçi Kargo 123456');
        $this->assertSame(40.0, (float) $this->lot()->quantity);

        $movement = StockMovement::where('type', StockMovementType::ReturnMovement->value)->sole();
        $this->assertSame(-10.0, (float) $movement->quantity);
        $this->assertSame(StockOutReason::ReturnToSupplier, $movement->reason_code);
        $this->assertSame(SupplierReturn::class, $movement->related_entity_type);
        $this->assertSame($return->id, $movement->related_entity_id);
        $this->assertStringContainsString('Hasarlı — ambalaj yırtık', $movement->reason);
        $this->assertTrue($movement->belongsToWorkflow());

        $this->returns->supplierApprove($return, $this->clerk, 'Kabul edildi, kredi notu kesilecek');
        $this->returns->complete($return, $this->clerk, 'KN-2026-77', 5000, 'Kredi notu alındı');

        $return->refresh();
        $this->assertSame(ReturnStatus::Completed, $return->status);
        $this->assertSame('Kabul edildi, kredi notu kesilecek', $return->supplier_response);
        $this->assertSame('KN-2026-77', $return->credit_note_number);
        $this->assertSame(5000.0, (float) $return->credit_amount);
        $this->assertSame(40.0, (float) $this->lot()->quantity);

        $this->assertSame(
            [ReturnStatus::Requested, ReturnStatus::Approved, ReturnStatus::Shipped, ReturnStatus::SupplierApproved, ReturnStatus::Completed],
            $return->events()->orderBy('id')->get()->pluck('status')->all(),
        );
        $this->assertSame('Yurtiçi Kargo 123456', $return->events()->where('status', ReturnStatus::Shipped->value)->sole()->note);

        // Denetim kaydı: oluşturma ve her durum değişikliği.
        $this->assertSame(5, AuditLog::where('entity_type', SupplierReturn::class)->where('entity_id', $return->id)->count());
    }

    public function test_only_admin_approves_or_rejects_a_request(): void
    {
        $return = $this->requested();

        foreach (['approve', 'reject'] as $action) {
            try {
                $this->returns->{$action}($return, $this->clerk);
                $this->fail("{$action} personel için reddedilmeliydi");
            } catch (AuthorizationException) {
                $this->assertSame(ReturnStatus::Requested, $return->fresh()->status);
            }
        }
    }

    public function test_a_request_rejected_before_shipping_has_no_stock_effect(): void
    {
        $return = $this->requested();
        $this->returns->approve($return, $this->admin);
        $this->returns->reject($return, $this->admin, 'Tedarikçi iade kabul etmiyor, imha edilecek');

        $this->assertSame(ReturnStatus::Rejected, $return->fresh()->status);
        $this->assertSame(50.0, (float) $this->lot()->quantity);
        $this->assertSame(0, StockMovement::where('type', 'return')->count());
    }

    public function test_admin_cannot_reject_a_shipped_return_on_behalf_of_the_supplier(): void
    {
        $return = $this->shipped();

        $this->expectException(ReturnException::class);
        $this->returns->reject($return, $this->admin, 'vazgeçtik');
    }

    public function test_supplier_rejection_after_shipping_puts_the_stock_back(): void
    {
        $return = $this->shipped(10);
        $this->assertSame(40.0, (float) $this->lot()->quantity);

        try {
            $this->returns->supplierReject($return, $this->clerk, '  ');
            $this->fail('tedarikçi gerekçesi zorunlu olmalıydı');
        } catch (ReturnException) {
            $this->assertSame(ReturnStatus::Shipped, $return->fresh()->status);
        }

        $this->returns->supplierReject($return, $this->clerk, 'Ürün hasarı taşımadan kaynaklı');

        $this->assertSame(ReturnStatus::Rejected, $return->fresh()->status);
        $this->assertSame('Ürün hasarı taşımadan kaynaklı', $return->fresh()->supplier_response);
        $this->assertSame(50.0, (float) $this->lot()->quantity);

        $shipment = StockMovement::where('type', 'return')->sole();
        $this->assertTrue(StockMovement::where('type', 'cancel')->where('related_entity_type', StockMovement::class)->where('related_entity_id', $shipment->id)->exists());
    }

    public function test_shipping_is_blocked_when_the_lot_no_longer_has_the_quantity(): void
    {
        $return = $this->requested(30);
        $this->returns->approve($return, $this->admin);

        // Onaydan sonra lottan 25 adet kullanıldı; 25 kaldı.
        app(StockMovementService::class)->out($this->product, $this->warehouse, 25, $this->lot(), $this->admin, 'Kullanım', StockOutReason::ClinicalUse);

        try {
            $this->returns->ship($return, $this->clerk);
            $this->fail('yetersiz stokta kargo engellenmeliydi');
        } catch (InsufficientStockException) {
            $this->assertSame(ReturnStatus::Approved, $return->fresh()->status);
            $this->assertSame(25.0, (float) $this->lot()->quantity);
            $this->assertSame(0, $return->events()->where('status', ReturnStatus::Shipped->value)->count());
        }
    }

    public function test_request_rules(): void
    {
        $cases = [
            'lottakinden fazla' => fn () => $this->requested(51),
            'sıfır miktar' => fn () => $this->requested(0),
            'diğer nedeni açıklamasız' => fn () => $this->requested(5, ReturnReason::Other),
        ];

        foreach ($cases as $label => $attempt) {
            try {
                $attempt();
                $this->fail("{$label} reddedilmeliydi");
            } catch (ReturnException) {
                $this->assertSame(0, SupplierReturn::count(), $label);
            }
        }

        $this->assertSame('ölçü hatalı', $this->requested(5, ReturnReason::Other, ['reason_note' => 'ölçü hatalı'])->reason_note);
    }

    public function test_the_related_order_must_be_from_the_same_supplier_and_have_delivered_the_product(): void
    {
        $orders = app(PurchaseOrderService::class);
        $other = Supplier::create(['name' => 'Başka Tedarikçi', 'status' => 'active']);

        $order = $orders->create($this->supplier, $this->warehouse, [['product_id' => $this->product->id, 'quantity' => 20, 'unit_price' => 500]], null, null, $this->admin);
        $orders->submit($order, $this->admin);

        try {
            $this->requested(5, details: ['purchase_order_id' => $order->id]);
            $this->fail('teslim alınmamış sipariş reddedilmeliydi');
        } catch (ReturnException $e) {
            $this->assertStringContainsString('teslim alınmamış', $e->getMessage());
        }

        $orders->approve($order, $this->admin);
        $orders->markOrdered($order, $this->admin);
        $line = $order->lines()->sole();
        $orders->receive($order, [$line->id => ['quantity' => 20, 'lot_no' => 'LOT001']], ['invoice_number' => 'FTR-1'], $this->admin);

        try {
            $this->returns->request($this->lot(), 5, ReturnReason::Defective, $other, $this->clerk, ['purchase_order_id' => $order->id]);
            $this->fail('başka tedarikçinin siparişi reddedilmeliydi');
        } catch (ReturnException $e) {
            $this->assertStringContainsString('tedarikçiye ait değil', $e->getMessage());
        }

        $return = $this->requested(5, ReturnReason::Defective, ['purchase_order_id' => $order->id, 'invoice_number' => 'FTR-1']);
        $this->assertSame($order->id, $return->purchase_order_id);
        $this->assertSame('FTR-1', $return->invoice_number);
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $return = $this->requested();

        foreach ([
            fn () => $this->returns->ship($return, $this->clerk),
            fn () => $this->returns->supplierApprove($return, $this->clerk),
            fn () => $this->returns->complete($return, $this->clerk),
            fn () => $this->returns->supplierReject($return, $this->clerk, 'yanıt'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('geçersiz geçiş reddedilmeliydi');
            } catch (ReturnException) {
                $this->assertSame(ReturnStatus::Requested, $return->fresh()->status);
            }
        }

        $this->returns->approve($return, $this->admin);
        $this->returns->ship($return, $this->clerk);

        $this->expectException(ReturnException::class);
        $this->returns->ship($return, $this->clerk);
    }

    public function test_stock_write_and_branch_scope_are_required(): void
    {
        $reader = $this->staff($this->branch, write: false);
        $otherBranch = Branch::create(['organization_id' => $this->organization->id, 'name' => 'Şube B', 'status' => 'active']);
        $outsider = $this->staff($otherBranch, write: true);

        foreach ([$reader, $outsider] as $user) {
            try {
                $this->returns->request($this->lot(), 5, ReturnReason::Damaged, $this->supplier, $user);
                $this->fail('yetkisiz kullanıcı iade açamamalı');
            } catch (AuthorizationException) {
                $this->assertSame(0, SupplierReturn::count());
            }
        }

        $return = $this->requested();
        $this->returns->approve($return, $this->admin);

        $this->expectException(AuthorizationException::class);
        $this->returns->ship($return, $outsider);
    }

    public function test_return_movements_cannot_be_cancelled_from_the_stock_movements_screen(): void
    {
        $this->shipped();
        $movement = StockMovement::where('type', 'return')->sole();

        $this->actingAs($this->admin);
        Livewire::test('pages::stock.movements')->call('cancel', $movement->id);

        $this->assertSame(40.0, (float) $this->lot()->quantity);
        $this->assertSame(0, StockMovement::where('type', 'cancel')->count());
    }

    public function test_dashboard_counts_a_return_as_an_other_outflow_not_as_usage(): void
    {
        $this->shipped(10);

        $summary = app(DashboardMetricsService::class)->summaryFor($this->organization->id);

        $this->assertSame(10.0, $summary['todayOut']);
        $this->assertSame(0.0, $summary['monthlyUsage']);
        $this->assertSame(['İade' => 10.0], $summary['monthlyOtherOutByReason']);
    }
}
