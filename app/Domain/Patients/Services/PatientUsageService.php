<?php

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Models\Patient;
use App\Domain\Patients\Models\PatientUsage;
use App\Domain\Stock\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Stok çıkış hareketlerini hastaya bağlar (Aşama 43). Stoğa dokunmaz:
 * hareketler StockMovementService::out ile önceden oluşmuş olmalıdır.
 */
class PatientUsageService
{
    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return Collection<int, PatientUsage>
     */
    public function record(Collection $movements, Patient $patient, CarbonInterface $usedOn): Collection
    {
        return $movements->flatMap(function (StockMovement $movement) use ($patient, $usedOn) {
            $base = [
                'patient_id' => $patient->id,
                'stock_movement_id' => $movement->id,
                'product_id' => $movement->lot->product_id,
                'stock_lot_id' => $movement->lot_id,
                'used_on' => $usedOn->toDateString(),
                'user_id' => auth()->id(),
            ];

            $serials = $movement->serials;

            if ($serials->isEmpty()) {
                return [PatientUsage::create($base + ['quantity' => abs((float) $movement->quantity)])];
            }

            return $serials->map(fn ($serial) => PatientUsage::create($base + ['stock_serial_id' => $serial->id, 'quantity' => 1]));
        })->values();
    }

    /**
     * Çıkış hareketi iptal edildiğinde ona bağlı kullanımlar geçersizleşir.
     */
    public function cancelForMovement(StockMovement $movement): void
    {
        PatientUsage::where('stock_movement_id', $movement->id)
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => now()]);
    }
}
