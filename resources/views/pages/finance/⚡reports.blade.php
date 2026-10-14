<?php

use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Reports\AgingReportService;
use App\Domain\Finance\Reports\CashFlowProjectionService;
use App\Domain\Finance\Reports\CashFlowReportService;
use App\Domain\Finance\Reports\FinanceReportFilters;
use App\Domain\Finance\Reports\IncomeExpenseReportService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\ObligationDirection;
use App\Domain\Organization\Models\Branch;
use App\Domain\Reporting\Services\ReportExportService;
use App\Domain\Reporting\Support\ReportType;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    /** Sekme anahtarı → dışa aktarım türü. */
    private const REPORTS = [
        'income_expense' => ReportType::FinanceIncomeExpense,
        'cash_flow' => ReportType::FinanceCashFlow,
        'aging' => ReportType::FinanceAging,
    ];

    #[Url]
    public string $report = 'income_expense';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $branch = '';

    #[Url]
    public string $account = '';

    #[Url]
    public string $days = '90';

    #[Url]
    public string $direction = 'receivable';

    public function mount(): void
    {
        $this->normalize();
    }

    public function updated(string $property): void
    {
        $this->normalize();
    }

    public function selectReport(string $report): void
    {
        $this->report = $report;
        // Her sekmenin varsayılan dönemi farklıdır.
        $this->from = '';
        $this->to = '';
        $this->normalize();
    }

    /**
     * URL'den gelen her değer süzgeç sınıfından ve izin listelerinden geçer; ekran
     * ve dışa aktarım aynı normalleşmiş süzgeci kullanır.
     */
    private function normalize(): void
    {
        $this->filters();
    }

    private function filters(): FinanceReportFilters
    {
        if (! array_key_exists($this->report, self::REPORTS)) {
            $this->report = 'income_expense';
        }

        $today = FinanceCalendar::today();
        $from = $this->report === 'cash_flow' ? $today->subMonthsNoOverflow(5)->startOfMonth() : $today->startOfMonth();

        $filters = FinanceReportFilters::fromArray([
            'from' => $this->from,
            'to' => $this->to,
            'branch_id' => $this->branch,
            'account_id' => $this->account,
            'days' => $this->days,
            'direction' => $this->direction,
        ], $from, $today);

        // Kapsamlı listede olmayan şube/hesap kimliği süzgeci sessizce kaldırır.
        $branchId = $filters->branchId !== null && Branch::where('organization_id', auth()->user()->organization_id)->whereKey($filters->branchId)->exists() ? $filters->branchId : null;
        $accountId = $filters->accountId !== null && FinanceAccount::whereKey($filters->accountId)->exists() ? $filters->accountId : null;

        $filters = new FinanceReportFilters($filters->from, $filters->to, $branchId, $accountId, $filters->direction ?? ObligationDirection::Receivable, $filters->days);

        $this->from = $filters->from->toDateString();
        $this->to = $filters->to->toDateString();
        $this->branch = (string) $branchId;
        $this->account = (string) $accountId;
        $this->days = (string) $filters->days;
        $this->direction = $filters->direction->value;

        return $filters;
    }

    public function export(string $format, ReportExportService $exports): void
    {
        Gate::authorize('finance.viewAny');

        if (! in_array($format, ReportExportService::FORMATS, true)) {
            return;
        }

        $filters = $this->filters();
        $exports->request(auth()->user(), self::REPORTS[$this->report], $format, ['filters' => $filters->toArray()]);

        $this->dispatch('report-export-queued');
        session()->flash('export-status', 'Rapor hazırlanıyor. Hazır olduğunda bildirim alacaksınız; "Dışa Aktarımlar" listesinden indirebilirsiniz.');
    }

    public function with(IncomeExpenseReportService $incomeExpense, CashFlowReportService $cashFlow, CashFlowProjectionService $projection, AgingReportService $aging): array
    {
        $filters = $this->filters();

        $data = match ($this->report) {
            'income_expense' => ['income' => $incomeExpense->report($filters)],
            'cash_flow' => ['cash' => $cashFlow->actual($filters), 'projection' => $projection->project($filters->days)],
            'aging' => [
                'aging' => $aging->report($filters->direction),
                'bucketLabels' => array_combine(AgingReportService::BUCKETS, array_map($aging->bucketLabel(...), AgingReportService::BUCKETS)),
            ],
        };

        return $data + [
            'branches' => Branch::where('organization_id', auth()->user()->organization_id)->where('status', 'active')->orderBy('name')->get(),
            'accounts' => FinanceAccount::orderBy('name')->get(['id', 'name']),
        ];
    }
};
?>

<div>
    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">Finans</h1>
            <p class="text-[14px] text-ink-muted mt-1 max-w-2xl">Gelir-gider, nakit akışı ve cari yaşlandırma raporları. Tutarlar hareket defterinden her seferinde yeniden hesaplanır.</p>
        </div>
        <div class="mt-4 sm:mt-0 flex gap-2 shrink-0">
            <button wire:click="export('xlsx')" wire:loading.attr="disabled" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">Excel'e Aktar</button>
            <button wire:click="export('pdf')" wire:loading.attr="disabled" class="text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">PDF'e Aktar</button>
        </div>
    </div>

    <x-finance-tabs />

    @if (session('export-status'))
        <div class="mb-4 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('export-status') }}</div>
    @endif

    <livewire:report-exports />

    <div class="mb-5 inline-flex rounded-md border border-line bg-surface p-0.5 text-[13px]" role="tablist">
        @foreach (['income_expense' => 'Gelir-Gider', 'cash_flow' => 'Nakit Akışı', 'aging' => 'Cari Yaşlandırma'] as $key => $label)
            <button wire:click="selectReport('{{ $key }}')" role="tab" aria-selected="{{ $report === $key ? 'true' : 'false' }}"
                    class="px-3 py-1.5 rounded {{ $report === $key ? 'bg-panel-900 text-white' : 'text-ink-muted hover:text-ink' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="mb-5 flex flex-wrap items-end gap-3">
        @if ($report === 'aging')
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Yön</label>
                <select wire:model.live="direction" class="border border-line rounded-md px-3 py-1.5 text-[14px] bg-surface">
                    <option value="receivable">Tahsil edilecek</option>
                    <option value="payable">Ödenecek</option>
                </select>
            </div>
        @else
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Başlangıç</label>
                <input type="date" wire:model.live="from" class="border border-line rounded-md px-3 py-1.5 text-[14px] bg-surface">
            </div>
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Bitiş</label>
                <input type="date" wire:model.live="to" class="border border-line rounded-md px-3 py-1.5 text-[14px] bg-surface">
            </div>
            @if ($report === 'income_expense')
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Şube</label>
                    <select wire:model.live="branch" class="border border-line rounded-md px-3 py-1.5 text-[14px] bg-surface">
                        <option value="">Tüm şubeler</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Hesap</label>
                <select wire:model.live="account" class="border border-line rounded-md px-3 py-1.5 text-[14px] bg-surface">
                    <option value="">Tüm hesaplar</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($report === 'cash_flow')
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Projeksiyon</label>
                    <select wire:model.live="days" class="border border-line rounded-md px-3 py-1.5 text-[14px] bg-surface">
                        @foreach ([30, 60, 90] as $d)
                            <option value="{{ $d }}">{{ $d }} gün</option>
                        @endforeach
                    </select>
                </div>
            @endif
        @endif
    </div>

    @if ($report === 'income_expense')
        <x-finance.report-income-expense :report="$income" />
    @elseif ($report === 'cash_flow')
        <x-finance.report-cash-flow :actual="$cash" :projection="$projection" />
    @else
        <x-finance.report-aging :report="$aging" :labels="$bucketLabels" />
    @endif
</div>
