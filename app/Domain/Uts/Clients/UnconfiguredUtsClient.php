<?php

namespace App\Domain\Uts\Clients;

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceiptPage;

/**
 * Organizasyon ÜTS token'ı girmemişse kullanılır: hiçbir dış çağrı yapmaz.
 */
class UnconfiguredUtsClient implements UtsClient
{
    public function pendingReceipts(?int $senderCode = null, int $page = 0): PendingReceiptPage
    {
        $this->fail();
    }

    public function lookup(string $uno, ?string $lot = null, ?string $serial = null): array
    {
        $this->fail();
    }

    public function name(): string
    {
        return 'Yapılandırılmamış';
    }

    private function fail(): never
    {
        throw new UtsException('ÜTS bağlantısı kurulmamış. Ayarlar ekranından ÜTS sistem token\'ını girin.');
    }
}
