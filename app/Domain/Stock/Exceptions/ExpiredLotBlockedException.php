<?php

namespace App\Domain\Stock\Exceptions;

/**
 * Organizasyon ayarı "kullanımı tamamen engelle" iken SKT'si geçmiş lottan
 * kullanım çıkışı denendi. Kullanılabilir (süresi geçmemiş) stok yetersizliği
 * sayıldığı için InsufficientStockException'ı genişletir — çıkış yapan her
 * ekran bu hatayı zaten miktar hatası olarak gösterir.
 */
class ExpiredLotBlockedException extends InsufficientStockException
{
    //
}
