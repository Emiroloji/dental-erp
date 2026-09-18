<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\StockCountException;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountLine;
use App\Domain\Inventory\Notifications\StockCountNotifier;
use App\Domain\Inventory\Support\CountDifferenceReason;
use App\Domain\Inventory\Support\StockCountPermissions;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\StockMovementService;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Stok sayımı (proje.md Bölüm 10): başlat → say → onaya gönder → Admin onayı.
 * Onaylanan her fark, doğrudan veri güncellemesi değil StockMovementService
 * ::adjust() ile iz bırakan bir düzeltme hareketi olarak işlenir (kurallar.md
 * Bölüm 1) ve lot bazında önceki/yeni miktar denetim kaydına yazılır.
 */
class StockCountService
{
    private const EPSILON = 0.0001;

    public function __construct(
        private readonly StockMovementService $stock,
        private readonly StockCountPermissions $permissions,
        private readonly StockCountNotifier $notifier,
    ) {}

    /**
     * Deponun miktarı sıfırdan büyük tüm lotlarını, o anki sistem miktarıyla satır yapar.
     */
    public function start(Warehouse $warehouse, User $actor, ?string $note = null): StockCount
    {
        if (! $this->permissions->canCount($actor, $warehouse)) {
            throw new AuthorizationException('Bu depoda sayım başlatma yetkiniz yok.');
        }

        if (! $warehouse->isOperational()) {
            throw new StockCountException('Pasif bir depo veya şubede sayım başlatılamaz.');
        }

        $open = StockCount::where('warehouse_id', $warehouse->id)
            ->whereIn('status', [StockCountStatus::Counting, StockCountStatus::PendingApproval])
            ->first();

        if ($open) {
            throw new StockCountException("Bu depoda zaten açık bir sayım var ({$open->number()}); önce onu tamamlayın veya iptal edin.");
        }

        return DB::transaction(function () use ($warehouse, $actor, $note) {
            $count = StockCount::create([
                'organization_id' => $actor->organization_id,
                'warehouse_id' => $warehouse->id,
                'status' => StockCountStatus::Counting,
                'note' => $note,
                'started_by' => $actor->id,
            ]);

            $lots = StockLot::where('warehouse_id', $warehouse->id)
                ->where('quantity', '>', 0)
                ->whereHas('product')
                ->get();

            if ($lots->isEmpty()) {
                throw new StockCountException('Bu depoda sayılacak stok yok.');
            }

            foreach ($lots as $lot) {
                $count->lines()->create([
                    'stock_lot_id' => $lot->id,
                    'product_id' => $lot->product_id,
                    'system_quantity' => $lot->quantity,
                ]);
            }

            $count->events()->create(['status' => StockCountStatus::Counting, 'actor_id' => $actor->id, 'note' => $note]);

            return $count;
        });
    }

    /**
     * Sayım girişlerini kaydeder (kısmen de olabilir; onaya gönderirken hepsi zorunlu).
     *
     * @param  array<int, array{counted_quantity?: float|int|string|null, reason?: ?string, note?: ?string}>  $entries  satır id => giriş
     */
    public function record(StockCount $count, array $entries, User $actor): StockCount
    {
        $this->ensureCanCount($actor, $count);

        DB::transaction(function () use ($count, $entries) {
            $locked = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockCountStatus::Counting) {
                throw new StockCountException('Yalnızca "Sayılıyor" durumundaki bir sayım düzenlenebilir.');
            }

            foreach ($entries as $lineId => $entry) {
                $line = $locked->lines()->whereKey($lineId)->first();

                if (! $line) {
                    throw new StockCountException('Girilen satır bu sayıma ait değil.');
                }

                $counted = $entry['counted_quantity'] ?? null;

                if ($counted !== null && $counted !== '' && (float) $counted < 0) {
                    throw new StockCountException('Sayılan miktar negatif olamaz.');
                }

                $line->update([
                    'counted_quantity' => ($counted === null || $counted === '') ? null : (float) $counted,
                    'reason' => ($entry['reason'] ?? null) ?: null,
                    'note' => ($entry['note'] ?? null) ?: null,
                ]);
            }
        });

        return $count->refresh();
    }

    /**
     * Onaya göndermeden önce: her satır sayılmış olmalı; farkı olan her
     * satırın nedeni olmalı, "Diğer" nedeninde açıklama zorunlu (kurallar.md
     * Bölüm 1: nedensiz düzeltme kaydedilemez).
     */
    public function submit(StockCount $count, User $actor): StockCount
    {
        return $this->transition($count, StockCountStatus::PendingApproval, $actor, effect: function (StockCount $locked) {
            foreach ($locked->lines()->with('product', 'lot')->get() as $line) {
                $label = $this->lineLabel($line);

                if (! $line->isCounted()) {
                    throw new StockCountException("{$label} henüz sayılmadı.");
                }

                if ($line->hasDifference() && $line->reason === null) {
                    throw new StockCountException("{$label}: fark için bir neden seçin.");
                }

                if ($line->hasDifference() && $line->reason === CountDifferenceReason::Other && blank($line->note)) {
                    throw new StockCountException("{$label}: \"Diğer\" nedeni için açıklama yazın.");
                }
            }
        });
    }

    /**
     * Admin sayımı yeniden sayılmak üzere geri gönderir.
     */
    public function sendBack(StockCount $count, User $actor, ?string $note = null): StockCount
    {
        return $this->transition($count, StockCountStatus::Counting, $actor, $note);
    }

    public function cancel(StockCount $count, User $actor, ?string $note = null): StockCount
    {
        return $this->transition($count, StockCountStatus::Cancelled, $actor, $note);
    }

    /**
     * Sayım sürerken depoda hareket olduysa sistem miktarlarını günceller.
     * Sayılan miktarlar korunur; farklar yeni sistem miktarına göre yeniden
     * hesaplanır. Yalnızca "Sayılıyor" durumunda.
     */
    public function refreshSystemQuantities(StockCount $count, User $actor): StockCount
    {
        $this->ensureCanCount($actor, $count);

        DB::transaction(function () use ($count) {
            $locked = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockCountStatus::Counting) {
                throw new StockCountException('Sistem miktarları yalnızca "Sayılıyor" durumunda yenilenebilir.');
            }

            foreach ($locked->lines()->with('lot')->get() as $line) {
                $line->update(['system_quantity' => $line->lot->quantity]);
            }
        });

        return $count->refresh();
    }

    /**
     * Onay: her lotun miktarı hâlâ sayımın sistem miktarına eşit olmalı —
     * sayım sırasında araya giren bir hareket (giriş, çıkış, transfer)
     * düzeltmeyle ezilmesin. Farklar adjust() ile düzeltme hareketine dönüşür.
     */
    public function approve(StockCount $count, User $actor): StockCount
    {
        return $this->transition($count, StockCountStatus::Approved, $actor, effect: function (StockCount $locked) use ($actor) {
            $lines = $locked->lines()->with(['product', 'lot'])->get();

            $changed = $lines->filter(fn (StockCountLine $line) => abs((float) $line->lot->quantity - (float) $line->system_quantity) > self::EPSILON);

            if ($changed->isNotEmpty()) {
                throw new StockCountException(
                    'Sayım başladıktan sonra şu lotlarda stok hareketi oldu: '
                    .$changed->map(fn (StockCountLine $line) => $this->lineLabel($line))->join(', ')
                    .'. Sayımı geri gönderin; sistem miktarları yenilenip ilgili satırlar yeniden kontrol edilmeli.'
                );
            }

            $before = [];
            $after = [];

            foreach ($lines->filter->hasDifference() as $line) {
                $reason = "{$locked->number()} sayım farkı: {$line->reason->label()}".(filled($line->note) ? " — {$line->note}" : '');

                $movement = $this->stock->adjust($line->lot, (float) $line->counted_quantity, $reason, $actor, $locked);
                $line->update(['stock_movement_id' => $movement->id]);

                $label = $this->lineLabel($line);
                $before[$label] = (float) $line->system_quantity;
                $after[$label] = (float) $line->counted_quantity;
            }

            if ($before !== []) {
                $locked->recordAuditChange(['stok' => $before], ['stok' => $after]);
            }
        });
    }

    private function transition(StockCount $count, StockCountStatus $next, User $actor, ?string $note = null, ?Closure $effect = null): StockCount
    {
        $count->loadMissing('warehouse');

        // Onaylamak ve yeniden sayıma geri göndermek Admin'in; diğerleri sayan tarafın.
        $allowed = in_array($next, [StockCountStatus::Approved, StockCountStatus::Counting], true)
            ? $this->permissions->canApprove($actor)
            : $this->permissions->canCount($actor, $count->warehouse);

        if (! $allowed) {
            throw new AuthorizationException('Bu sayım üzerinde bu işlemi yapma yetkiniz yok.');
        }

        DB::transaction(function () use ($count, $next, $actor, $note, $effect) {
            $locked = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($next)) {
                throw new StockCountException("\"{$locked->status->label()}\" durumundaki bir sayım \"{$next->label()}\" durumuna geçirilemez.");
            }

            if ($effect) {
                $effect($locked);
            }

            $locked->update(['status' => $next]);
            $locked->events()->create(['status' => $next, 'actor_id' => $actor->id, 'note' => $note]);
        });

        $this->notifier->statusChanged($count->refresh(), $next, $actor, $note);

        return $count;
    }

    private function ensureCanCount(User $actor, StockCount $count): void
    {
        $count->loadMissing('warehouse');

        if (! $this->permissions->canCount($actor, $count->warehouse)) {
            throw new AuthorizationException('Bu sayım üzerinde işlem yapma yetkiniz yok.');
        }
    }

    private function lineLabel(StockCountLine $line): string
    {
        return $line->product->name.' / '.($line->lot->lot_no ?? 'Lot #'.$line->lot->id);
    }
}
