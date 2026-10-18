<?php

namespace App\Domain\Uts\Services;

use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsConnection;

/**
 * ÜTS'ye yazan her işlemin ilk adımı: bağlantının ortamını döndürür; gerçek
 * ortamda yazma kilidi (UTS_PRODUCTION_WRITES) kapalıysa durdurur.
 */
class UtsWriteGuard
{
    /**
     * @throws UtsException
     */
    public function environment(): string
    {
        $environment = (string) UtsConnection::first()?->environment;

        if ($environment === 'production' && ! config('uts.production_writes')) {
            throw new UtsException('ÜTS\'ye gerçek ortamda bildirim gönderimi henüz açılmadı. Önce test ortamında denenmeli.');
        }

        return $environment;
    }
}
