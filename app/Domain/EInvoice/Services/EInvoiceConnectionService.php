<?php

namespace App\Domain\EInvoice\Services;

use App\Domain\EInvoice\Contracts\EInvoiceProvider;
use App\Domain\EInvoice\Models\EInvoiceConnection;

class EInvoiceConnectionService
{
    public function current(): ?EInvoiceConnection
    {
        return EInvoiceConnection::first();
    }

    public function save(string $provider, string $environment, string $apiKey): EInvoiceConnection
    {
        return EInvoiceConnection::updateOrCreate(
            ['organization_id' => auth()->user()->organization_id],
            ['provider' => $provider, 'environment' => $environment, 'api_key' => trim($apiKey), 'last_verified_at' => null],
        );
    }

    /**
     * Bağlantıyı en ucuz çağrıyla dener (son günün gelen faturaları);
     * başarılıysa doğrulama zamanını işler, değilse EInvoiceException fırlar.
     */
    public function test(EInvoiceProvider $provider): void
    {
        $provider->incoming(now()->subDay(), 1);

        $this->current()?->update(['last_verified_at' => now()]);
    }

    public function remove(): void
    {
        $this->current()?->delete();
    }
}
