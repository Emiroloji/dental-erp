<?php

namespace App\Support\Notifications;

use App\Domain\Stock\Notifications\StockLevelAlert;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Uygulama içi bildirim türleri ve okunur özetleri. Bildirimler sayfası ve
 * günlük özet e-postası (Aşama 39) aynı etiketleri kullanır.
 */
final class NotificationKind
{
    /** Tür => etiket; sıra, özet e-postasındaki grup sırasıdır. */
    public const LABELS = [
        'stock' => 'Stok Uyarıları',
        'cold_chain' => 'Soğuk Zincir',
        'finance' => 'Finans',
        'transfer' => 'Transfer',
        'purchase' => 'Satın Alma',
        'stock_count' => 'Stok Sayımı',
        'return' => 'İade',
        'plan' => 'Paket',
    ];

    public static function label(string $kind): string
    {
        return self::LABELS[$kind] ?? 'Diğer';
    }

    public static function of(DatabaseNotification $notification): string
    {
        if ($notification->type === StockLevelAlert::class) {
            return 'stock';
        }

        $kind = $notification->data['kind'] ?? null;

        return is_string($kind) && array_key_exists($kind, self::LABELS) ? $kind : 'other';
    }

    /**
     * Tek satırlık okunur metin.
     */
    public static function summary(DatabaseNotification $notification): string
    {
        $data = $notification->data;

        if (filled($data['message'] ?? null)) {
            return $data['message'];
        }

        $product = $data['product_name'] ?? 'Bildirim';
        $reason = $data['reasons'][0] ?? null;

        return $reason ? "{$product}: {$reason}" : $product;
    }
}
