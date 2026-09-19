<?php

namespace App\Domain\Stock\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Support\ExpiredLotPolicy;
use App\Domain\Reporting\Services\DashboardMetricsService;
use App\Domain\Stock\Exceptions\ColdChainException;
use App\Domain\Stock\Exceptions\ControlledProductException;
use App\Domain\Stock\Exceptions\ExpiredLotBlockedException;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Exceptions\InsufficientStockException;
use App\Domain\Stock\Exceptions\SerialException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Notifications\ColdChainNotifier;
use App\Domain\Stock\Support\SerialStatus;
use App\Domain\Stock\Support\StockMovementType;
use App\Domain\Stock\Support\StockOutReason;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockMovementService
{
    public function __construct(
        private readonly DashboardMetricsService $dashboardMetrics,
        private readonly ColdChainNotifier $coldChain,
        private readonly SerialRegistry $serials,
    ) {}

    /**
     * @param  array{lot_no?: ?string, expiry_date?: ?string, unit_cost?: ?float}  $lotAttributes
     * @param  Model|null  $related  Girişi doğuran kayıt (ör. satın alma teslim alımı)
     * @param  array{temperature?: float|string|null, temperature_note?: ?string, serials?: array<int, string>}  $tracking  İlaç/medikal takip bilgisi (Aşama 26)
     */
    public function in(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        array $lotAttributes = [],
        ?User $actor = null,
        ?string $reason = null,
        ?Model $related = null,
        array $tracking = [],
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Giriş miktarı sıfırdan büyük olmalıdır.');
        }

        $this->ensureOperational($warehouse);
        [$temperature, $temperatureNote, $outOfRange] = $this->coldChainReading($product, $tracking);
        $serials = $this->serialsFor($product, $quantity, $tracking);

        $movement = DB::transaction(function () use ($product, $warehouse, $quantity, $lotAttributes, $actor, $reason, $related, $temperature, $temperatureNote, $serials) {
            $lot = $this->resolveOrCreateLot($product, $warehouse, $lotAttributes);
            $lot = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $lot->update(['quantity' => $lot->quantity + $quantity]);

            $movement = $this->recordMovement(
                StockMovementType::In, $lot, $quantity, $actor, $reason,
                relatedEntityType: $related?->getMorphClass(), relatedEntityId: $related?->getKey(),
                temperature: $temperature, temperatureNote: $temperatureNote,
            );

            if ($serials !== null) {
                $this->serials->receive($product, $lot, $serials, $movement);
            }

            return $movement;
        });

        if ($outOfRange) {
            $this->coldChain->outOfRangeAccepted($movement, $actor);
        }

        return $movement;
    }

    /**
     * Soğuk zincir kuralı (Aşama 26): girişte ölçülen sıcaklık zorunludur;
     * saklama aralığı dışındaysa giriş ancak yazılı gerekçeyle kabul edilir.
     *
     * @param  array{temperature?: float|string|null, temperature_note?: ?string}  $tracking
     * @return array{0: ?float, 1: ?string, 2: bool}
     */
    private function coldChainReading(Product $product, array $tracking): array
    {
        $raw = $tracking['temperature'] ?? null;
        $temperature = $raw === null || $raw === '' ? null : (float) $raw;
        $note = trim((string) ($tracking['temperature_note'] ?? '')) ?: null;

        if (! $product->cold_chain) {
            return [$temperature, $note, false];
        }

        if ($temperature === null) {
            throw new ColdChainException("{$product->name} soğuk zincir ürünü; girişte ölçülen sıcaklık zorunludur.");
        }

        if ($product->temperatureInRange($temperature)) {
            return [$temperature, $note, false];
        }

        if ($note === null) {
            throw new ColdChainException("{$product->name}: ölçülen sıcaklık saklama aralığı ({$product->storageRangeLabel()}) dışında. Giriş reddedildi; kabul edilecekse gerekçe yazın.");
        }

        return [$temperature, $note, true];
    }

    /**
     * @param  array{note?: ?string, serials?: array<int, string>}  $tracking  İlaç/medikal takip bilgisi (Aşama 26): kontrollü üründe çıkış açıklaması, seri takipli üründe çıkan seriler
     * @return Collection<int, StockMovement>
     */
    public function out(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        ?StockLot $lot = null,
        ?User $actor = null,
        ?string $reason = null,
        ?StockOutReason $reasonCode = null,
        array $tracking = [],
    ): Collection {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Çıkış miktarı sıfırdan büyük olmalıdır.');
        }

        // Kontrollü ürün (Aşama 26): her çıkış bir açıklamayla kayda geçer.
        if ($product->is_controlled && blank(trim((string) ($tracking['note'] ?? '')))) {
            throw new ControlledProductException("{$product->name} kontrollü bir ürün; çıkışta açıklama zorunludur.");
        }

        // Açıklama her zaman hareket kaydına geçer (çağıran nedene eklememişse eklenir).
        $note = trim((string) ($tracking['note'] ?? ''));
        if ($note !== '' && ! str_contains((string) $reason, $note)) {
            $reason = filled($reason) ? "{$reason} — {$note}" : $note;
        }

        $this->ensureOperational($warehouse);

        // "Kullanımı tamamen engelle" ayarı (proje.md Bölüm 7): kullanım
        // çıkışında SKT'si geçmiş lot kullanılamaz; imha çıkışları serbesttir.
        $blockExpired = ! ($reasonCode?->disposesStock() ?? false)
            && $this->expiredLotPolicy($warehouse) === ExpiredLotPolicy::Block;

        // Seri takipli ürün: çıkan birimler seri numarasıyla seçilir; lotlar
        // serilerden gelir (FEFO uygulanmaz).
        $serials = $this->serialsFor($product, $quantity, $tracking);

        if ($serials !== null) {
            return DB::transaction(function () use ($product, $warehouse, $lot, $actor, $reason, $reasonCode, $blockExpired, $serials) {
                $selected = $this->serials->selectInStock($product, $warehouse, $serials, $lot);

                if ($blockExpired && ($expired = $selected->first(fn ($serial) => $serial->lot->isExpired()))) {
                    throw new ExpiredLotBlockedException("{$expired->serial_no} seri numaralı birimin lotunun son kullanma tarihi geçmiş; organizasyon ayarına göre kullanımı engelli.");
                }

                return $selected->groupBy('lot_id')->map(function ($group) use ($actor, $reason, $reasonCode) {
                    $movement = $this->withdrawFromLot($group->first()->lot, $group->count(), $actor, $reason, $reasonCode);
                    $this->serials->transition($group, $movement, SerialStatus::Out);

                    return $movement;
                })->values();
            });
        }

        return DB::transaction(function () use ($product, $warehouse, $quantity, $lot, $actor, $reason, $reasonCode, $blockExpired) {
            if ($lot) {
                if ($blockExpired && $lot->isExpired()) {
                    throw new ExpiredLotBlockedException('Seçilen lotun son kullanma tarihi geçmiş; organizasyon ayarına göre kullanımı engelli. İmha için "SKT geçmiş" nedeniyle çıkış yapın.');
                }

                return collect([$this->withdrawFromLot($lot, $quantity, $actor, $reason, $reasonCode)]);
            }

            return $this->withdrawFefo($product, $warehouse, $quantity, $actor, $reason, $reasonCode, excludeExpired: $blockExpired);
        });
    }

    /**
     * Transfer gönderimi (kurallar.md Bölüm 1: "Gönderildi" anında kaynaktan
     * düşülür). Lotlar FEFO ile seçilir; her hareket transfer kaydına bağlanır.
     *
     * @return Collection<int, StockMovement>
     */
    public function transferOut(Product $product, Warehouse $warehouse, float $quantity, Model $transfer, ?User $actor = null): Collection
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Transfer miktarı sıfırdan büyük olmalıdır.');
        }

        $this->ensureOperational($warehouse);

        return DB::transaction(function () use ($product, $warehouse, $quantity, $transfer, $actor) {
            $movements = $this->withdrawFefo(
                $product, $warehouse, $quantity, $actor, "Transfer #{$transfer->getKey()} gönderimi", null,
                StockMovementType::TransferOut, $transfer,
            );

            // Seri takipli ürün: her lottan gönderilen kadar seri "yolda" olur.
            if ($product->tracks_serials) {
                foreach ($movements as $movement) {
                    $this->serials->transition($this->serials->pick($movement->lot, (int) round(abs((float) $movement->quantity))), $movement, SerialStatus::InTransit);
                }
            }

            return $movements;
        });
    }

    /**
     * Transfer teslim alımı: gönderilen lotun numarası, SKT'si ve alış fiyatı
     * hedef depoda aynen korunur — lot takibi transferde kopmaz.
     */
    public function transferIn(StockMovement $shipped, Warehouse $warehouse, Model $transfer, ?User $actor = null): StockMovement
    {
        $this->ensureOperational($warehouse);

        return DB::transaction(function () use ($shipped, $warehouse, $transfer, $actor) {
            $sourceLot = $shipped->lot;
            $quantity = abs((float) $shipped->quantity);

            $lot = $this->resolveOrCreateLot($sourceLot->product, $warehouse, [
                'lot_no' => $sourceLot->lot_no,
                'expiry_date' => $sourceLot->expiry_date?->toDateString(),
                'unit_cost' => (float) $sourceLot->unit_cost,
            ]);
            $lot = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $lot->update(['quantity' => $lot->quantity + $quantity]);

            $movement = $this->recordMovement(
                StockMovementType::TransferIn, $lot, $quantity, $actor, "Transfer #{$transfer->getKey()} teslim alımı",
                relatedEntityType: $transfer->getMorphClass(), relatedEntityId: $transfer->getKey(),
            );

            // Yoldaki seriler hedef lota geçer.
            $inTransit = $shipped->serials()->where('status', SerialStatus::InTransit->value)->lockForUpdate()->get();

            if ($inTransit->isNotEmpty()) {
                $this->serials->transition($inTransit, $movement, SerialStatus::InStock, $lot);
            }

            return $movement;
        });
    }

    /**
     * Tedarikçiye iade (proje.md Bölüm 10: "ayrı bir hareket türü"). İade
     * belirli bir lottan yapılır — FEFO uygulanmaz; SKT'si geçmiş lot da iade
     * edilebilir. Hareket iade kaydına bağlıdır; tedarikçi reddederse cancel()
     * ile ters kayıt yazılır.
     */
    /**
     * @param  array{serials?: array<int, string>}  $tracking  Seri takipli üründe iade edilen seriler (Aşama 26)
     */
    public function returnOut(StockLot $lot, float $quantity, Model $return, ?User $actor = null, ?string $reason = null, array $tracking = []): StockMovement
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('İade miktarı sıfırdan büyük olmalıdır.');
        }

        $this->ensureOperational($lot->warehouse);
        $serials = $this->serialsFor($lot->product, $quantity, $tracking);

        return DB::transaction(function () use ($lot, $quantity, $return, $actor, $reason, $serials) {
            $selected = $serials !== null ? $this->serials->selectInStock($lot->product, $lot->warehouse, $serials, $lot) : null;

            $movement = $this->withdrawFromLot(
                $lot, $quantity, $actor, $reason, StockOutReason::ReturnToSupplier,
                StockMovementType::ReturnMovement, $return,
            );

            if ($selected !== null) {
                $this->serials->transition($selected, $movement, SerialStatus::Out);
            }

            return $movement;
        });
    }

    /**
     * @param  Model|null  $related  Düzeltmeyi doğuran kayıt (ör. onaylanan stok sayımı)
     * @param  array{serials?: array<int, string>}  $tracking  Seri takipli üründe lotta fiilen bulunan serilerin tam listesi (Aşama 26)
     */
    public function adjust(StockLot $lot, float $countedQuantity, string $reason, ?User $actor = null, ?Model $related = null, array $tracking = []): StockMovement
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Stok düzeltmesi bir neden olmadan yapılamaz.');
        }

        if ($countedQuantity < 0) {
            throw new InvalidArgumentException('Sayılan miktar negatif olamaz.');
        }

        $this->ensureOperational($lot->warehouse);

        $serials = $this->serialsFor($lot->product, $countedQuantity, $tracking, allowZero: true);

        return DB::transaction(function () use ($lot, $countedQuantity, $reason, $actor, $related, $serials) {
            $locked = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $delta = $countedQuantity - (float) $locked->quantity;

            $locked->update(['quantity' => $countedQuantity]);

            $movement = $this->recordMovement(
                StockMovementType::CountAdjust, $locked, $delta, $actor, $reason,
                relatedEntityType: $related?->getMorphClass(), relatedEntityId: $related?->getKey(),
            );

            // Seri takipli: sayılan liste ile stoktaki seriler eşitlenir; net fark
            // miktar farkıyla tutmalıdır (lot miktarı = stoktaki seri sayısı).
            if ($serials !== null && abs($this->serials->reconcile($locked, $serials, $movement) - $delta) > 0.00001) {
                throw new SerialException("Lot {$locked->lot_no}: sayılan seri listesi ile kayıtlı seriler uyuşmuyor.");
            }

            return $movement;
        });
    }

    public function cancel(StockMovement $movement, ?User $actor = null): StockMovement
    {
        $this->ensureOperational($movement->warehouse);

        return DB::transaction(function () use ($movement, $actor) {
            $lot = StockLot::whereKey($movement->lot_id)->lockForUpdate()->firstOrFail();

            $reverseDelta = -1 * (float) $movement->quantity;
            $newQuantity = (float) $lot->quantity + $reverseDelta;

            if ($newQuantity < 0) {
                throw new InsufficientStockException('Bu hareket iptal edilirse stok negatife düşer.');
            }

            $lot->update(['quantity' => $newQuantity]);

            $cancellation = $this->recordMovement(
                StockMovementType::Cancel,
                $lot,
                $reverseDelta,
                $actor,
                "İptal: #{$movement->id} numaralı hareket",
                relatedEntityType: StockMovement::class,
                relatedEntityId: $movement->id,
            );

            // Seri takipli: hareketin taşıdığı seriler eski durumuna döner.
            $this->serials->reverse($movement, $cancellation);

            return $cancellation;
        });
    }

    /**
     * kurallar.md Bölüm 4: pasif depo veya pasif şubedeki bir depo üzerinde
     * hiçbir stok işlemi (giriş, çıkış, düzeltme, iptal) yapılamaz.
     */
    /**
     * Seri takipli ürün için seri listesi (doğrulanmış); diğer ürünlerde null.
     *
     * @param  array{serials?: array<int, string>}  $tracking
     * @return array<int, string>|null
     */
    private function serialsFor(Product $product, float $quantity, array $tracking, bool $allowZero = false): ?array
    {
        if (! $product->tracks_serials) {
            return null;
        }

        $serials = SerialRegistry::normalize($tracking['serials'] ?? []);

        if (! $allowZero || $quantity > 0 || $serials !== []) {
            SerialRegistry::ensureMatchesQuantity($product, $quantity, $serials);
        }

        return $serials;
    }

    private function expiredLotPolicy(Warehouse $warehouse): ExpiredLotPolicy
    {
        $organizationId = $warehouse->branch()->withoutGlobalScopes()->value('organization_id');

        return Organization::find($organizationId)?->expiredLotPolicy() ?? ExpiredLotPolicy::Warn;
    }

    private function ensureOperational(Warehouse $warehouse): void
    {
        if (! $warehouse->isOperational()) {
            throw new InactiveLocationException("\"{$warehouse->name}\" deposu veya bağlı olduğu şube pasif; bu depoda stok işlemi yapılamaz.");
        }
    }

    private function withdrawFromLot(
        StockLot $lot,
        float $quantity,
        ?User $actor,
        ?string $reason,
        ?StockOutReason $reasonCode,
        StockMovementType $type = StockMovementType::Out,
        ?Model $related = null,
    ): StockMovement {
        $locked = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

        if ((float) $locked->quantity < $quantity) {
            throw new InsufficientStockException("Lot #{$locked->id} için yeterli stok yok.");
        }

        $locked->update(['quantity' => $locked->quantity - $quantity]);

        return $this->recordMovement(
            $type, $locked, -1 * $quantity, $actor, $reason,
            relatedEntityType: $related?->getMorphClass(), relatedEntityId: $related?->getKey(), reasonCode: $reasonCode,
        );
    }

    /**
     * @return Collection<int, StockMovement>
     */
    private function withdrawFefo(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        ?User $actor,
        ?string $reason,
        ?StockOutReason $reasonCode,
        StockMovementType $type = StockMovementType::Out,
        ?Model $related = null,
        bool $excludeExpired = false,
    ): Collection {
        $lots = StockLot::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($excludeExpired) {
            $expiredQuantity = $lots->filter(fn (StockLot $lot) => $lot->isExpired())->sum('quantity');
            $lots = $lots->reject(fn (StockLot $lot) => $lot->isExpired())->values();
        }

        $available = $lots->sum('quantity');

        if ($available < $quantity) {
            if (($expiredQuantity ?? 0) > 0) {
                throw new ExpiredLotBlockedException("Yeterli kullanılabilir stok yok: SKT'si geçmemiş mevcut {$available}, talep edilen {$quantity}. SKT'si geçmiş {$expiredQuantity} adetin kullanımı organizasyon ayarına göre engelli.");
            }

            throw new InsufficientStockException("Yeterli stok yok: mevcut {$available}, talep edilen {$quantity}.");
        }

        $remaining = $quantity;
        $movements = collect();

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $lot->quantity, $remaining);
            $lot->update(['quantity' => $lot->quantity - $take]);
            $movements->push($this->recordMovement(
                $type, $lot, -1 * $take, $actor, $reason,
                relatedEntityType: $related?->getMorphClass(), relatedEntityId: $related?->getKey(), reasonCode: $reasonCode,
            ));

            $remaining -= $take;
        }

        return $movements;
    }

    /**
     * @param  array{lot_no?: ?string, expiry_date?: ?string, unit_cost?: ?float}  $attributes
     */
    private function resolveOrCreateLot(Product $product, Warehouse $warehouse, array $attributes): StockLot
    {
        if (filled($attributes['lot_no'] ?? null)) {
            $existing = StockLot::where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('lot_no', $attributes['lot_no'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return StockLot::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'lot_no' => $attributes['lot_no'] ?? null,
            'expiry_date' => $attributes['expiry_date'] ?? null,
            'unit_cost' => $attributes['unit_cost'] ?? 0,
            'quantity' => 0,
        ]);
    }

    private function recordMovement(
        StockMovementType $type,
        StockLot $lot,
        float $quantity,
        ?User $actor,
        ?string $reason,
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null,
        ?StockOutReason $reasonCode = null,
        ?float $temperature = null,
        ?string $temperatureNote = null,
    ): StockMovement {
        $movement = StockMovement::create([
            'type' => $type->value,
            'lot_id' => $lot->id,
            'warehouse_id' => $lot->warehouse_id,
            'quantity' => $quantity,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'reason_code' => $reasonCode,
            'temperature' => $temperature,
            'temperature_note' => $temperatureNote,
            'related_entity_type' => $relatedEntityType,
            'related_entity_id' => $relatedEntityId,
        ]);

        // mimari.md Bölüm 6: her stok hareketinde ilgili dashboard cache anahtarı
        // geçersiz kılınır. Bu, in()/out()/adjust()/cancel()'in hepsinin geçtiği
        // tek nokta olduğu için burada — dört ayrı yerde tekrar etmeye gerek yok.
        $this->dashboardMetrics->forget($lot->product->organization_id);

        return $movement;
    }
}
