<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Access\Support\Module;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Exceptions\StockCountException;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountLine;
use App\Domain\Inventory\Notifications\StockCountNotifier;
use App\Domain\Inventory\Support\CountDifferenceReason;
use App\Domain\Inventory\Support\StockCountPermissions;
use App\Domain\Inventory\Support\StockCountStatus;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\ScanException;
use App\Domain\Stock\Exceptions\SerialException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Services\ScanResolver;
use App\Domain\Stock\Services\SerialRegistry;
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

    private const LOCKED_MESSAGE = 'Kör sayımda farklar gösterildi; sayılan miktarlar artık değiştirilemez. Yeniden saymak için Yönetici sayımı geri göndermeli.';

    public function __construct(
        private readonly StockMovementService $stock,
        private readonly StockCountPermissions $permissions,
        private readonly StockCountNotifier $notifier,
        private readonly ScanResolver $scanner,
    ) {}

    /**
     * Deponun miktarı sıfırdan büyük tüm lotlarını, o anki sistem miktarıyla satır yapar.
     *
     * @param  bool  $blind  Aşama 58 kör sayım: sayan, "Sayımı Bitir"e kadar sistem miktarını görmez.
     */
    public function start(Warehouse $warehouse, User $actor, ?string $note = null, bool $blind = false): StockCount
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

        return DB::transaction(function () use ($warehouse, $actor, $note, $blind) {
            $count = StockCount::create([
                'organization_id' => $actor->organization_id,
                'warehouse_id' => $warehouse->id,
                'status' => StockCountStatus::Counting,
                'note' => $note,
                'blind' => $blind,
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
     * Aşama 40: okutulan barkod/QR sayımın ilgili satırını bir birim artırır.
     * Satır sırasıyla lot etiketinden, GS1 kodundaki lot numarasından ya da
     * ürünün sayımdaki tek satırından bulunur; ürünün birden çok lotu varsa
     * tahmin yapılmaz. Seri takipli üründe okutulan seri bulunan seriler
     * listesine eklenir ve sayılan miktar liste uzunluğudur.
     *
     * @return array{line: StockCountLine, counted: float}
     */
    public function scan(StockCount $count, string $code, User $actor): array
    {
        $this->ensureCanCount($actor, $count);

        try {
            $scanned = $this->scanner->resolve($code, $actor->accessibleBranchIds(Module::StockMovement));
        } catch (ScanException $e) {
            throw new StockCountException($e->getMessage());
        }

        return DB::transaction(function () use ($count, $scanned) {
            $locked = $this->lockForCounting($count, editsQuantities: true);
            $line = $this->lineFor($locked, $scanned['product'], $scanned['lot'], $scanned['gs1']['lot_no'] ?? null);
            $line = StockCountLine::whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($line->product->tracks_serials) {
                $serial = $scanned['gs1']['serial'] ?? null;

                if (blank($serial)) {
                    throw new StockCountException("{$line->product->name} seri takipli: seri numarası içeren kareyi (GS1 DataMatrix) okutun ya da seriyi satıra yazın.");
                }

                $serials = $line->counted_serials ?? [];

                if (in_array($serial, $serials, true)) {
                    throw new StockCountException("{$this->lineLabel($line)}: {$serial} zaten sayıldı.");
                }

                $serials[] = $serial;
                $line->update(['counted_serials' => $serials, 'counted_quantity' => count($serials)]);
            } else {
                $line->update(['counted_quantity' => (float) $line->counted_quantity + 1]);
            }

            return ['line' => $line, 'counted' => (float) $line->counted_quantity];
        });
    }

    /**
     * Son okutmayı geri alır: bir birim düşer (sıfırda "sayılmadı"ya döner);
     * seri takipli satırda verilen seri listeden çıkar.
     */
    public function undoScan(StockCount $count, int $lineId, ?string $serial, User $actor): StockCountLine
    {
        $this->ensureCanCount($actor, $count);

        return DB::transaction(function () use ($count, $lineId, $serial) {
            $locked = $this->lockForCounting($count, editsQuantities: true);
            $line = $locked->lines()->whereKey($lineId)->lockForUpdate()->first()
                ?? throw new StockCountException('Girilen satır bu sayıma ait değil.');

            if ($line->product->tracks_serials) {
                $serials = array_values(array_diff($line->counted_serials ?? [], [$serial]));
                $line->update(['counted_serials' => $serials ?: null, 'counted_quantity' => $serials === [] ? null : count($serials)]);
            } else {
                $remaining = (float) $line->counted_quantity - 1;
                $line->update(['counted_quantity' => $remaining > self::EPSILON ? $remaining : null]);
            }

            return $line;
        });
    }

    /**
     * Aşama 58 — rafta bulunan ama sayım listesinde olmayan lotu sayıma ekler
     * (sayım başlarken stoğu 0 olan ya da depoda hiç kaydı olmayan lot). Satır
     * lotun o anki miktarıyla açılır; yeni lotta bu 0'dır ve sayılan miktar
     * onayda düzeltme hareketiyle stoğa girer.
     */
    public function addLine(StockCount $count, Product $product, ?string $lotNo, ?string $expiryDate, User $actor): StockCountLine
    {
        $this->ensureCanCount($actor, $count);

        if ($product->status !== 'active') {
            throw new StockCountException("{$product->name} pasif bir ürün; sayıma eklenemez.");
        }

        $lotNo = trim((string) $lotNo) ?: null;

        return DB::transaction(function () use ($count, $product, $lotNo, $expiryDate) {
            $locked = $this->lockForCounting($count, editsQuantities: true);
            $lot = $this->stock->openLotForCount($product, $locked->warehouse, $lotNo, $expiryDate);
            $lot = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $existing = $locked->lines()->where('stock_lot_id', $lot->id)->first();

            if ($existing) {
                throw new StockCountException("{$this->lineLabel($existing)} bu sayımda zaten var; miktarı o satıra girin.");
            }

            return $locked->lines()->create([
                'stock_lot_id' => $lot->id,
                'product_id' => $product->id,
                'system_quantity' => $lot->quantity,
                'added_during_count' => true,
            ]);
        });
    }

    /**
     * Aşama 58 — kör sayımda "Sayımı Bitir": her satır sayılmış olmalı; sistem
     * miktarları ve farklar görünür olur, sayılan miktarlar kilitlenir. Sayan
     * artık yalnızca fark nedenlerini girip onaya gönderir.
     */
    public function reveal(StockCount $count, User $actor): StockCount
    {
        $this->ensureCanCount($actor, $count);

        DB::transaction(function () use ($count) {
            $locked = $this->lockForCounting($count, editsQuantities: true);

            if (! $locked->blind) {
                throw new StockCountException('Bu sayım kör sayım değil; sistem miktarları zaten görünür.');
            }

            foreach ($locked->lines()->with('product', 'lot')->get() as $line) {
                $this->ensureLineCounted($line);
            }

            $locked->update(['blind_revealed_at' => now()]);
        });

        return $count->refresh();
    }

    /**
     * @param  bool  $editsQuantities  sayılan miktarı/satırları değiştiren işlem; kör sayımda farklar gösterildiyse reddedilir
     */
    private function lockForCounting(StockCount $count, bool $editsQuantities = false): StockCount
    {
        $locked = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== StockCountStatus::Counting) {
            throw new StockCountException('Yalnızca "Sayılıyor" durumundaki bir sayım düzenlenebilir.');
        }

        if ($editsQuantities && $locked->countsLocked()) {
            throw new StockCountException(self::LOCKED_MESSAGE);
        }

        return $locked;
    }

    private function ensureLineCounted(StockCountLine $line): void
    {
        $label = $this->lineLabel($line);

        if (! $line->isCounted()) {
            throw new StockCountException("{$label} henüz sayılmadı.");
        }

        if ($line->product->tracks_serials && $line->counted_serials === null) {
            throw new StockCountException("{$label}: seri takipli ürün; bulunan seri numaralarını girin.");
        }
    }

    private function lineFor(StockCount $count, Product $product, ?StockLot $lot, ?string $lotNo): StockCountLine
    {
        $lines = $count->lines()->with(['product', 'lot'])->where('product_id', $product->id)->get();

        if ($lot) {
            return $lines->firstWhere('stock_lot_id', $lot->id)
                ?? throw new StockCountException("{$product->name} / {$lot->lot_no} lotu bu sayımda yok (başka bir depoya ait olabilir).");
        }

        if (filled($lotNo)) {
            $lines = $lines->filter(fn (StockCountLine $line) => $line->lot?->lot_no === $lotNo);
        }

        return match ($lines->count()) {
            1 => $lines->first(),
            0 => throw new StockCountException("{$product->name} bu sayımda yok".(filled($lotNo) ? " ({$lotNo} lotu)" : '').'. Rafta bulduysanız "Listede olmayan lot ekle" ile sayıma ekleyin.'),
            default => throw new StockCountException("{$product->name} ürününün sayımda {$lines->count()} lotu var: lot etiketini okutun ya da miktarı satıra elle girin."),
        };
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
            $locked = $this->lockForCounting($count);

            foreach ($entries as $lineId => $entry) {
                $line = $locked->lines()->whereKey($lineId)->first();

                if (! $line) {
                    throw new StockCountException('Girilen satır bu sayıma ait değil.');
                }

                $counted = $entry['counted_quantity'] ?? null;
                $countedSerials = null;

                // Seri takipli lot (Aşama 26): fiilen bulunan serilerin listesi
                // girilir; sayılan miktar liste uzunluğudur.
                if ($line->product->tracks_serials && array_key_exists('counted_serials', $entry)) {
                    $raw = $entry['counted_serials'];

                    if ($raw === null || (is_string($raw) && trim($raw) === '' && ($counted === null || $counted === ''))) {
                        $counted = null;
                    } else {
                        try {
                            $countedSerials = is_array($raw) ? SerialRegistry::normalize($raw) : SerialRegistry::parseList($raw);
                        } catch (SerialException $e) {
                            throw new StockCountException("{$this->lineLabel($line)}: {$e->getMessage()}");
                        }

                        $counted = count($countedSerials);
                    }
                }

                if ($counted !== null && $counted !== '' && (float) $counted < 0) {
                    throw new StockCountException('Sayılan miktar negatif olamaz.');
                }

                $counted = ($counted === null || $counted === '') ? null : (float) $counted;

                // Kör sayımda farklar gösterildikten sonra yalnızca neden/açıklama değişir.
                if ($locked->countsLocked()) {
                    $sameSerials = ! $line->product->tracks_serials || ! array_key_exists('counted_serials', $entry)
                        || collect($countedSerials ?? [])->sort()->values()->all() === collect($line->counted_serials ?? [])->sort()->values()->all();

                    if (! $sameSerials || ($counted === null) !== ($line->counted_quantity === null) || abs((float) $counted - (float) $line->counted_quantity) > self::EPSILON) {
                        throw new StockCountException(self::LOCKED_MESSAGE);
                    }

                    $countedSerials = $line->counted_serials;
                }

                $line->update([
                    'counted_quantity' => $counted,
                    'counted_serials' => $countedSerials,
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
            if ($locked->hidesSystemQuantities()) {
                throw new StockCountException('Kör sayım: önce "Sayımı Bitir" ile farkları görün, nedenleri girip sonra onaya gönderin.');
            }

            foreach ($locked->lines()->with('product', 'lot')->get() as $line) {
                $label = $this->lineLabel($line);
                $this->ensureLineCounted($line);

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
     * Admin sayımı yeniden sayılmak üzere geri gönderir. Kör sayımda sistem
     * miktarları yeniden gizlenir ve sayılan miktarların kilidi açılır.
     */
    public function sendBack(StockCount $count, User $actor, ?string $note = null): StockCount
    {
        return $this->transition($count, StockCountStatus::Counting, $actor, $note, function (StockCount $locked) {
            $locked->update(['blind_revealed_at' => null]);
        });
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

                $movement = $this->stock->adjust($line->lot, (float) $line->counted_quantity, $reason, $actor, $locked, [
                    'serials' => $line->counted_serials ?? [],
                ]);
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
