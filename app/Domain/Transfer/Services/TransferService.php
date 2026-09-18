<?php

namespace App\Domain\Transfer\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Transfer\Exceptions\TransferException;
use App\Domain\Transfer\Models\TransferRequest;
use App\Domain\Transfer\Support\TransferPermissions;
use App\Domain\Transfer\Support\TransferStatus;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Şubeler/depolar arası transfer akışı (proje.md Bölüm 8). Stok yalnızca iki
 * anda değişir ve her zaman StockMovementService üzerinden (kurallar.md Bölüm 1):
 * "Gönderildi" → kaynaktan düşülür, "Teslim Alındı" → hedefe eklenir.
 * Gönderimden sonra iptal edilirse gönderim hareketleri ters kayıtla geri alınır.
 */
class TransferService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly TransferPermissions $permissions,
    ) {}

    public function request(Product $product, Warehouse $from, Warehouse $to, float $quantity, ?string $reason, User $actor): TransferRequest
    {
        if (! $this->permissions->canRequestInto($actor, $to)) {
            throw new AuthorizationException('Bu depo adına transfer talebi açma yetkiniz yok.');
        }

        if ($from->is($to)) {
            throw new TransferException('Kaynak ve hedef depo aynı olamaz.');
        }

        if ($quantity <= 0) {
            throw new TransferException('Transfer miktarı sıfırdan büyük olmalıdır.');
        }

        if (! $from->isOperational() || ! $to->isOperational()) {
            throw new TransferException('Pasif bir depo veya şube için transfer talebi açılamaz.');
        }

        return DB::transaction(function () use ($product, $from, $to, $quantity, $reason, $actor) {
            $transfer = TransferRequest::create([
                'organization_id' => $actor->organization_id,
                'product_id' => $product->id,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'quantity' => $quantity,
                'status' => TransferStatus::Pending,
                'reason' => $reason,
                'requested_by' => $actor->id,
            ]);

            $transfer->events()->create(['status' => TransferStatus::Pending, 'actor_id' => $actor->id, 'note' => $reason]);

            return $transfer;
        });
    }

    public function approve(TransferRequest $transfer, User $actor): TransferRequest
    {
        return $this->transition($transfer, TransferStatus::Approved, $actor, 'approve');
    }

    public function reject(TransferRequest $transfer, User $actor, ?string $note = null): TransferRequest
    {
        return $this->transition($transfer, TransferStatus::Rejected, $actor, 'reject', $note);
    }

    public function prepare(TransferRequest $transfer, User $actor): TransferRequest
    {
        return $this->transition($transfer, TransferStatus::Preparing, $actor, 'prepare');
    }

    public function ship(TransferRequest $transfer, User $actor): TransferRequest
    {
        return $this->transition($transfer, TransferStatus::Shipped, $actor, 'ship', effect: function (TransferRequest $locked) use ($actor) {
            $this->stock->transferOut($locked->product, $locked->fromWarehouse, (float) $locked->quantity, $locked, $actor);
        });
    }

    public function receive(TransferRequest $transfer, User $actor): TransferRequest
    {
        return $this->transition($transfer, TransferStatus::Received, $actor, 'receive', effect: function (TransferRequest $locked) use ($actor) {
            foreach ($this->shippedMovements($locked) as $shipped) {
                $this->stock->transferIn($shipped, $locked->toWarehouse, $locked, $actor);
            }
        });
    }

    public function cancel(TransferRequest $transfer, User $actor, ?string $note = null): TransferRequest
    {
        return $this->transition($transfer, TransferStatus::Cancelled, $actor, 'cancel', $note, function (TransferRequest $locked) use ($actor) {
            // kurallar.md Bölüm 1: gönderimden sonra iptal → düşülen miktar kaynağa geri eklenir.
            if ($locked->status === TransferStatus::Shipped) {
                foreach ($this->shippedMovements($locked) as $shipped) {
                    $this->stock->cancel($shipped, $actor);
                }
            }
        });
    }

    /**
     * Satır kilidiyle durumu yeniden okur: aynı transferin eşzamanlı iki kez
     * gönderilmesi / teslim alınması mümkün olmaz.
     */
    private function transition(
        TransferRequest $transfer,
        TransferStatus $next,
        User $actor,
        string $action,
        ?string $note = null,
        ?Closure $effect = null,
    ): TransferRequest {
        $transfer->loadMissing(['fromWarehouse', 'toWarehouse']);

        if (! $this->permissions->can($actor, $action, $transfer)) {
            throw new AuthorizationException('Bu transfer üzerinde bu işlemi yapma yetkiniz yok.');
        }

        DB::transaction(function () use ($transfer, $next, $actor, $note, $effect) {
            $locked = TransferRequest::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($next)) {
                throw new TransferException("\"{$locked->status->label()}\" durumundaki bir transfer \"{$next->label()}\" durumuna geçirilemez.");
            }

            if ($effect) {
                $effect($locked);
            }

            $locked->update(['status' => $next]);
            $locked->events()->create(['status' => $next, 'actor_id' => $actor->id, 'note' => $note]);
        });

        return $transfer->refresh();
    }

    /**
     * @return Collection<int, StockMovement>
     */
    private function shippedMovements(TransferRequest $transfer)
    {
        return $transfer->movements()
            ->where('type', StockMovementType::TransferOut->value)
            ->withoutCancelled()
            ->with('lot.product')
            ->orderBy('id')
            ->get();
    }
}
