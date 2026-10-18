<?php

namespace App\Domain\Uts\Contracts;

use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceiptPage;
use App\Domain\Uts\Support\UsageReport;
use App\Domain\Uts\Support\UtsItem;

/**
 * ÜTS web servisleri. Uygulamalar: HttpUtsClient (gerçek), FakeUtsClient
 * (test), UnconfiguredUtsClient (token yok). Yazma bildirimleri: Alma
 * (Aşama 42), Kullanım ve Kullanım iptali (Aşama 43).
 */
interface UtsClient
{
    /**
     * Kuruma yapılmış, kabul bekleyen Verme bildirimleri (rev. 1.99, 3.4.10);
     * gönderen kurum ve belge (fatura/irsaliye) numarasıyla süzülebilir.
     *
     * @throws UtsException
     */
    public function pendingReceipts(?int $senderCode = null, int $page = 0, ?string $documentNo = null): PendingReceiptPage;

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

    /**
     * Kuruma gelen Verme bildirimini "Almak istemiyorum" olarak işaretler
     * (rev. 1.99, 3.4.22); bildirim kabul bekleyenlerden kalkar. ÜTS'de
     * "Almak istiyorum" ile geri alınabilir.
     *
     * @throws UtsException
     */
    public function decline(string $notificationId): void;

    /**
     * Hastaya kullanılan tekil ürünü bildirir (rev. 1.99, 3.1.9). Yeni
     * bildirim kodunu (SNC) döndürür.
     *
     * @throws UtsException
     */
    public function reportUsage(UsageReport $report): string;

    /**
     * Kullanım bildirimini iptal eder (3.2, kullanim/iptal). İptal
     * bildiriminin kodunu döndürür.
     *
     * @throws UtsException
     */
    public function cancelUsage(string $notificationId): string;

    public function name(): string;
}
