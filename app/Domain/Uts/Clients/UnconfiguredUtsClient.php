<?php

namespace App\Domain\Uts\Clients;

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceiptPage;
use App\Domain\Uts\Support\UsageReport;

/**
 * Organizasyon ÜTS token'ı girmemişse kullanılır: hiçbir dış çağrı yapmaz.
 */
class UnconfiguredUtsClient implements UtsClient
{
    public function pendingReceipts(?int $senderCode = null, int $page = 0, ?string $documentNo = null): PendingReceiptPage
    {
        $this->fail();
    }

    public function lookup(string $uno, ?string $lot = null, ?string $serial = null): array
    {
        $this->fail();
    }

    public function receive(string $notificationId, ?int $quantity = null): string
    {
        $this->fail();
    }

    public function decline(string $notificationId): void
    {
        $this->fail();
    }

    public function reportUsage(UsageReport $report): string
    {
        $this->fail();
    }

    public function cancelUsage(string $notificationId): string
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
