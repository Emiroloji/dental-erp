<?php

namespace App\Domain\Stock\Notifications;

use App\Domain\Catalog\Models\Product;
use App\Domain\Stock\Support\StockLevel;
use Illuminate\Notifications\Notification;

class StockLevelAlert extends Notification
{
    /**
     * mimari.md Bölüm 8: uygulama içi bildirimler senkron yazılır — bu sınıf
     * kasıtlı olarak ShouldQueue'yu uygulamaz. Ağır iş olan tarama zaten
     * ScanStockLevelsJob üzerinden kuyrukta çalışır; bildirim yazımının kendisi
     * anlık ve senkrondur.
     *
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        public readonly Product $product,
        public readonly StockLevel $level,
        public readonly float $quantity,
        public readonly array $reasons,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'level' => $this->level->value,
            'title' => $this->level === StockLevel::Critical ? 'Kritik stok seviyesi' : 'Düşük stok seviyesi',
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => $this->quantity,
            'min_stock' => $this->product->min_stock,
            'reasons' => $this->reasons,
        ];
    }
}
