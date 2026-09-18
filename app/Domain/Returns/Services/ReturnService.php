<?php

namespace App\Domain\Returns\Services;

use App\Domain\Catalog\Models\Supplier;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Support\ReturnPermissions;
use App\Domain\Returns\Support\ReturnReason;
use App\Domain\Returns\Support\ReturnStatus;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Tedarikçiye iade akışı (proje.md Bölüm 10): Talep Edildi → Onaylandı →
 * Kargoya Verildi → Tedarikçi Onayladı → Tamamlandı; Reddedildi.
 *
 * Stok yalnızca iki anda değişir ve her zaman StockMovementService üzerinden
 * (transfer kuralıyla aynı mantık, kurallar.md Bölüm 1): "Kargoya Verildi" →
 * lottan düşülür; tedarikçi kargolanan iadeyi reddederse ürün geri gelir ve
 * düşülen miktar ters kayıtla lota geri eklenir. Kargodan önce reddedilen bir
 * iadenin stok etkisi olmaz.
 */
class ReturnService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly ReturnPermissions $permissions,
    ) {}

    /**
     * @param  array{reason_note?: ?string, purchase_order_id?: ?int, invoice_number?: ?string}  $details
     */
    public function request(StockLot $lot, float $quantity, ReturnReason $reason, Supplier $supplier, User $actor, array $details = []): SupplierReturn
    {
        $lot->loadMissing(['warehouse', 'product']);

        if (! $this->permissions->canManage($actor, $lot->warehouse)) {
            throw new AuthorizationException('Bu depodan iade talebi açma yetkiniz yok.');
        }

        if (! $lot->warehouse->isOperational()) {
            throw new ReturnException('Pasif bir depo veya şubeden iade talebi açılamaz.');
        }

        if ($quantity <= 0) {
            throw new ReturnException('İade miktarı sıfırdan büyük olmalıdır.');
        }

        // Stok kargoya verilince düşer; yine de lotta olmayan bir miktar için talep açılmaz.
        if ($quantity > (float) $lot->quantity) {
            throw new ReturnException('İade miktarı lottaki mevcut miktardan ('.$this->format((float) $lot->quantity).') fazla olamaz.');
        }

        $reasonNote = trim((string) ($details['reason_note'] ?? '')) ?: null;

        if ($reason === ReturnReason::Other && $reasonNote === null) {
            throw new ReturnException('"Diğer" iade nedeni için açıklama yazın.');
        }

        $order = null;

        if (filled($details['purchase_order_id'] ?? null)) {
            $order = PurchaseOrder::with('lines')->find($details['purchase_order_id']);

            if (! $order) {
                throw new ReturnException('Seçilen sipariş bulunamadı.');
            }

            if ($order->supplier_id !== $supplier->id) {
                throw new ReturnException("{$order->number()} siparişi seçilen tedarikçiye ait değil.");
            }

            if (! $order->lines->contains(fn ($line) => $line->product_id === $lot->product_id && (float) $line->received_quantity > 0)) {
                throw new ReturnException("{$order->number()} siparişinden bu ürün teslim alınmamış.");
            }
        }

        return DB::transaction(function () use ($lot, $quantity, $reason, $reasonNote, $supplier, $order, $details, $actor) {
            $return = SupplierReturn::create([
                'organization_id' => $actor->organization_id,
                'stock_lot_id' => $lot->id,
                'product_id' => $lot->product_id,
                'warehouse_id' => $lot->warehouse_id,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $order?->id,
                'invoice_number' => trim((string) ($details['invoice_number'] ?? '')) ?: null,
                'quantity' => $quantity,
                'reason' => $reason,
                'reason_note' => $reasonNote,
                'status' => ReturnStatus::Requested,
                'requested_by' => $actor->id,
            ]);

            $return->events()->create(['status' => ReturnStatus::Requested, 'actor_id' => $actor->id, 'note' => $return->reasonText()]);

            return $return;
        });
    }

    public function approve(SupplierReturn $return, User $actor): SupplierReturn
    {
        return $this->transition($return, ReturnStatus::Approved, $actor, admin: true);
    }

    /**
     * Admin, kargoya verilmemiş bir iade talebini reddeder — stok etkisi yok.
     */
    public function reject(SupplierReturn $return, User $actor, ?string $note = null): SupplierReturn
    {
        return $this->transition($return, ReturnStatus::Rejected, $actor, $note, admin: true, effect: function (SupplierReturn $locked) {
            if ($locked->status === ReturnStatus::Shipped) {
                throw new ReturnException('Kargoya verilmiş bir iade ancak tedarikçi reddettiğinde reddedilebilir.');
            }
        });
    }

    /**
     * Ürün kargoya verilir: iade miktarı lottan düşülür (lot yetersizse engellenir).
     */
    public function ship(SupplierReturn $return, User $actor, ?string $note = null): SupplierReturn
    {
        return $this->transition($return, ReturnStatus::Shipped, $actor, $note, effect: function (SupplierReturn $locked) use ($actor) {
            $this->stock->returnOut($locked->lot, (float) $locked->quantity, $locked, $actor, "{$locked->number()} tedarikçiye iade: {$locked->reasonText()}");
        });
    }

    public function supplierApprove(SupplierReturn $return, User $actor, ?string $response = null): SupplierReturn
    {
        $response = trim((string) $response) ?: null;

        return $this->transition($return, ReturnStatus::SupplierApproved, $actor, $response, effect: function (SupplierReturn $locked) use ($response) {
            $locked->supplier_response = $response;
        });
    }

    /**
     * Tedarikçi kargolanan iadeyi kabul etmedi: ürün geri gelir, düşülen
     * miktar ters kayıtla lota geri eklenir. Tedarikçinin yanıtı zorunludur.
     */
    public function supplierReject(SupplierReturn $return, User $actor, ?string $response): SupplierReturn
    {
        $response = trim((string) $response);

        if ($response === '') {
            throw new ReturnException('Tedarikçinin ret gerekçesini yazın.');
        }

        return $this->transition($return, ReturnStatus::Rejected, $actor, "Tedarikçi reddetti: {$response}", effect: function (SupplierReturn $locked) use ($actor, $response) {
            if ($locked->status !== ReturnStatus::Shipped) {
                throw new ReturnException('Yalnızca kargoya verilmiş bir iadeyi tedarikçi reddedebilir.');
            }

            $shipped = $locked->movements()->where('type', StockMovementType::ReturnMovement->value)->withoutCancelled()->get();

            foreach ($shipped as $movement) {
                $this->stock->cancel($movement, $actor);
            }

            $locked->supplier_response = $response;
        });
    }

    /**
     * İade kapanır; tedarikçinin kredi notu (numara, tutar) varsa kaydedilir.
     */
    public function complete(SupplierReturn $return, User $actor, ?string $creditNoteNumber = null, ?float $creditAmount = null, ?string $note = null): SupplierReturn
    {
        if ($creditAmount !== null && $creditAmount < 0) {
            throw new ReturnException('Kredi notu tutarı negatif olamaz.');
        }

        return $this->transition($return, ReturnStatus::Completed, $actor, $note, effect: function (SupplierReturn $locked) use ($creditNoteNumber, $creditAmount) {
            $locked->credit_note_number = trim((string) $creditNoteNumber) ?: null;
            $locked->credit_amount = $creditAmount;
        });
    }

    /**
     * Satır kilidiyle durumu yeniden okur: aynı iade eşzamanlı iki kez
     * kargoya verilemez / reddedilemez.
     */
    private function transition(
        SupplierReturn $return,
        ReturnStatus $next,
        User $actor,
        ?string $note = null,
        bool $admin = false,
        ?Closure $effect = null,
    ): SupplierReturn {
        $return->loadMissing('warehouse');

        $allowed = $admin ? $this->permissions->canApprove($actor) : $this->permissions->canManage($actor, $return->warehouse);

        if (! $allowed) {
            throw new AuthorizationException('Bu iade üzerinde bu işlemi yapma yetkiniz yok.');
        }

        DB::transaction(function () use ($return, $next, $actor, $note, $effect) {
            $locked = SupplierReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($next)) {
                throw new ReturnException("\"{$locked->status->label()}\" durumundaki bir iade \"{$next->label()}\" durumuna geçirilemez.");
            }

            if ($effect) {
                $effect($locked);
            }

            $locked->status = $next;
            $locked->save();
            $locked->events()->create(['status' => $next, 'actor_id' => $actor->id, 'note' => trim((string) $note) ?: null]);
        });

        return $return->refresh();
    }

    private function format(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',');
    }
}
