<?php

namespace App\Domain\Uts\Services;

use App\Domain\Uts\Clients\HttpUtsClient;
use App\Domain\Uts\Clients\UnconfiguredUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Models\UtsConnection;

/**
 * Bağlantıdan ÜTS istemcisini üretir. Ekranlar oturumdaki organizasyonun
 * bağlantısını (current), kuyruk işleri bağlantının kendisini (for)
 * kullanır; kuyrukta oturum yoktur. Token yoksa dış çağrı yapılmaz.
 */
class UtsClientFactory
{
    public function for(UtsConnection $connection): UtsClient
    {
        return new HttpUtsClient(
            $connection->token,
            (string) config('uts.urls.'.$connection->environment, config('uts.urls.test')),
            (int) config('uts.timeout'),
        );
    }

    public function current(): UtsClient
    {
        $connection = auth()->check() ? UtsConnection::first() : null;

        return $connection ? $this->for($connection) : new UnconfiguredUtsClient;
    }
}
