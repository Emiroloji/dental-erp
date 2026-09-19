<?php

namespace App\Domain\Assistant\Support;

use App\Domain\Forecasting\Support\ForecastRisk;
use App\Domain\Stock\Support\StockMovementType;
use Illuminate\Support\Carbon;

/**
 * Yapay zekâ sağlayıcısına gönderilen bağlamın TAMAMI. Kullanıcı kararı:
 * yalnızca soru metni ve filtre seçenekleri gider — stok miktarı, değer,
 * ürün listesi, lot, kişi bilgisi asla bu nesneye girmez.
 */
final class InterpreterContext
{
    /**
     * @param  array<int, array{id: int, name: string}>  $branches
     * @param  array<int, array{id: int, name: string}>  $warehouses
     * @param  array<int, array{id: int, name: string}>  $categories
     * @param  array<int, array{id: int, name: string}>  $suppliers
     */
    public function __construct(
        public readonly Carbon $today,
        public readonly array $branches,
        public readonly array $warehouses,
        public readonly array $categories,
        public readonly array $suppliers,
    ) {}

    /**
     * Sağlayıcıdan bağımsız sistem talimatı.
     */
    public function instructions(): string
    {
        $reports = collect(AssistantReport::cases())
            ->map(fn (AssistantReport $report) => "- {$report->value}: {$report->description()}")
            ->implode("\n");
        $types = collect(StockMovementType::cases())->map(fn ($type) => "{$type->value} ({$type->label()})")->implode(', ');
        $risks = collect(ForecastRisk::cases())->map(fn ($risk) => "{$risk->value} ({$risk->label()})")->implode(', ');

        return <<<TXT
        Sen bir diş kliniği stok yönetim sisteminin rapor asistanısın. Görevin, kullanıcının Türkçe sorusunu aşağıdaki rapor türlerinden birine ve filtrelere çevirmek. Cevap üretme, rakam uydurma; yalnızca sorguyu yapılandır. Rakamları sistem kendi raporlarından hesaplayacak.

        Rapor türleri:
        {$reports}

        Kurallar:
        - Bugünün tarihi: {$this->today->toDateString()} ({$this->today->locale('tr')->dayName}). Tarihleri YYYY-MM-DD biçiminde ver. "bu ay" = ayın 1'i ile bugün arası; "geçen ay" = önceki takvim ayının tamamı; "bu hafta" = pazartesiden bugüne; "son N gün" = bugün dahil geriye N gün; "dün" = yalnızca dün. Tarih belirtilmemişse from ve to null olsun.
        - Şube, depo, kategori ve tedarikçi için YALNIZCA aşağıdaki listelerdeki id'leri kullan. Soruda geçen ad listede yoksa ilgili alanı null bırak.
        - Ürün adı veya kodu geçiyorsa search alanına yalnızca o ürün ifadesini yaz (ör. "eldiven"). Ürün listesi sana verilmez.
        - movement_type şunlardan biri olabilir: {$types}.
        - risk (yalnızca forecast raporu için) şunlardan biri olabilir: {$risks}.
        - "En çok 5", "ilk 10" gibi bir sayı istenirse limit alanına yaz.
        - Soru stok, kullanım, satın alma, hareket veya tahminle ilgili değilse ya da hangi raporun istendiği anlaşılmıyorsa understood=false yap ve clarification alanına kısa bir Türkçe açıklama yaz.

        Filtre seçenekleri (JSON):
        {$this->optionsJson()}
        TXT;
    }

    public function optionsJson(): string
    {
        return json_encode([
            'branches' => $this->branches,
            'warehouses' => $this->warehouses,
            'categories' => $this->categories,
            'suppliers' => $this->suppliers,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Sağlayıcıdan bağımsız cevap şeması (JSON Schema alt kümesi).
     *
     * @return array<string, mixed>
     */
    public static function responseSchema(): array
    {
        $nullableInt = ['type' => 'integer', 'nullable' => true];
        $nullableString = ['type' => 'string', 'nullable' => true];

        return [
            'type' => 'object',
            'properties' => [
                'understood' => ['type' => 'boolean'],
                'report' => ['type' => 'string', 'nullable' => true, 'enum' => array_column(AssistantReport::cases(), 'value')],
                'from' => $nullableString,
                'to' => $nullableString,
                'branch_id' => $nullableInt,
                'warehouse_id' => $nullableInt,
                'category_id' => $nullableInt,
                'supplier_id' => $nullableInt,
                'movement_type' => ['type' => 'string', 'nullable' => true, 'enum' => array_column(StockMovementType::cases(), 'value')],
                'risk' => ['type' => 'string', 'nullable' => true, 'enum' => array_column(ForecastRisk::cases(), 'value')],
                'search' => $nullableString,
                'limit' => $nullableInt,
                'clarification' => $nullableString,
            ],
            'required' => ['understood'],
        ];
    }
}
