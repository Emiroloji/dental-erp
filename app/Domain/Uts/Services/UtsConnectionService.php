<?php

namespace App\Domain\Uts\Services;

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Models\UtsConnection;

class UtsConnectionService
{
    public function current(): ?UtsConnection
    {
        return UtsConnection::first();
    }

    public function save(string $environment, string $token): UtsConnection
    {
        return UtsConnection::updateOrCreate(
            ['organization_id' => auth()->user()->organization_id],
            ['environment' => $environment, 'token' => trim($token), 'last_verified_at' => null],
        );
    }

    /**
     * Bağlantıyı en ucuz sorguyla dener (kabul bekleyenlerin ilk sayfası);
     * başarılıysa doğrulama zamanını işler, değilse UtsException fırlar.
     */
    public function test(UtsClient $client): void
    {
        $client->pendingReceipts(null, 0);

        $this->current()?->update(['last_verified_at' => now()]);
    }

    public function remove(): void
    {
        $this->current()?->delete();
    }
}
