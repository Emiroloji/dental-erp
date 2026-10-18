<?php

namespace App\Domain\Uts\Services;

use App\Domain\Patients\Models\PatientUsage;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Models\UtsNotification;
use App\Domain\Uts\Support\RemovalReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Hastaya kullanılmış ürünün hastadan çıkarılması (Aşama 55; rev. 1.99,
 * 3.1.22). Çıkarma önce kullanım kaydına yazılır; kullanım ÜTS'ye
 * bildirilmişse ardından ÜTS'ye de bildirilir. ÜTS hatası yerel kaydı geri
 * almaz; başarısız gönderim Gönderim Geçmişi'nden tekrar edilir. ÜTS'de iptal
 * edilen çıkarma, yerel kayıttan da kaldırılır.
 */
class UtsRemovalService
{
    public function __construct(
        private readonly UtsClient $client,
        private readonly UtsWriteGuard $guard,
        private readonly UtsUsageService $usages,
    ) {}

    /**
     * @return list<string> ÜTS'ye gönderilemediyse hata mesajları (yerel çıkarma yine yazılır)
     *
     * @throws UtsException geçersiz gerekçe, tarih ya da zaten çıkarılmış kullanım
     */
    public function remove(PatientUsage $usage, string $reason, ?string $description, CarbonInterface $date): array
    {
        $this->ensureValid($usage, $reason, $description, $date);

        $description = $reason === 'DIGER' ? trim((string) $description) : null;

        DB::transaction(fn () => $usage->update([
            'removed_on' => $date->toDateString(),
            'removal_reason' => $reason,
            'removal_note' => $description,
        ]));

        if ($usage->utsStatus() !== PatientUsage::UTS_SENT) {
            return [];
        }

        try {
            $this->send($usage->fresh(), $this->guard->environment());
        } catch (UtsException $e) {
            return [$e->getMessage()];
        }

        return [];
    }

    /**
     * @throws UtsException
     */
    public function retry(UtsNotification $failed): UtsNotification
    {
        $usage = PatientUsage::findOrFail($failed->patient_usage_id);

        if (! $this->canRetry($failed)) {
            throw new UtsException('Bu çıkarma tekrar gönderilemez.');
        }

        return $this->send($usage, $this->guard->environment());
    }

    /**
     * ÜTS'deki çıkarma bildirimini iptal eder ve yerel çıkarmayı geri alır.
     *
     * @throws UtsException
     */
    public function cancel(UtsNotification $sent): UtsNotification
    {
        $environment = $this->guard->environment();

        if (! $this->isActive($sent)) {
            throw new UtsException('Bu çıkarma bildirimi ÜTS\'de geçerli değil; iptal edilecek bir şey yok.');
        }

        $attributes = $sent->only(['patient_usage_id', 'uno', 'product_name', 'lot', 'serial', 'quantity', 'details']) + [
            'user_id' => auth()->id(),
            'type' => UtsNotification::TYPE_REMOVAL_CANCEL,
            'source_notification_id' => $sent->uts_notification_id,
            'environment' => $environment,
        ];

        try {
            $code = $this->client->cancelRemoval($sent->uts_notification_id);
        } catch (UtsException $e) {
            UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_FAILED, 'message' => $e->getMessage()]);

            throw $e;
        }

        return DB::transaction(function () use ($sent, $attributes, $code) {
            PatientUsage::whereKey($sent->patient_usage_id)->update(['removed_on' => null, 'removal_reason' => null, 'removal_note' => null]);

            return UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_SENT, 'uts_notification_id' => $code]);
        });
    }

    /** Kullanımın ÜTS'de geçerli (iptal edilmemiş) çıkarma bildirimi var mı? */
    public function hasActive(PatientUsage $usage): bool
    {
        return UtsNotification::where('patient_usage_id', $usage->id)
            ->where('type', UtsNotification::TYPE_REMOVAL)
            ->where('status', UtsNotification::STATUS_SENT)
            ->get()
            ->contains(fn (UtsNotification $n) => $this->isActive($n));
    }

    public function isActive(UtsNotification $sent): bool
    {
        return $sent->type === UtsNotification::TYPE_REMOVAL
            && $sent->status === UtsNotification::STATUS_SENT
            && ! UtsNotification::where('type', UtsNotification::TYPE_REMOVAL_CANCEL)
                ->where('status', UtsNotification::STATUS_SENT)
                ->where('source_notification_id', $sent->uts_notification_id)
                ->exists();
    }

    /** Başarısız çıkarma tekrar edilebilir mi (çıkarma hâlâ kayıtlı ve sonradan gönderilmemiş)? */
    public function canRetry(UtsNotification $failed): bool
    {
        if ($failed->type !== UtsNotification::TYPE_REMOVAL || $failed->status !== UtsNotification::STATUS_FAILED) {
            return false;
        }

        $usage = PatientUsage::find($failed->patient_usage_id);

        return $usage?->removed_on !== null && ! $this->hasActive($usage);
    }

    private function send(PatientUsage $usage, string $environment): UtsNotification
    {
        $report = new RemovalReport($this->usages->reportFor($usage, $usage->removed_on), $usage->removal_reason, $usage->removal_note);
        $attributes = [
            'user_id' => auth()->id(),
            'patient_usage_id' => $usage->id,
            'type' => UtsNotification::TYPE_REMOVAL,
            'environment' => $environment,
            'uno' => (string) $usage->product->gtin,
            'product_name' => $usage->product->name,
            'lot' => $usage->lot->lot_no,
            'serial' => $usage->serial?->serial_no,
            'quantity' => 1,
            'details' => ['reason' => $usage->removal_reason, 'description' => $usage->removal_note, 'date' => $usage->removed_on->toDateString()],
        ];

        try {
            $code = $this->client->reportRemoval($report);
        } catch (UtsException $e) {
            UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_FAILED, 'message' => $e->getMessage()]);

            throw $e;
        }

        return UtsNotification::create($attributes + ['status' => UtsNotification::STATUS_SENT, 'uts_notification_id' => $code]);
    }

    private function ensureValid(PatientUsage $usage, string $reason, ?string $description, CarbonInterface $date): void
    {
        if ($usage->cancelled_at !== null) {
            throw new UtsException('Stok çıkışı iptal edilmiş bir kullanım hastadan çıkarılamaz.');
        }

        if ($usage->removed_on !== null) {
            throw new UtsException('Bu ürün hastadan zaten çıkarıldı.');
        }

        if (! array_key_exists($reason, RemovalReport::REASONS)) {
            throw new UtsException('Geçerli bir çıkarma gerekçesi seçin.');
        }

        if ($reason === 'DIGER' && blank($description)) {
            throw new UtsException('"Diğer" gerekçesi için açıklama yazın.');
        }

        if ($date->toDateString() > today()->toDateString()) {
            throw new UtsException('Çıkarma tarihi ileri bir tarih olamaz.');
        }

        if ($date->toDateString() < $usage->used_on->toDateString()) {
            throw new UtsException('Çıkarma tarihi kullanım tarihinden ('.$usage->used_on->format('d.m.Y').') önce olamaz.');
        }
    }
}
