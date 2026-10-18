<?php

namespace App\Domain\Uts\Services;

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Support\PendingReceipt;

/**
 * Kabul bekleyen bir Verme bildirimini ÜTS'de Alma bildirimiyle kabul eder
 * (Aşama 42). Kabul her zaman bildirim koduyla (VBI) yapılır; stoğa
 * dokunmaz. Alma bildiriminin iptali olmadığından her deneme kaydedilir.
 */
class UtsReceiveService
{
    public function __construct(
        private readonly UtsClient $client,
        private readonly UtsWriteGuard $guard,
    ) {}

    /**
     * @param  ?int  $quantity  Lot takipli üründe eksik alım için; boşsa verilen adedin tamamı.
     *
     * @throws UtsException
     */
    public function receive(PendingReceipt $receipt, ?int $quantity = null): UtsNotification
    {
        if ($receipt->notificationId === null) {
            throw new UtsException('ÜTS bu satır için bildirim kodu döndürmedi; kabul edilemez.');
        }

        $quantity = $this->quantityToSend($receipt, $quantity);
        $environment = $this->guard->environment();

        $alreadyReceived = UtsNotification::where('source_notification_id', $receipt->notificationId)
            ->where('status', UtsNotification::STATUS_SENT)
            ->exists();

        if ($alreadyReceived) {
            throw new UtsException('Bu bildirim daha önce kabul edildi.');
        }

        $attributes = [
            'user_id' => auth()->id(),
            'type' => UtsNotification::TYPE_RECEIVE,
            'source_notification_id' => $receipt->notificationId,
            'environment' => $environment,
            'uno' => $receipt->uno,
            'product_name' => $receipt->brand,
            'lot' => $receipt->lot,
            'serial' => $receipt->serial,
            'quantity' => $quantity ?? $receipt->quantity,
            'sender_code' => $receipt->senderCode,
            'sender_name' => $receipt->senderName,
            'document_no' => $receipt->documentNo,
        ];

        try {
            $code = $this->client->receive($receipt->notificationId, $quantity);
        } catch (UtsException $e) {
            UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_FAILED, 'message' => $e->getMessage()]);

            throw $e;
        }

        return UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_SENT, 'uts_notification_id' => $code]);
    }

    /**
     * ÜTS'ye gidecek adet: seri takipli üründe hiç verilmez (rev. 1.99,
     * 3.1.6.2); lot takipli üründe yalnızca verilenden azsa verilir.
     */
    private function quantityToSend(PendingReceipt $receipt, ?int $quantity): ?int
    {
        if ($receipt->serial !== null || $quantity === null || $receipt->quantity === null) {
            return null;
        }

        if ($quantity < 1 || $quantity > $receipt->quantity) {
            throw new UtsException("Kabul edilecek adet 1 ile {$receipt->quantity} arasında olmalı.");
        }

        return $quantity < $receipt->quantity ? $quantity : null;
    }
}
