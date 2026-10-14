<?php

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Organization\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * Finans raporlarının ortak süzgeçleri. Ekran girdisinden güvenle üretilir:
 * geçersiz veya eksik değerlerde varsayılana düşer, hata fırlatmaz.
 */
final readonly class FinanceReportFilters
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $branchId = null,
        public ?int $accountId = null,
        public ?ObligationDirection $direction = null,
        public int $days = 90,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input, CarbonImmutable $defaultFrom, CarbonImmutable $defaultTo): self
    {
        $from = self::parseDate($input['from'] ?? null);
        $to = self::parseDate($input['to'] ?? null);

        // Ters aralık veya 2 yıldan uzun dönem: ikisi de varsayılana döner.
        if ($from === null || $to === null || $from->gt($to) || $from->addYears(2)->lt($to)) {
            $from = $defaultFrom;
            $to = $defaultTo;
        }

        $days = (int) ($input['days'] ?? 90);

        return new self(
            from: $from,
            to: $to,
            branchId: self::parseId($input['branch_id'] ?? null),
            accountId: self::parseId($input['account_id'] ?? null),
            direction: is_string($input['direction'] ?? null) ? ObligationDirection::tryFrom($input['direction']) : null,
            days: in_array($days, [30, 60, 90], true) ? $days : 90,
        );
    }

    /**
     * Kuyruğa yazılacak düz değerler; fromArray ile aynen geri kurulur.
     *
     * @return array{from: string, to: string, branch_id: ?int, account_id: ?int, direction: ?string, days: int}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'branch_id' => $this->branchId,
            'account_id' => $this->accountId,
            'direction' => $this->direction?->value,
            'days' => $this->days,
        ];
    }

    /**
     * Rapor başlığında gösterilecek süzgeç özeti; adlar kapsamlı modellerden okunur.
     */
    public function describe(): string
    {
        $parts = ['Tarih: '.$this->from->format('d.m.Y').' – '.$this->to->format('d.m.Y')];

        if ($this->branchId !== null) {
            $parts[] = 'Şube: '.(Branch::find($this->branchId)?->name ?? '—');
        }

        if ($this->accountId !== null) {
            $parts[] = 'Hesap: '.(FinanceAccount::find($this->accountId)?->name ?? '—');
        }

        if ($this->direction !== null) {
            $parts[] = 'Yön: '.$this->direction->label();
        }

        return implode(' · ', $parts);
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        // Y-m-d gidiş-dönüş: 2026-02-30 gibi taşan veya 2026-1-5 gibi eksik biçimler reddedilir.
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function parseId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
