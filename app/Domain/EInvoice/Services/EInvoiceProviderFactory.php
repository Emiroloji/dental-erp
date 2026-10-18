<?php

namespace App\Domain\EInvoice\Services;

use App\Domain\EInvoice\Clients\NilveraProvider;
use App\Domain\EInvoice\Clients\UnconfiguredEInvoiceProvider;
use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Models\EInvoiceConnection;

/**
 * Bağlantıdan entegratör sürücüsünü üretir. Ekranlar oturumdaki
 * organizasyonun bağlantısını (current), kuyruk işleri bağlantının kendisini
 * (for) kullanır; kuyrukta oturum yoktur. Yeni entegratör = yeni sürücü +
 * buraya bir satır.
 */
class EInvoiceProviderFactory
{
    public function for(EInvoiceConnection $connection): EInvoiceProvider
    {
        return match ($connection->provider) {
            'nilvera' => new NilveraProvider(
                $connection->api_key,
                (string) config("einvoice.urls.nilvera.{$connection->environment}", config('einvoice.urls.nilvera.test')),
                (int) config('einvoice.timeout'),
            ),
            default => new UnconfiguredEInvoiceProvider,
        };
    }

    public function current(): EInvoiceProvider
    {
        $connection = auth()->check() ? EInvoiceConnection::first() : null;

        return $connection ? $this->for($connection) : new UnconfiguredEInvoiceProvider;
    }
}
