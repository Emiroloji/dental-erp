<?php

namespace App\Domain\Uts\Contracts;

use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceiptPage;
use App\Domain\Uts\Support\UtsItem;

/**
 * ÜTS web servisleri (yalnızca okuma). Uygulamalar: HttpUtsClient (gerçek),
 * FakeUtsClient (test), UnconfiguredUtsClient (token yok). Yazma bildirimleri
 * (Alma, Kullanım...) Aşama B'de bu arayüze eklenecek.
 */
interface UtsClient
{
    /**
     * Kuruma yapılmış, kabul bekleyen Verme bildirimleri (3.4.7).
     *
     * @throws UtsException
     */
    public function pendingReceipts(?int $senderCode = null, int $page = 0): PendingReceiptPage;

    /**
     * Kurum üzerindeki tekil ürünü ürün no + lot/seri ile sorgular (3.4.1).
     *
     * @return list<UtsItem>
     *
     * @throws UtsException
     */
    public function lookup(string $uno, ?string $lot = null, ?string $serial = null): array;

    public function name(): string;
}
