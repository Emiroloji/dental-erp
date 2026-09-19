<?php

namespace App\Domain\Stock\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Exceptions\SerialException;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockMovement;
use App\Domain\Stock\Models\StockSerial;
use App\Domain\Stock\Support\SerialStatus;
use Illuminate\Support\Collection;

/**
 * Seri numarası defteri (Aşama 26). YALNIZCA StockMovementService tarafından
 * ve onun açtığı veritabanı işlemi içinde çağrılır — seri durumları stok
 * hareketinden bağımsız değişemez. Her seri değişikliği ilgili harekete
 * bağlanır (stock_movement_serials); bir serinin geçmişi buradan okunur.
 */
class SerialRegistry
{
    public const MAX_LENGTH = 100;

    /**
     * Ekrandaki "her satıra bir seri" alanını listeye çevirir (virgül ve
     * noktalı virgül de ayırıcıdır).
     *
     * @return array<int, string>
     */
    public static function parseList(?string $text): array
    {
        return self::normalize(preg_split('/[\r\n,;]+/', (string) $text) ?: []);
    }

    /**
     * Girilen/okutulan seri listesini temizler; tekrar eden veya geçersiz
     * seri varsa reddeder.
     *
     * @param  array<int, mixed>  $serials
     * @return array<int, string>
     */
    public static function normalize(array $serials): array
    {
        $clean = collect($serials)
            ->map(fn ($serial) => trim((string) $serial))
            ->filter(fn (string $serial) => $serial !== '')
            ->values();

        if ($long = $clean->first(fn (string $serial) => mb_strlen($serial) > self::MAX_LENGTH)) {
            throw new SerialException('Seri numarası en fazla '.self::MAX_LENGTH." karakter olabilir: {$long}");
        }

        $duplicates = $clean->duplicates()->unique()->values();

        if ($duplicates->isNotEmpty()) {
            throw new SerialException('Aynı seri numarası birden fazla kez girildi: '.$duplicates->implode(', '));
        }

        return $clean->all();
    }

    /**
     * Seri takipli üründe miktar tam sayı ve seri sayısına eşit olmalıdır.
     *
     * @param  array<int, string>  $serials
     */
    public static function ensureMatchesQuantity(Product $product, float $quantity, array $serials): void
    {
        if (abs($quantity - round($quantity)) > 0.00001) {
            throw new SerialException("{$product->name} seri takipli; miktar tam sayı olmalıdır.");
        }

        if (count($serials) !== (int) round($quantity)) {
            throw new SerialException("{$product->name} seri takipli: ".(int) round($quantity).' birim için '.count($serials).' seri numarası girildi. Her birimin seri numarası girilmelidir.');
        }
    }

    /**
     * Girişte yeni seriler lota alınır. Daha önce çıkmış (ör. hastadan geri
     * dönen veya iptal edilmiş) bir seri yeniden stoğa girebilir; stokta ya
     * da yolda olan bir seri ikinci kez giremez.
     *
     * @param  array<int, string>  $serials
     */
    public function receive(Product $product, StockLot $lot, array $serials, StockMovement $movement): void
    {
        $existing = StockSerial::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->whereIn('serial_no', $serials)
            ->lockForUpdate()
            ->get()
            ->keyBy('serial_no');

        $active = $existing->filter(fn (StockSerial $serial) => in_array($serial->status, [SerialStatus::InStock, SerialStatus::InTransit], true));

        if ($active->isNotEmpty()) {
            throw new SerialException('Bu seri numaraları zaten stokta veya transferde: '.$active->keys()->implode(', '));
        }

        $ids = [];

        foreach ($serials as $serialNo) {
            $serial = $existing->get($serialNo);

            if ($serial) {
                $serial->update(['lot_id' => $lot->id, 'status' => SerialStatus::InStock]);
            } else {
                $serial = StockSerial::create([
                    'organization_id' => $product->organization_id,
                    'product_id' => $product->id,
                    'lot_id' => $lot->id,
                    'serial_no' => $serialNo,
                    'status' => SerialStatus::InStock,
                ]);
            }

            $ids[] = $serial->id;
        }

        $movement->serials()->attach($ids);
    }

    /**
     * Çıkış için seçilen seriler: bu üründe, bu depoda ve stokta olmalı;
     * lot belirtildiyse hepsi o lottan.
     *
     * @param  array<int, string>  $serials
     * @return Collection<int, StockSerial>
     */
    public function selectInStock(Product $product, Warehouse $warehouse, array $serials, ?StockLot $lot = null): Collection
    {
        $found = StockSerial::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->whereIn('serial_no', $serials)
            ->where('status', SerialStatus::InStock->value)
            ->whereHas('lot', fn ($query) => $query->where('warehouse_id', $warehouse->id))
            ->when($lot, fn ($query) => $query->where('lot_id', $lot->id))
            ->with('lot')
            ->lockForUpdate()
            ->get();

        $missing = array_values(array_diff($serials, $found->pluck('serial_no')->all()));

        if ($missing !== []) {
            throw new SerialException('Bu seri numaraları '.($lot ? 'seçilen lotta' : "\"{$warehouse->name}\" deposunda").' stokta değil: '.implode(', ', $missing));
        }

        return $found;
    }

    /**
     * Otomatik seçim (transfer gönderimi): lottaki stoktaki serilerden
     * numara sırasıyla istenen kadar.
     *
     * @return Collection<int, StockSerial>
     */
    public function pick(StockLot $lot, int $count): Collection
    {
        $serials = StockSerial::withoutGlobalScopes()
            ->where('lot_id', $lot->id)
            ->where('status', SerialStatus::InStock->value)
            ->orderBy('serial_no')
            ->limit($count)
            ->lockForUpdate()
            ->get();

        if ($serials->count() !== $count) {
            throw new SerialException("Lot {$lot->lot_no}: stok miktarı ile stoktaki seri sayısı uyuşmuyor.");
        }

        return $serials;
    }

    /**
     * @param  Collection<int, StockSerial>  $serials
     */
    public function transition(Collection $serials, StockMovement $movement, SerialStatus $status, ?StockLot $toLot = null): void
    {
        foreach ($serials as $serial) {
            $serial->update(['status' => $status, ...($toLot ? ['lot_id' => $toLot->id] : [])]);
        }

        $movement->serials()->attach($serials->modelKeys());
    }

    /**
     * Stok sayımı: lotta fiilen bulunan serilerin tam listesi. Listede olmayan
     * stoktaki seriler "sayımda bulunamadı", listede olup stokta görünmeyenler
     * lota yeniden/ilk kez alınır.
     *
     * @param  array<int, string>  $counted
     * @return int Net fark (bulunan − kayıp)
     */
    public function reconcile(StockLot $lot, array $counted, StockMovement $movement): int
    {
        $current = StockSerial::withoutGlobalScopes()
            ->where('lot_id', $lot->id)
            ->where('status', SerialStatus::InStock->value)
            ->lockForUpdate()
            ->get();

        $missing = $current->reject(fn (StockSerial $serial) => in_array($serial->serial_no, $counted, true));
        $foundNumbers = array_values(array_diff($counted, $current->pluck('serial_no')->all()));

        $this->transition($missing, $movement, SerialStatus::Lost);

        if ($foundNumbers !== []) {
            $this->receive($lot->product, $lot, $foundNumbers, $movement);
        }

        return count($foundNumbers) - $missing->count();
    }

    /**
     * Hareket iptali: orijinal hareketin serileri eski durumuna döner.
     */
    public function reverse(StockMovement $original, StockMovement $cancellation): void
    {
        $serials = $original->serials()->lockForUpdate()->get();

        if ($serials->isEmpty()) {
            return;
        }

        [$expected, $next] = match ($original->type->value) {
            'in' => [SerialStatus::InStock, SerialStatus::Void],
            'out', 'return' => [SerialStatus::Out, SerialStatus::InStock],
            'transfer_out' => [SerialStatus::InTransit, SerialStatus::InStock],
            default => throw new SerialException('Seri takipli üründe bu hareket türü iptal edilemez.'),
        };

        $moved = $serials->reject(fn (StockSerial $serial) => $serial->status !== $expected || $serial->lot_id !== $original->lot_id);

        if ($moved->count() !== $serials->count()) {
            throw new SerialException('Bu hareketin seri numaralarından bazıları sonradan başka bir işlem gördü; hareket iptal edilemez: '
                .$serials->diff($moved)->pluck('serial_no')->implode(', '));
        }

        $this->transition($serials, $cancellation, $next);
    }
}
