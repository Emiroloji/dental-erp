<?php

namespace App\Domain\Returns\Support;

/**
 * proje.md Bölüm 10: iade nedeni (hasarlı, hatalı ürün, yanlış sevkiyat, SKT sorunlu, diğer).
 */
enum ReturnReason: string
{
    case Damaged = 'damaged';
    case Defective = 'defective';
    case WrongShipment = 'wrong_shipment';
    case ExpiryIssue = 'expiry_issue';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Hasarlı',
            self::Defective => 'Hatalı ürün',
            self::WrongShipment => 'Yanlış sevkiyat',
            self::ExpiryIssue => 'SKT sorunlu',
            self::Other => 'Diğer',
        };
    }
}
