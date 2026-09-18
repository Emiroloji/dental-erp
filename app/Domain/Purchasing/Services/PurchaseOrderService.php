<?php

namespace App\Domain\Purchasing\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Purchasing\Exceptions\PurchasingException;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Purchasing\Notifications\PurchaseNotifier;
use App\Domain\Purchasing\Support\PurchaseOrderStatus;
use App\Domain\Purchasing\Support\PurchasingPermissions;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Satın alma akışı (proje.md Bölüm 9): talep → Admin onayı → tedarikçiye
 * sipariş → kısmi/tam teslim alma. Teslim alınan her miktar sipariş satırına
 * bağlanır ve StockMovementService::in() ile lot/SKT/alış fiyatıyla stoğa
 * girer; kalan miktar açık sipariş olarak kalır (kurallar.md Bölüm 1).
 */
class PurchaseOrderService
{
    /** Ondalık miktar karşılaştırmalarında yuvarlama toleransı. */
    private const EPSILON = 0.0001;

    public function __construct(
        private readonly StockMovementService $stock,
        private readonly PurchasingPermissions $permissions,
        private readonly PurchaseNotifier $notifier,
    ) {}

    /**
     * @param  array<int, array{product_id: int, quantity: float|int|string, unit_price?: float|int|string|null}>  $lines
     */
    public function create(Supplier $supplier, Warehouse $warehouse, array $lines, ?string $expectedDeliveryDate, ?string $note, User $actor): PurchaseOrder
    {
        $this->ensureCanManage($actor, $warehouse);
        $this->ensureOrderable($supplier, $warehouse, $lines);

        return DB::transaction(function () use ($supplier, $warehouse, $lines, $expectedDeliveryDate, $note, $actor) {
            $order = PurchaseOrder::create([
                'organization_id' => $actor->organization_id,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => PurchaseOrderStatus::Draft,
                'expected_delivery_date' => $expectedDeliveryDate,
                'note' => $note,
                'requested_by' => $actor->id,
            ]);

            $this->writeLines($order, $lines);
            $order->events()->create(['status' => PurchaseOrderStatus::Draft, 'actor_id' => $actor->id, 'note' => $note]);

            return $order;
        });
    }

    /**
     * Yalnızca taslak düzenlenebilir; onaya gönderilen talep kilitlenir.
     *
     * @param  array<int, array{product_id: int, quantity: float|int|string, unit_price?: float|int|string|null}>  $lines
     */
    public function updateDraft(PurchaseOrder $order, Supplier $supplier, Warehouse $warehouse, array $lines, ?string $expectedDeliveryDate, ?string $note, User $actor): PurchaseOrder
    {
        $this->ensureCanManage($actor, $order->warehouse);
        $this->ensureCanManage($actor, $warehouse);
        $this->ensureOrderable($supplier, $warehouse, $lines);

        DB::transaction(function () use ($order, $supplier, $warehouse, $lines, $expectedDeliveryDate, $note) {
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PurchaseOrderStatus::Draft) {
                throw new PurchasingException('Yalnızca taslak halindeki talepler düzenlenebilir.');
            }

            $locked->update([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'expected_delivery_date' => $expectedDeliveryDate,
                'note' => $note,
            ]);

            $locked->lines()->delete();
            $this->writeLines($locked, $lines);
        });

        return $order->refresh();
    }

    public function submit(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::PendingApproval, $actor);
    }

    public function approve(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::Approved, $actor);
    }

    public function reject(PurchaseOrder $order, User $actor, ?string $note = null): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::Rejected, $actor, $note);
    }

    public function markOrdered(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::Ordered, $actor, effect: fn (PurchaseOrder $locked) => $locked->ordered_at = now());
    }

    public function cancel(PurchaseOrder $order, User $actor, ?string $note = null): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::Cancelled, $actor, $note);
    }

    /**
     * Kısmi teslimde gelmeyecek kalanı kapatır: teslim alınan stok yerinde
     * kalır, sipariş artık açık sipariş sayılmaz.
     */
    public function closeRemaining(PurchaseOrder $order, User $actor, ?string $note = null): PurchaseOrder
    {
        if ($order->status !== PurchaseOrderStatus::PartiallyReceived) {
            throw new PurchasingException('Yalnızca kısmi teslim alınmış bir siparişin kalanı kapatılabilir.');
        }

        return $this->transition($order, PurchaseOrderStatus::Completed, $actor, $note ?? 'Kalan miktar kapatıldı.');
    }

    /**
     * @param  array<int, array{quantity: float|int|string|null, lot_no?: ?string, expiry_date?: ?string, unit_cost?: float|int|string|null}>  $lines  sipariş satırı id => teslim bilgisi
     * @param  array{invoice_number?: ?string, delivery_note_number?: ?string, document_path?: ?string, document_name?: ?string}  $document
     */
    public function receive(PurchaseOrder $order, array $lines, array $document, User $actor): PurchaseReceipt
    {
        $order->loadMissing('warehouse');
        $this->ensureCanManage($actor, $order->warehouse);

        $lines = array_filter($lines, fn (array $line) => (float) ($line['quantity'] ?? 0) > 0);

        if ($lines === []) {
            throw new PurchasingException('Teslim alınacak en az bir kalem için miktar girin.');
        }

        return DB::transaction(function () use ($order, $lines, $document, $actor) {
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReceive()) {
                throw new PurchasingException("\"{$locked->status->label()}\" durumundaki bir sipariş teslim alınamaz; önce sipariş verilmiş olmalı.");
            }

            $receipt = $locked->receipts()->create([
                'warehouse_id' => $locked->warehouse_id,
                'invoice_number' => $document['invoice_number'] ?? null,
                'delivery_note_number' => $document['delivery_note_number'] ?? null,
                'document_path' => $document['document_path'] ?? null,
                'document_name' => $document['document_name'] ?? null,
                'received_by' => $actor->id,
            ]);

            foreach ($lines as $lineId => $input) {
                $line = PurchaseOrderLine::where('purchase_order_id', $locked->id)->whereKey($lineId)->lockForUpdate()->first();

                if (! $line) {
                    throw new PurchasingException('Teslim alınan kalem bu siparişe ait değil.');
                }

                $quantity = (float) $input['quantity'];

                if ($quantity > $line->remaining() + self::EPSILON) {
                    throw new PurchasingException("{$line->product->name}: kalan sipariş miktarından ({$line->remaining()}) fazlası teslim alınamaz.");
                }

                $unitCost = filled($input['unit_cost'] ?? null) ? (float) $input['unit_cost'] : (float) $line->unit_price;

                $movement = $this->stock->in(
                    $line->product,
                    $locked->warehouse,
                    $quantity,
                    [
                        'lot_no' => ($input['lot_no'] ?? null) ?: null,
                        'expiry_date' => ($input['expiry_date'] ?? null) ?: null,
                        'unit_cost' => $unitCost,
                    ],
                    $actor,
                    "{$locked->number()} teslim alımı",
                    $receipt,
                );

                $receipt->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'quantity' => $quantity,
                    'lot_no' => ($input['lot_no'] ?? null) ?: null,
                    'expiry_date' => ($input['expiry_date'] ?? null) ?: null,
                    'unit_cost' => $unitCost,
                    'stock_movement_id' => $movement->id,
                ]);

                $line->update(['received_quantity' => (float) $line->received_quantity + $quantity]);
            }

            $fullyReceived = $locked->lines()->get()->every(fn (PurchaseOrderLine $line) => $line->remaining() <= self::EPSILON);
            $next = $fullyReceived ? PurchaseOrderStatus::Completed : PurchaseOrderStatus::PartiallyReceived;

            $locked->update(['status' => $next]);
            $locked->events()->create([
                'status' => $next,
                'actor_id' => $actor->id,
                'note' => collect([
                    filled($receipt->invoice_number) ? "Fatura: {$receipt->invoice_number}" : null,
                    filled($receipt->delivery_note_number) ? "İrsaliye: {$receipt->delivery_note_number}" : null,
                ])->filter()->join(', ') ?: null,
            ]);

            $order->refresh();

            return $receipt;
        });
    }

    private function transition(PurchaseOrder $order, PurchaseOrderStatus $next, User $actor, ?string $note = null, ?Closure $effect = null): PurchaseOrder
    {
        $order->loadMissing('warehouse');

        $allowed = in_array($next, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Rejected], true)
            ? $this->permissions->canApprove($actor)
            : $this->permissions->canManage($actor, $order->warehouse);

        if (! $allowed) {
            throw new AuthorizationException('Bu satın alma kaydı üzerinde bu işlemi yapma yetkiniz yok.');
        }

        DB::transaction(function () use ($order, $next, $actor, $note, $effect) {
            $locked = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($next)) {
                throw new PurchasingException("\"{$locked->status->label()}\" durumundaki bir kayıt \"{$next->label()}\" durumuna geçirilemez.");
            }

            if ($effect) {
                $effect($locked);
            }

            $locked->status = $next;
            $locked->save();
            $locked->events()->create(['status' => $next, 'actor_id' => $actor->id, 'note' => $note]);
        });

        $this->notifier->statusChanged($order->refresh(), $next, $actor, $note);

        return $order;
    }

    private function ensureCanManage(User $actor, Warehouse $warehouse): void
    {
        if (! $this->permissions->canManage($actor, $warehouse)) {
            throw new AuthorizationException('Bu depo için satın alma işlemi yapma yetkiniz yok.');
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float|int|string, unit_price?: float|int|string|null}>  $lines
     */
    private function ensureOrderable(Supplier $supplier, Warehouse $warehouse, array $lines): void
    {
        if ($supplier->status !== 'active') {
            throw new PurchasingException('Pasif bir tedarikçiye sipariş verilemez.');
        }

        if (! $warehouse->isOperational()) {
            throw new PurchasingException('Pasif bir depo veya şube için satın alma talebi açılamaz.');
        }

        if ($lines === []) {
            throw new PurchasingException('Talepte en az bir ürün kalemi olmalı.');
        }

        foreach ($lines as $line) {
            if ((float) $line['quantity'] <= 0) {
                throw new PurchasingException('Kalem miktarları sıfırdan büyük olmalı.');
            }
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float|int|string, unit_price?: float|int|string|null}>  $lines
     */
    private function writeLines(PurchaseOrder $order, array $lines): void
    {
        foreach ($lines as $line) {
            $product = Product::where('status', 'active')->findOrFail($line['product_id']);

            $order->lines()->create([
                'product_id' => $product->id,
                'quantity' => (float) $line['quantity'],
                'unit_price' => (float) ($line['unit_price'] ?? 0),
            ]);
        }
    }
}
