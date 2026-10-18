<?php

namespace App\Domain\Uts\Contracts;

use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceiptPage;
use App\Domain\Uts\Support\UtsItem;

/**
 * ÜTS web servisleri. Uygulamalar: HttpUtsClient (gerçek), FakeUtsClient
 * (test), UnconfiguredUtsClient (token yok). Yazma bildirimlerinden şimdilik
 * yalnızca Alma vardır (Aşama 42); Kullanım vb. sonraki aşamalardır.
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

    /**
     * Kabul bekleyen bir Verme bildirimini Alma bildirimiyle kabul eder
     * (rev. 1.99, 3.1.6). Adet yalnızca lot takipli üründe eksik alım için
     * verilir. Alma bildiriminin iptali yoktur (3.2). Yeni bildirim kodunu
     * (SNC) döndürür.
     *
     * @throws UtsException
     */
    public function receive(string $notificationId, ?int $quantity = null): string;

    public function name(): string;
}
