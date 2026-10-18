<?php

namespace App\Domain\Uts\Services;

use App\Domain\Returns\Models\SupplierReturn;
use App\Domain\Returns\Support\ReturnStatus;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Support\GiveReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Tedarikçiye iadeyi ÜTS'ye Verme bildirimiyle bildirir ve gerekirse iptal
 * eder (Aşama 48). İade akışından ayrı, kargodan sonra kullanıcının
 * başlattığı adımdır; ÜTS kesintisi iadeyi durdurmaz. Seri takipli üründe her
 * seri ayrı bildirimdir; yarım kalan gönderim tekrar başlatıldığında yalnızca
 * eksikler gider.
 */
class UtsReturnService
{
    public const STATUS_NONE = 'none';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        private readonly UtsClient $client,
        private readonly UtsWriteGuard $guard,
    ) {}

    public function isReportable(SupplierReturn $return): bool
    {
        return (bool) $return->product?->uts_usage_report
            && in_array($return->status, [ReturnStatus::Shipped, ReturnStatus::SupplierApproved, ReturnStatus::Completed], true);
    }

    /**
     * @return Collection<int, UtsNotification> bu çağrıda başarıyla gönderilenler
     *
     * @throws UtsException ilk hata; ondan önce gönderilenler kayıtlıdır
     */
    public function report(SupplierReturn $return, int $institutionNo, string $documentNo, CarbonInterface $date): Collection
    {
        $return->loadMissing(['product', 'lot', 'supplier']);

        if (! $this->isReportable($return)) {
            throw new UtsException('Yalnızca kargoya verilmiş, ÜTS takipli ürünün iadesi bildirilir.');
        }

        $documentNo = trim($documentNo);
        if ($institutionNo <= 0 || $documentNo === '') {
            throw new UtsException('Tedarikçinin ÜTS kurum numarası ve iade belge numarası gerekli.');
        }

        $environment = $this->guard->environment();
        $pending = $this->pendingUnits($return);

        if ($pending === []) {
            throw new UtsException('Bu iade ÜTS\'ye zaten bildirildi.');
        }

        if ($return->supplier && $return->supplier->uts_institution_no === null) {
            $return->supplier->update(['uts_institution_no' => $institutionNo]);
        }

        $sent = collect();

        foreach ($pending as $serial) {
            $report = new GiveReport(
                uno: (string) $return->product->gtin,
                lot: $serial === null ? $return->lot->lot_no : null,
                serial: $serial,
                quantity: $serial === null ? (int) round((float) $return->quantity) : null,
                institutionNo: $institutionNo,
                documentNo: $documentNo,
                date: $date->toDateString(),
            );
            $attributes = $this->attributes($return, UtsNotification::TYPE_GIVE, $environment, null, $serial) + ['document_no' => $documentNo, 'sender_code' => $institutionNo];

            try {
                $code = $this->client->give($report);
            } catch (UtsException $e) {
                UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_FAILED, 'message' => $e->getMessage()]);

                throw $e;
            }

            $sent->push(UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_SENT, 'uts_notification_id' => $code]));
        }

        return $sent;
    }

    /**
     * Gönderilmiş ve iptal edilmemiş her Verme bildirimini iptal eder.
     *
     * @throws UtsException
     */
    public function cancel(SupplierReturn $return): void
    {
        $return->loadMissing(['product', 'lot']);
        $environment = $this->guard->environment();
        $active = $this->activeGives($return);

        if ($active->isEmpty()) {
            throw new UtsException('Bu iadenin ÜTS\'de geçerli bir Verme bildirimi yok.');
        }

        foreach ($active as $give) {
            $attributes = $this->attributes($return, UtsNotification::TYPE_GIVE_CANCEL, $environment, $give->uts_notification_id, $give->serial);

            try {
                $code = $this->client->cancelGive($give->uts_notification_id);
            } catch (UtsException $e) {
                UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_FAILED, 'message' => $e->getMessage()]);

                throw $e;
            }

            UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_SENT, 'uts_notification_id' => $code]);
        }
    }

    public function status(SupplierReturn $return): string
    {
        $records = $this->records($return);

        if ($records->isEmpty()) {
            return self::STATUS_NONE;
        }

        $everSent = $records->contains(fn ($r) => $r->type === UtsNotification::TYPE_GIVE && $r->status === UtsNotification::STATUS_SENT);

        if ($everSent && $this->activeGives($return)->isEmpty()) {
            return self::STATUS_CANCELLED;
        }

        if ($this->pendingUnits($return) === []) {
            return self::STATUS_SENT;
        }

        return $this->activeGives($return)->isNotEmpty() ? self::STATUS_PARTIAL : self::STATUS_FAILED;
    }

    public function hasActiveGive(SupplierReturn $return): bool
    {
        return $this->activeGives($return)->isNotEmpty();
    }

    /**
     * Henüz başarıyla bildirilmemiş birimler: seri numaraları ya da lot için null.
     *
     * @return list<?string>
     */
    private function pendingUnits(SupplierReturn $return): array
    {
        $done = $this->records($return)
            ->filter(fn ($r) => $r->type === UtsNotification::TYPE_GIVE && $r->status === UtsNotification::STATUS_SENT)
            ->pluck('serial')
            ->all();

        $units = $return->serial_numbers ?: [null];

        return array_values(array_filter($units, fn (?string $unit) => ! in_array($unit, $done, true)));
    }

    /**
     * @return Collection<int, UtsNotification>
     */
    private function activeGives(SupplierReturn $return): Collection
    {
        $records = $this->records($return);
        $cancelled = $records
            ->filter(fn ($r) => $r->type === UtsNotification::TYPE_GIVE_CANCEL && $r->status === UtsNotification::STATUS_SENT)
            ->pluck('source_notification_id')
            ->all();

        return $records
            ->filter(fn ($r) => $r->type === UtsNotification::TYPE_GIVE && $r->status === UtsNotification::STATUS_SENT && ! in_array($r->uts_notification_id, $cancelled, true))
            ->values();
    }

    /**
     * @return Collection<int, UtsNotification>
     */
    private function records(SupplierReturn $return): Collection
    {
        return UtsNotification::where('supplier_return_id', $return->id)->orderBy('id')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SupplierReturn $return, string $type, string $environment, ?string $source, ?string $serial): array
    {
        return [
            'user_id' => auth()->id(),
            'supplier_return_id' => $return->id,
            'type' => $type,
            'source_notification_id' => $source,
            'environment' => $environment,
            'uno' => (string) $return->product->gtin,
            'product_name' => $return->product->name,
            'lot' => $return->lot?->lot_no,
            'serial' => $serial,
            'quantity' => $serial === null ? (int) round((float) $return->quantity) : 1,
            'sender_name' => $return->supplier?->name,
        ];
    }
}
