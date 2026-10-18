<?php

namespace App\Domain\Uts\Services;

use App\Domain\Patients\Models\PatientUsage;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Support\UsageReport;

/**
 * Hastaya kullanımı ÜTS'ye bildirir ve gerekirse iptal eder (Aşama 43).
 * Her deneme uts_notifications'a yazılır; hasta kimliği bu kayda yazılmaz,
 * kullanım kaydı üzerinden bulunur.
 */
class UtsUsageService
{
    public function __construct(
        private readonly UtsClient $client,
        private readonly UtsWriteGuard $guard,
    ) {}

    /**
     * @throws UtsException
     */
    public function report(PatientUsage $usage): UtsNotification
    {
        $environment = $this->guard->environment();

        if (in_array($usage->utsStatus(), [PatientUsage::UTS_SENT, PatientUsage::UTS_CANCELLED], true)) {
            throw new UtsException('Bu kullanım ÜTS\'ye zaten bildirildi.');
        }

        if ($usage->cancelled_at !== null) {
            throw new UtsException('Stok çıkışı iptal edilmiş bir kullanım ÜTS\'ye bildirilemez.');
        }

        $attributes = $this->attributes($usage, UtsNotification::TYPE_USAGE, $environment, null);

        try {
            $code = $this->client->reportUsage($this->reportFor($usage));
        } catch (UtsException $e) {
            UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_FAILED, 'message' => $e->getMessage()]);

            throw $e;
        }

        return UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_SENT, 'uts_notification_id' => $code]);
    }

    /**
     * @throws UtsException
     */
    public function cancel(PatientUsage $usage): UtsNotification
    {
        $environment = $this->guard->environment();
        $code = $usage->reportedCode();

        if ($code === null) {
            throw new UtsException('Bu, ÜTS\'de bildirilmiş bir kullanım değil; iptal edilecek bildirim yok.');
        }

        $attributes = $this->attributes($usage, UtsNotification::TYPE_USAGE_CANCEL, $environment, $code);

        try {
            $cancelCode = $this->client->cancelUsage($code);
        } catch (UtsException $e) {
            UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_FAILED, 'message' => $e->getMessage()]);

            throw $e;
        }

        return UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_SENT, 'uts_notification_id' => $cancelCode]);
    }

    private function reportFor(PatientUsage $usage): UsageReport
    {
        $patient = $usage->patient;

        return new UsageReport(
            uno: (string) $usage->product->gtin,
            lot: $usage->serial ? null : $usage->lot->lot_no,
            serial: $usage->serial?->serial_no,
            quantity: $usage->serial ? null : (int) round((float) $usage->quantity),
            usedOn: $usage->used_on->toDateString(),
            firstName: $patient->first_name,
            lastName: $patient->last_name,
            identityCode: $patient->identity_type->utsCode(),
            identityField: $patient->identity_type->utsField(),
            identityNo: $patient->identity_no,
            personNo: $patient->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(PatientUsage $usage, string $type, string $environment, ?string $source): array
    {
        return [
            'user_id' => auth()->id(),
            'patient_usage_id' => $usage->id,
            'type' => $type,
            'source_notification_id' => $source,
            'environment' => $environment,
            'uno' => (string) $usage->product->gtin,
            'product_name' => $usage->product->name,
            'lot' => $usage->lot->lot_no,
            'serial' => $usage->serial?->serial_no,
            'quantity' => (int) round((float) $usage->quantity),
        ];
    }
}
