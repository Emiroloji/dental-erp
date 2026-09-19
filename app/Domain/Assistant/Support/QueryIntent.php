<?php

namespace App\Domain\Assistant\Support;

use App\Domain\Forecasting\Support\ForecastRisk;
use App\Domain\Reporting\Support\ReportFilters;
use App\Domain\Stock\Support\StockMovementType;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Yapay zekânın cevabından çıkarılan, DOĞRULANMIŞ rapor sorgusu. Sağlayıcıya
 * güvenilmez: id'ler yalnızca kullanıcıya sunulan seçeneklerdense kabul
 * edilir, tarih/enum/limit değerleri kontrol edilir. Geçersiz değerler
 * atılır ve kullanıcıya uyarı olarak gösterilir.
 */
final class QueryIntent
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly bool $understood,
        public readonly ?AssistantReport $report = null,
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly ?int $branchId = null,
        public readonly ?int $warehouseId = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $supplierId = null,
        public readonly ?StockMovementType $movementType = null,
        public readonly ?ForecastRisk $risk = null,
        public readonly ?string $search = null,
        public readonly int $limit = 10,
        public readonly ?string $clarification = null,
        public readonly array $warnings = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromInterpreter(array $raw, InterpreterContext $context): self
    {
        $report = AssistantReport::tryFrom((string) ($raw['report'] ?? ''));

        if (! ($raw['understood'] ?? false) || $report === null) {
            $clarification = trim((string) ($raw['clarification'] ?? ''));

            return new self(
                understood: false,
                clarification: $clarification !== '' ? mb_substr($clarification, 0, 300) : 'Soruyu bir rapora çeviremedim. Örneğin "Geçen ay en çok kullanılan 5 ürün" gibi sorabilirsiniz.',
            );
        }

        $warnings = [];

        $pick = function (string $key, array $options, string $label) use ($raw, &$warnings): ?int {
            $value = $raw[$key] ?? null;

            if ($value === null || $value === '') {
                return null;
            }

            if (is_numeric($value) && in_array((int) $value, array_column($options, 'id'), true)) {
                return (int) $value;
            }

            $warnings[] = "{$label} filtresi erişiminiz dahilinde bulunamadığı için uygulanmadı.";

            return null;
        };

        [$from, $to] = $report->usesDates() ? self::dates($raw, $warnings) : [null, null];

        $branchId = $pick('branch_id', $context->branches, 'Şube');
        $warehouseId = $pick('warehouse_id', $context->warehouses, 'Depo');

        $search = trim((string) ($raw['search'] ?? ''));
        $maxRows = (int) config('assistant.max_rows');
        $limit = is_numeric($raw['limit'] ?? null) ? (int) $raw['limit'] : (int) config('assistant.default_rows');

        return new self(
            understood: true,
            report: $report,
            from: $from,
            to: $to,
            branchId: $branchId,
            warehouseId: $warehouseId,
            categoryId: $pick('category_id', $context->categories, 'Kategori'),
            supplierId: $pick('supplier_id', $context->suppliers, 'Tedarikçi'),
            movementType: $report === AssistantReport::Movements ? StockMovementType::tryFrom((string) ($raw['movement_type'] ?? '')) : null,
            risk: $report === AssistantReport::Forecast ? ForecastRisk::tryFrom((string) ($raw['risk'] ?? '')) : null,
            search: $search !== '' ? mb_substr($search, 0, 100) : null,
            limit: max(1, min($maxRows, $limit)),
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $warnings
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function dates(array $raw, array &$warnings): array
    {
        $parse = function (mixed $value): ?Carbon {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }

            try {
                return Carbon::createFromFormat('!Y-m-d', $value);
            } catch (Throwable) {
                return null;
            }
        };

        $today = now()->startOfDay();
        $from = $parse($raw['from'] ?? null);
        $to = $parse($raw['to'] ?? null);

        if (($raw['from'] ?? null) !== null && $from === null || ($raw['to'] ?? null) !== null && $to === null) {
            $warnings[] = 'Anlaşılamayan tarih yerine bu ay kullanıldı.';
            $from = $to = null;
        }

        // Varsayılan dönem rapor ekranlarıyla aynı: bu ayın başından bugüne.
        $from ??= $today->copy()->startOfMonth();
        $to ??= $today->copy();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    public function filters(): ReportFilters
    {
        return new ReportFilters(
            from: $this->from,
            to: $this->to,
            branchId: $this->branchId,
            warehouseId: $this->warehouseId,
            categoryId: $this->categoryId,
            supplierId: $this->supplierId,
        );
    }

    /**
     * Denetim/geçmiş için saklanan biçim.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'understood' => $this->understood,
            'report' => $this->report?->value,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'branch_id' => $this->branchId,
            'warehouse_id' => $this->warehouseId,
            'category_id' => $this->categoryId,
            'supplier_id' => $this->supplierId,
            'movement_type' => $this->movementType?->value,
            'risk' => $this->risk?->value,
            'search' => $this->search,
            'limit' => $this->limit,
            'clarification' => $this->clarification,
            'warnings' => $this->warnings,
        ];
    }
}
