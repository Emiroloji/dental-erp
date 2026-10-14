<?php

use App\Domain\Finance\Exceptions\FinanceRuleException;
use App\Domain\Finance\Models\FinanceAccount;
use App\Domain\Finance\Models\FinanceReconciliation;
use App\Domain\Finance\Reports\AccountStatementService;
use App\Domain\Finance\Services\FinanceBalanceService;
use App\Domain\Finance\Services\LedgerService;
use App\Domain\Finance\Services\ReconciliationService;
use App\Domain\Finance\Support\FinanceCalendar;
use App\Domain\Finance\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::authenticated')] class extends Component
{
    /** Domain hata alanı → mutabakat formundaki özellik; bilinmeyen alan tutara düşer. */
    private const RECONCILE_FIELDS = ['date' => 'statementDate', 'account' => 'statementBalance', 'amount' => 'statementBalance'];

    /** Sorguyu sınırlı tutmak için en geniş ekstre aralığı (gün). */
    private const MAX_RANGE_DAYS = 731;

    public FinanceAccount $account;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public string $statementDate = '';

    public string $statementBalance = '';

    public string $note = '';

    public ?int $previewSystem = null;

    public ?int $previewStatement = null;

    public ?int $previewDifference = null;

    public function mount(FinanceAccount $account): void
    {
        $this->account = $account;
        $this->statementDate = FinanceCalendar::today()->toDateString();
        $this->normalizePeriod();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->normalizePeriod();
        }

        if (in_array($property, ['statementDate', 'statementBalance'], true)) {
            $this->resetPreview();
        }
    }

    private function normalizePeriod(): void
    {
        $from = $this->parseDate($this->from);
        $to = $this->parseDate($this->to);

        if ($from === null || $to === null || $from->greaterThan($to) || $from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $today = FinanceCalendar::today();
            $this->from = $today->startOfMonth()->toDateString();
            $this->to = $today->toDateString();
        }
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            return null;
        }

        return $date !== false && $date->toDateString() === $value ? $date : null;
    }

    private function resetPreview(): void
    {
        $this->previewSystem = null;
        $this->previewStatement = null;
        $this->previewDifference = null;
    }

    /** @return array{0: CarbonImmutable, 1: int}|null */
    private function statementInput(): ?array
    {
        $this->validate(['statementDate' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:500']]);

        try {
            return [CarbonImmutable::parse($this->statementDate), Money::parse($this->statementBalance)];
        } catch (\InvalidArgumentException $e) {
            $this->addError('statementBalance', $e->getMessage());

            return null;
        }
    }

    public function compare(ReconciliationService $reconciliations, LedgerService $ledger): void
    {
        Gate::authorize('finance.create');
        $this->resetPreview();

        if (($input = $this->statementInput()) === null) {
            return;
        }

        try {
            // Karşılaştırma aşamasında da kaydetme kuralları denenir; hata kaydetmeden önce görünsün.
            $ledger->assertDateWritable($this->account, $input[0]);
            $preview = $reconciliations->preview($this->account, $input[0], $input[1]);
        } catch (FinanceRuleException $e) {
            $this->addError(self::RECONCILE_FIELDS[$e->field] ?? 'statementBalance', $e->getMessage());

            return;
        }

        $this->previewSystem = $preview['system_balance'];
        $this->previewStatement = $input[1];
        $this->previewDifference = $preview['difference'];
    }

    public function reconcile(bool $adjust, ReconciliationService $reconciliations): void
    {
        Gate::authorize('finance.create');

        if (($input = $this->statementInput()) === null) {
            return;
        }

        try {
            $record = $reconciliations->reconcile($this->account, $input[0], $input[1], auth()->user(), $adjust, $this->note);
        } catch (FinanceRuleException $e) {
            $this->addError(self::RECONCILE_FIELDS[$e->field] ?? 'statementBalance', $e->getMessage());

            return;
        }

        $this->reset('statementBalance', 'note');
        $this->resetPreview();
        session()->flash('status', $record->difference === 0
            ? 'Mutabakat kaydedildi; ekstre ile defter bakiyesi eşit.'
            : ($adjust ? 'Mutabakat kaydedildi ve fark düzeltme hareketiyle kapatıldı.' : 'Mutabakat farkıyla birlikte kaydedildi.'));
    }

    public function with(FinanceBalanceService $balances, AccountStatementService $statements): array
    {
        $this->normalizePeriod();

        return [
            'balance' => $balances->balance($this->account),
            'statement' => $statements->statement($this->account, CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to)),
            'reconciliations' => FinanceReconciliation::where('account_id', $this->account->id)->with(['adjustment.reversal', 'actor'])->orderByDesc('id')->limit(50)->get(),
        ];
    }
};
?>

<div>
    <a href="{{ route('finance.accounts') }}" wire:navigate class="inline-block mb-3 text-[13px] text-ink-muted hover:text-ink hover:underline">← Hesaplar</a>

    <div class="mb-6 sm:flex sm:items-end sm:justify-between">
        <div>
            <h1 class="text-[22px] font-medium tracking-tight text-ink">
                {{ $account->name }}
                @unless ($account->isActive())
                    <span class="ml-1 align-middle inline-flex px-2 py-0.5 rounded text-[12px] bg-line text-ink-muted">Pasif</span>
                @endunless
            </h1>
            <p class="text-[14px] text-ink-muted mt-1">
                {{ $account->type->label() }}
                @if ($account->iban)
                    <span class="font-mono whitespace-nowrap">· {{ $account->iban }}</span>
                @endif
            </p>
        </div>
        <div class="mt-4 sm:mt-0 sm:text-right">
            <div class="text-[13px] text-ink-muted">Güncel bakiye</div>
            <div @class(['text-[22px] font-medium tabular-nums', 'text-ink' => $balance >= 0, 'text-finance-out' => $balance < 0])>{{ Money::format($balance) }}</div>
        </div>
    </div>

    <x-finance-tabs />

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ session('status') }}</div>
    @endif

    <div class="mb-3 flex flex-wrap items-end gap-3">
        <h2 class="text-[15px] font-medium text-ink mr-auto">Hesap ekstresi</h2>
        <div>
            <label class="block text-[12px] text-ink-muted mb-1">Başlangıç</label>
            <input type="date" wire:model.live="from" class="border border-line rounded-md px-3 py-1.5 text-[14px] bg-surface">
        </div>
        <div>
            <label class="block text-[12px] text-ink-muted mb-1">Bitiş</label>
            <input type="date" wire:model.live="to" class="border border-line rounded-md px-3 py-1.5 text-[14px] bg-surface">
        </div>
    </div>

    <section class="mb-8 border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Tarih</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Tür</th>
                    <th class="px-3 sm:px-5 py-3 font-medium">Açıklama</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium text-right">Giriş</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium text-right">Çıkış</th>
                    <th class="sm:hidden px-3 py-3 font-medium text-right">Tutar</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Bakiye</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr class="bg-canvas/60 text-ink-muted text-[13px]">
                    <td class="px-3 sm:px-5 py-2.5 whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($from)->format('d.m.Y') }}</td>
                    <td class="hidden sm:table-cell"></td>
                    <td class="px-3 sm:px-5 py-2.5">Dönem başı bakiyesi</td>
                    <td class="hidden sm:table-cell" colspan="2"></td>
                    <td class="sm:hidden"></td>
                    <td class="px-3 sm:px-5 py-2.5 text-right whitespace-nowrap tabular-nums">{{ Money::format($statement['opening']) }}</td>
                </tr>
                @forelse ($statement['rows'] as $row)
                    @php
                        $entry = $row['entry'];
                        $signed = $entry->signedAmount();
                        $struck = $entry->reversal !== null;
                    @endphp
                    <tr wire:key="row-{{ $entry->id }}" @class(['text-ink-muted' => $struck])>
                        <td class="px-3 sm:px-5 py-3 whitespace-nowrap">{{ $entry->occurred_on->format('d.m.Y') }}</td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap">{{ $entry->label() }}</td>
                        <td class="px-3 sm:px-5 py-3">
                            <div @class(['line-through' => $struck])>{{ $entry->description ?: $entry->label() }}</div>
                            <div class="text-[12px] text-ink-muted">
                                <span class="sm:hidden">{{ $entry->label() }}{{ $entry->party ? ' · ' : '' }}</span>{{ $entry->party?->name }}
                                @if ($struck)
                                    <span class="whitespace-nowrap">· İptal edildi</span>
                                @endif
                            </div>
                        </td>
                        <td @class(['hidden sm:table-cell px-5 py-3 text-right whitespace-nowrap tabular-nums', 'text-finance-in' => $signed > 0, 'line-through' => $struck])>{{ $signed > 0 ? Money::format($signed) : '' }}</td>
                        <td @class(['hidden sm:table-cell px-5 py-3 text-right whitespace-nowrap tabular-nums', 'text-finance-out' => $signed < 0, 'line-through' => $struck])>{{ $signed < 0 ? Money::format(-$signed) : '' }}</td>
                        <td @class(['sm:hidden px-3 py-3 text-right whitespace-nowrap tabular-nums', 'text-finance-in' => $signed > 0, 'text-finance-out' => $signed < 0, 'line-through' => $struck])>{{ $signed > 0 ? '+' : '' }}{{ Money::format($signed) }}</td>
                        <td @class(['px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium', 'text-finance-out' => $row['balance'] < 0])>{{ Money::format($row['balance']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-ink-muted text-[13px]">Bu dönemde hareket yok.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t border-line text-[13px]">
                    <td></td>
                    <td class="hidden sm:table-cell"></td>
                    <td class="px-3 sm:px-5 py-3 font-medium">Dönem toplamı</td>
                    <td class="hidden sm:table-cell px-5 py-3 text-right whitespace-nowrap tabular-nums text-finance-in">{{ Money::format($statement['in']) }}</td>
                    <td class="hidden sm:table-cell px-5 py-3 text-right whitespace-nowrap tabular-nums text-finance-out">{{ Money::format($statement['out']) }}</td>
                    <td class="sm:hidden px-3 py-3 text-right whitespace-nowrap tabular-nums">
                        <div class="text-finance-in">+{{ Money::format($statement['in']) }}</div>
                        <div class="text-finance-out">−{{ Money::format($statement['out']) }}</div>
                    </td>
                    <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium">
                        <span class="block text-[12px] font-normal text-ink-muted">Dönem sonu</span>
                        {{ Money::format($statement['closing']) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </section>

    @can('finance.create')
        <h2 class="mb-2 text-[15px] font-medium text-ink">Mutabakat</h2>
        <section class="mb-8 border border-line rounded-lg bg-surface px-4 sm:px-5 py-4">
            <p class="text-[13px] text-ink-muted mb-4">Banka veya kasa ekstresindeki bakiyeyi gir; ekstre tarihindeki defter bakiyesiyle karşılaştırılır.</p>
            <form wire:submit="compare" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Ekstre tarihi</label>
                    <input type="date" wire:model.live="statementDate" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('statementDate') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Banka ekstresindeki bakiye (₺)</label>
                    <input type="text" inputmode="decimal" wire:model.blur="statementBalance" placeholder="12.500,00" class="w-full border border-line rounded-md px-3 py-2 text-[14px] tabular-nums">
                    @error('statementBalance') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-[13px] text-ink-muted mb-1.5">Not (isteğe bağlı)</label>
                    <input type="text" wire:model="note" maxlength="500" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                    @error('note') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
                </div>
                <div class="sm:col-span-3">
                    <button wire:loading.attr="disabled" type="submit" class="border border-line rounded-md px-4 py-2 text-[14px] font-medium text-ink hover:bg-canvas transition-colors">Karşılaştır</button>
                </div>
            </form>

            @if ($previewDifference !== null)
                <div class="mt-5 pt-4 border-t border-line">
                    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-[14px]">
                        <div>
                            <dt class="text-[12px] text-ink-muted">Defter bakiyesi</dt>
                            <dd class="tabular-nums font-medium">{{ Money::format($previewSystem) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[12px] text-ink-muted">Ekstre bakiyesi</dt>
                            <dd class="tabular-nums font-medium">{{ Money::format($previewStatement) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[12px] text-ink-muted">Fark</dt>
                            <dd @class(['tabular-nums font-medium', 'text-finance-in' => $previewDifference > 0, 'text-finance-out' => $previewDifference < 0])>{{ $previewDifference > 0 ? '+' : '' }}{{ Money::format($previewDifference) }}</dd>
                        </div>
                    </dl>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        @if ($previewDifference === 0)
                            <p class="text-[13px] text-ink-muted w-full">Ekstre ve defter bakiyesi eşit.</p>
                            <button wire:loading.attr="disabled" wire:click="reconcile(false)" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Kayıt et</button>
                        @else
                            <button wire:loading.attr="disabled" wire:click="reconcile(true)" wire:confirm="Fark, ekstre tarihli bir düzeltme hareketiyle kapatılsın mı?" class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">Farkı düzeltme hareketiyle kapat</button>
                            <button wire:loading.attr="disabled" wire:click="reconcile(false)" class="border border-line rounded-md px-4 py-2.5 text-[14px] font-medium text-ink hover:bg-canvas transition-colors">Yalnızca kaydet</button>
                        @endif
                    </div>
                </div>
            @endif
        </section>
    @endcan

    <h2 class="mb-2 text-[15px] font-medium text-ink">Mutabakat geçmişi</h2>
    <section class="border border-line rounded-lg bg-surface overflow-x-auto">
        <table class="w-full text-[14px]">
            <thead>
                <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                    <th class="px-3 sm:px-5 py-3 font-medium">Ekstre tarihi</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Ekstre</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium text-right">Sistem</th>
                    <th class="px-3 sm:px-5 py-3 font-medium text-right">Fark</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Düzeltme</th>
                    <th class="hidden sm:table-cell px-5 py-3 font-medium">Kim</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($reconciliations as $record)
                    <tr wire:key="rec-{{ $record->id }}">
                        <td class="px-3 sm:px-5 py-3 whitespace-nowrap">
                            {{ $record->statement_date->format('d.m.Y') }}
                            <div class="sm:hidden text-[12px] text-ink-muted">{{ $record->actor?->name }}</div>
                        </td>
                        <td class="px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums">{{ Money::format($record->statement_balance) }}</td>
                        <td class="hidden sm:table-cell px-5 py-3 text-right whitespace-nowrap tabular-nums">{{ Money::format($record->system_balance) }}</td>
                        <td @class(['px-3 sm:px-5 py-3 text-right whitespace-nowrap tabular-nums font-medium', 'text-finance-in' => $record->difference > 0, 'text-finance-out' => $record->difference < 0])>{{ $record->difference > 0 ? '+' : '' }}{{ Money::format($record->difference) }}</td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap text-ink-muted">
                            @if ($record->adjustment === null)
                                {{ $record->difference === 0 ? '—' : 'Düzeltme yapılmadı' }}
                            @elseif ($record->adjustment->reversal !== null)
                                Düzeltme iptal edildi
                            @else
                                Düzeltme yapıldı
                            @endif
                        </td>
                        <td class="hidden sm:table-cell px-5 py-3 whitespace-nowrap text-ink-muted">{{ $record->actor?->name }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-ink-muted text-[13px]">Henüz mutabakat kaydı yok.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
