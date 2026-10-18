<?php

namespace App\Domain\Uts\Services;

use App\Domain\Stock\Models\StockMovement;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Support\DisposalReport;
use Illuminate\Support\Collection;

/**
 * SKT geçmiş ya da hasarlı ürün çıkışını ÜTS'ye İmha/Bertaraf veya HEK/Zayiat
 * olarak bildirir (Aşama 49). Stok çıkışından sonra çalışır; ÜTS hatası
 * çıkışı geri almaz. Gerekçe ve belge no kayıtta (details) saklanır ki
 * başarısız gönderim Gönderim Geçmişi'nden aynen tekrar edilebilsin.
 */
class UtsDisposalService
{
    public function __construct(
        private readonly UtsClient $client,
        private readonly UtsWriteGuard $guard,
    ) {}

    /**
     * @return array<string, string> türe göre geçerli kodlar
     */
    public static function codes(string $type): array
    {
        return $type === DisposalReport::TYPE_IMHA ? DisposalReport::IMHA_REASONS : DisposalReport::HEK_TYPES;
    }

    /**
     * Hareketin her serisi (lotta tek kayıt) için bildirim gönderir.
     *
     * @return list<string> başarısız gönderimlerin hata mesajları
     *
     * @throws UtsException gerçek ortam kilidi ya da geçersiz gerekçe
     */
    public function report(StockMovement $movement, string $type, string $code, ?string $description, ?string $documentNo): array
    {
        $this->ensureValid($type, $code, $description, $documentNo);
        $environment = $this->guard->environment();
        $movement->loadMissing(['lot.product', 'serials']);
        $details = ['type' => $type, 'code' => $code, 'description' => $description, 'document_no' => $documentNo];
        $units = $movement->serials->pluck('serial_no')->all() ?: [null];
        $errors = [];

        foreach ($units as $serial) {
            try {
                $this->send($movement, $serial, $details, $environment);
            } catch (UtsException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Başarısız bir gönderimi aynı gerekçeyle tekrar eder.
     *
     * @throws UtsException
     */
    public function retry(UtsNotification $failed): UtsNotification
    {
        $movement = StockMovement::with(['lot.product', 'serials'])->findOrFail($failed->stock_movement_id);
        $environment = $this->guard->environment();

        if ($this->sentFor($movement, $failed->serial)) {
            throw new UtsException('Bu birim ÜTS\'ye zaten bildirildi.');
        }

        return $this->send($movement, $failed->serial, $failed->details, $environment);
    }

    /**
     * @throws UtsException
     */
    public function cancel(UtsNotification $sent): UtsNotification
    {
        $environment = $this->guard->environment();
        $type = $sent->details['type'];

        if (! $this->isActive($sent)) {
            throw new UtsException('Bu bildirim ÜTS\'de geçerli değil; iptal edilecek bir şey yok.');
        }

        $attributes = $sent->only(['stock_movement_id', 'uno', 'product_name', 'lot', 'serial', 'quantity', 'document_no', 'details']) + [
            'user_id' => auth()->id(),
            'type' => $type === DisposalReport::TYPE_IMHA ? UtsNotification::TYPE_IMHA_CANCEL : UtsNotification::TYPE_HEK_CANCEL,
            'source_notification_id' => $sent->uts_notification_id,
            'environment' => $environment,
        ];

        try {
            $code = $this->client->cancelDisposal($type, $sent->uts_notification_id);
        } catch (UtsException $e) {
            UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_FAILED, 'message' => $e->getMessage()]);

            throw $e;
        }

        return UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_SENT, 'uts_notification_id' => $code]);
    }

    /** Hareketin ÜTS'de geçerli (iptal edilmemiş) imha/zayiat bildirimi var mı? */
    public function hasActive(StockMovement $movement): bool
    {
        return $this->disposals($movement->id)->contains(fn (UtsNotification $n) => $this->isActive($n));
    }

    public function isActive(UtsNotification $sent): bool
    {
        if (! in_array($sent->type, [UtsNotification::TYPE_IMHA, UtsNotification::TYPE_HEK], true) || $sent->status !== UtsNotification::STATUS_SENT) {
            return false;
        }

        return ! UtsNotification::whereIn('type', [UtsNotification::TYPE_IMHA_CANCEL, UtsNotification::TYPE_HEK_CANCEL])
            ->where('status', UtsNotification::STATUS_SENT)
            ->where('source_notification_id', $sent->uts_notification_id)
            ->exists();
    }

    /** Başarısız gönderim tekrar edilebilir mi (aynı birim sonradan gönderilmemiş)? */
    public function canRetry(UtsNotification $failed): bool
    {
        return in_array($failed->type, [UtsNotification::TYPE_IMHA, UtsNotification::TYPE_HEK], true)
            && $failed->status === UtsNotification::STATUS_FAILED
            && $failed->stock_movement_id !== null
            && ! $this->sentFor(StockMovement::findOrFail($failed->stock_movement_id), $failed->serial);
    }

    /**
     * @param  array{type: string, code: string, description: ?string, document_no: ?string}  $details
     */
    private function send(StockMovement $movement, ?string $serial, array $details, string $environment): UtsNotification
    {
        $product = $movement->lot->product;
        $quantity = $serial === null ? (int) round(abs((float) $movement->quantity)) : 1;
        $report = new DisposalReport($details['type'], (string) $product->gtin, $serial === null ? $movement->lot->lot_no : null, $serial, $serial === null ? $quantity : null, $details['code'], $details['description'], $details['document_no']);

        $attributes = [
            'user_id' => auth()->id(),
            'stock_movement_id' => $movement->id,
            'type' => $details['type'] === DisposalReport::TYPE_IMHA ? UtsNotification::TYPE_IMHA : UtsNotification::TYPE_HEK,
            'environment' => $environment,
            'uno' => (string) $product->gtin,
            'product_name' => $product->name,
            'lot' => $movement->lot->lot_no,
            'serial' => $serial,
            'quantity' => $quantity,
            'document_no' => $details['document_no'],
            'details' => $details,
        ];

        try {
            $code = $this->client->dispose($report);
        } catch (UtsException $e) {
            UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_FAILED, 'message' => $e->getMessage()]);

            throw $e;
        }

        return UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_SENT, 'uts_notification_id' => $code]);
    }

    private function sentFor(StockMovement $movement, ?string $serial): bool
    {
        return $this->disposals($movement->id)->contains(fn (UtsNotification $n) => $n->serial === $serial && $n->status === UtsNotification::STATUS_SENT);
    }

    /**
     * @return Collection<int, UtsNotification>
     */
    private function disposals(int $movementId): Collection
    {
        return UtsNotification::where('stock_movement_id', $movementId)
            ->whereIn('type', [UtsNotification::TYPE_IMHA, UtsNotification::TYPE_HEK])
            ->orderBy('id')
            ->get();
    }

    private function ensureValid(string $type, string $code, ?string $description, ?string $documentNo): void
    {
        if (! array_key_exists($code, self::codes($type))) {
            throw new UtsException('Geçerli bir ÜTS gerekçesi seçin.');
        }

        if ($code === 'DIGER' && blank($description)) {
            throw new UtsException('"Diğer" için açıklama yazın.');
        }

        if ($type === DisposalReport::TYPE_IMHA && blank($documentNo)) {
            throw new UtsException('İmha bildirimi için imha tutanağı (belge) numarası gerekli.');
        }
    }
}
