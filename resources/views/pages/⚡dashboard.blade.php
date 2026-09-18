<?php

use App\Domain\Reporting\Services\DashboardMetricsService;
use App\Domain\Stock\Support\StockLevel;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    public function with(DashboardMetricsService $metrics): array
    {
        return [
            'summary' => $metrics->summaryFor(auth()->user()->organization_id),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <p class="text-[13px] text-ink-muted mb-1">{{ now()->translatedFormat('d F Y, l') }}</p>
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Kontrol Paneli</h1>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-px bg-line rounded-lg overflow-hidden border border-line">
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] text-ink-muted">Aktif Ürün</p>
            <p class="text-[26px] font-medium tabular-nums mt-1">{{ $summary['productCount'] }}</p>
        </div>
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] text-ink-muted">Toplam Stok Miktarı</p>
            <p class="text-[26px] font-medium tabular-nums mt-1">{{ Number::format($summary['totalStockQuantity'], precision: 0) }}</p>
        </div>
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] text-ink-muted">Toplam Stok Değeri</p>
            <p class="text-[26px] font-medium tabular-nums mt-1">{{ Number::format($summary['totalStockValue'], precision: 2) }} ₺</p>
        </div>
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] text-ink-muted">Personel</p>
            <p class="text-[26px] font-medium tabular-nums mt-1">{{ $summary['staffCount'] }}</p>
        </div>
    </div>

    <div class="mt-2 grid grid-cols-3 gap-px bg-line rounded-lg overflow-hidden border border-line">
        <div class="bg-surface px-5 py-4">
            <p class="text-[12px] text-ink-muted">Kategori</p>
            <p class="text-[18px] font-medium tabular-nums mt-0.5">{{ $summary['categoryCount'] }}</p>
        </div>
        <div class="bg-surface px-5 py-4">
            <p class="text-[12px] text-ink-muted">Tedarikçi</p>
            <p class="text-[18px] font-medium tabular-nums mt-0.5">{{ $summary['supplierCount'] }}</p>
        </div>
        <div class="bg-surface px-5 py-4">
            <p class="text-[12px] text-ink-muted">Şube</p>
            <p class="text-[18px] font-medium tabular-nums mt-0.5">{{ $summary['branchCount'] }}</p>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-px bg-line rounded-lg overflow-hidden border border-line">
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] {{ StockLevel::Normal->badgeClasses() }} inline-flex px-2 py-0.5 rounded">{{ StockLevel::Normal->label() }}</p>
            <p class="text-[26px] font-medium tabular-nums mt-2">{{ $summary['levelCounts']['normal'] }}</p>
            <p class="text-[12px] text-ink-muted mt-0.5">ürün normal seviyede</p>
        </div>
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] {{ StockLevel::Low->badgeClasses() }} inline-flex px-2 py-0.5 rounded">{{ StockLevel::Low->label() }}</p>
            <p class="text-[26px] font-medium tabular-nums mt-2">{{ $summary['levelCounts']['low'] }}</p>
            <p class="text-[12px] text-ink-muted mt-0.5">ürün düşük seviyede</p>
        </div>
        <div class="bg-surface px-5 py-5">
            <p class="text-[13px] {{ StockLevel::Critical->badgeClasses() }} inline-flex px-2 py-0.5 rounded">{{ StockLevel::Critical->label() }}</p>
            <p class="text-[26px] font-medium tabular-nums mt-2">{{ $summary['levelCounts']['critical'] }}</p>
            <p class="text-[12px] text-ink-muted mt-0.5">ürün kritik seviyede</p>
        </div>
    </div>

    <div class="mt-10 grid grid-cols-1 lg:grid-cols-2 gap-6">
        <section class="border border-line rounded-lg bg-surface p-6">
            <h2 class="text-[15px] font-medium text-ink mb-4">Bugün / Bu Ay</h2>
            <dl class="space-y-3 text-[14px]">
                <div class="flex items-center justify-between">
                    <dt class="text-ink-muted">Bugünkü Giriş</dt>
                    <dd class="tabular-nums text-status-good">+{{ Number::format($summary['todayIn'], precision: 2) }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-ink-muted">Bugünkü Çıkış</dt>
                    <dd class="tabular-nums text-status-critical">-{{ Number::format($summary['todayOut'], precision: 2) }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-ink-muted" title="Yalnızca klinik içi kullanım ve sarf çıkışları">Bu Ayki Kullanım</dt>
                    <dd class="tabular-nums">{{ Number::format($summary['monthlyUsage'], precision: 2) }}</dd>
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <dt class="text-ink-muted">Bu Ayki Diğer Çıkışlar <span class="text-[12px]">(Kayıp/Fire/İade/Transfer)</span></dt>
                        <dd class="tabular-nums">{{ Number::format($summary['monthlyOtherOut'], precision: 2) }}</dd>
                    </div>
                    @if ($summary['monthlyOtherOutByReason'])
                        <dl class="mt-1.5 ml-3 space-y-1 text-[13px]">
                            @foreach ($summary['monthlyOtherOutByReason'] as $reasonLabel => $reasonQuantity)
                                <div class="flex items-center justify-between">
                                    <dt class="text-ink-muted">{{ $reasonLabel }}</dt>
                                    <dd class="tabular-nums text-ink-muted">{{ Number::format($reasonQuantity, precision: 2) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-ink-muted">SKT'ye {{ config('stock.levels.expiry_warning_days') }} Gün veya Az Kalan Lot</dt>
                    <dd class="tabular-nums text-status-warn">{{ $summary['expiringSoonLotCount'] }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-ink-muted">Süresi Geçmiş Lot</dt>
                    <dd class="tabular-nums text-status-critical">{{ $summary['expiredLotCount'] }}</dd>
                </div>
            </dl>
        </section>

        <section class="border border-line rounded-lg bg-surface p-6">
            <h2 class="text-[15px] font-medium text-ink mb-4">Şube Bazlı Stok Dağılımı</h2>
            @if (count($summary['branchDistribution']) > 0)
                @php $maxBranchQty = max(array_column($summary['branchDistribution'], 'quantity')) ?: 1; @endphp
                <div class="space-y-3">
                    @foreach ($summary['branchDistribution'] as $branch)
                        <div>
                            <div class="flex items-center justify-between text-[13px] mb-1">
                                <span class="text-ink">{{ $branch['name'] }}</span>
                                <span class="text-ink-muted tabular-nums">{{ Number::format($branch['quantity'], precision: 0) }}</span>
                            </div>
                            <div class="h-1.5 rounded-full bg-line overflow-hidden">
                                <div class="h-full bg-brand-500 rounded-full" style="width: {{ max(4, round($branch['quantity'] / $maxBranchQty * 100)) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-[13px] text-ink-muted">Henüz stok hareketi yok.</p>
            @endif
        </section>

        <section class="border border-line rounded-lg bg-surface p-6 lg:col-span-2">
            <h2 class="text-[15px] font-medium text-ink mb-4">En Çok Kullanılan Ürünler</h2>
            @if (count($summary['topUsedProducts']) > 0)
                <ol class="divide-y divide-line">
                    @foreach ($summary['topUsedProducts'] as $index => $product)
                        <li class="flex items-center justify-between py-2.5 text-[14px]">
                            <span class="text-ink"><span class="text-ink-muted mr-2 tabular-nums">{{ $index + 1 }}.</span>{{ $product['name'] }}</span>
                            <span class="text-ink-muted tabular-nums">{{ Number::format($product['used'], precision: 2) }}</span>
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="text-[13px] text-ink-muted">Henüz çıkış hareketi yok.</p>
            @endif
        </section>
    </div>

    <p class="mt-6 text-[12px] text-ink-muted">
        Rakamlar en fazla {{ (int) (config('reporting.dashboard_cache_ttl') / 60) ?: 1 }} dakikalığına önbelleklenir; bir stok hareketi yapıldığında anında güncellenir.
    </p>
</div>
