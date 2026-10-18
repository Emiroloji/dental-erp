<?php

namespace App\Domain\EInvoice\Clients;

use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use App\Domain\EInvoice\Support\IncomingPage;
use Carbon\CarbonInterface;

/**
 * Organizasyon e-Fatura bağlantısı kurmamışsa kullanılır: dış çağrı yapmaz.
 */
class UnconfiguredEInvoiceProvider implements EInvoiceProvider
{
    public function incoming(CarbonInterface $since, int $page): IncomingPage
    {
        $this->fail();
    }

    public function xml(string $uuid): string
    {
        $this->fail();
    }

    public function pdf(string $uuid): string
    {
        $this->fail();
    }

    public function name(): string
    {
        return 'Yapılandırılmamış';
    }

    private function fail(): never
    {
        throw new EInvoiceException('e-Fatura bağlantısı kurulmamış. Ayarlar ekranından entegratör API anahtarını girin.');
    }
}
