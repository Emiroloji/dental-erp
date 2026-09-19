<?php

namespace App\Domain\Stock\Support;

use App\Domain\Stock\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * "Sadece uyar" ayarında SKT'si geçmiş lottan yapılan çıkış engellenmez ama
 * işaretlenir (proje.md Bölüm 7): çıkış ekranları bu mesajı gösterir.
 */
class ExpiredUsageWarning
{
    /**
     * @param  Collection<int, StockMovement>  $movements
     */
    public static function for(Collection $movements, ?StockOutReason $reason): ?string
    {
        if ($reason?->disposesStock()) {
            return null;
        }

        $lotNames = $movements
            ->map(fn (StockMovement $movement) => $movement->lot)
            ->filter(fn ($lot) => $lot?->isExpired())
            ->map(fn ($lot) => $lot->lot_no ?? "Lot #{$lot->id}")
            ->unique()
            ->values();

        if ($lotNames->isEmpty()) {
            return null;
        }

        return 'Dikkat: son kullanma tarihi geçmiş lottan çıkış yapıldı ('.$lotNames->implode(', ').').';
    }
}
