<?php

use App\Domain\Catalog\Exceptions\ProductRuleException;
use App\Domain\Import\Contracts\Importer;
use App\Domain\Import\Exceptions\ImportFileException;
use App\Domain\Import\Importers\OpeningStockImporter;
use App\Domain\Import\Importers\ProductImporter;
use App\Domain\Import\Support\ImportResult;
use App\Domain\Import\Support\SpreadsheetReader;
use App\Domain\Stock\Exceptions\ColdChainException;
use App\Domain\Stock\Exceptions\InactiveLocationException;
use App\Domain\Stock\Exceptions\SerialException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Toplu veri aktarımı (Aşama 38): şablonu indir → doldur → yükle → önizle →
 * içe aktar. Tek hatalı satır varsa hiçbir satır yazılmaz. Satırlar bileşen
 * durumunda taşınmaz; onayda dosya yeniden okunup yeniden doğrulanır.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    use WithFileUploads;

    /** Sekme => [importer, gerekli yetki, başarı mesajı kalıbı]. */
    private const TABS = [
        'products' => [ProductImporter::class, 'product_management.create', ':count ürün içe aktarıldı.'],
        'opening-stock' => [OpeningStockImporter::class, 'stock_movement.create', ':count satır açılış stoğu içe aktarıldı.'],
    ];

    private const PREVIEW_ERRORS = 100;

    private const PREVIEW_ROWS = 20;

    public string $tab = 'products';

    public $file = null;

    /** @var array{rowCount: int, errorCount: int, errors: array<int, array{line: int, column: string, message: string}>, moreErrors: int, creates: array<string, array<int, string>>, headings: array<int, string>, sample: array<int, array<string, string>>}|null */
    public ?array $preview = null;

    public function mount(): void
    {
        $this->tab = collect(self::TABS)->keys()->first(fn (string $tab) => Gate::allows(self::TABS[$tab][1])) ?? 'products';
    }

    public function selectTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);
        Gate::authorize(self::TABS[$tab][1]);

        $this->resetUpload();
        $this->tab = $tab;
    }

    public function downloadTemplate()
    {
        Gate::authorize(self::TABS[$this->tab][1]);
        $importer = $this->importer();

        return Excel::download($importer->template(auth()->user()), $importer->templateFileName());
    }

    public function updatedFile(): void
    {
        Gate::authorize(self::TABS[$this->tab][1]);
        $this->preview = null;

        $result = $this->validateFile();

        if ($result === null) {
            return;
        }

        $errors = $result->errors();

        $this->preview = [
            'rowCount' => $result->rowCount(),
            'errorCount' => $result->errorCount(),
            'errors' => array_slice($errors, 0, self::PREVIEW_ERRORS),
            'moreErrors' => max(0, count($errors) - self::PREVIEW_ERRORS),
            'creates' => $result->creates,
            'headings' => $this->importer()->headings(),
            'sample' => array_values(array_map(fn ($row) => $row->raw, array_slice($result->rows, 0, self::PREVIEW_ROWS, true))),
        ];
    }

    public function import(): void
    {
        Gate::authorize(self::TABS[$this->tab][1]);

        // Önizleme ile onay arasında veri değişmiş olabilir: yeniden okunur ve doğrulanır.
        $result = $this->validateFile();

        if ($result === null) {
            return;
        }

        if (! $result->isValid()) {
            $this->updatedFile();
            $this->addError('file', 'Dosyada hatalı satırlar var. Dosyayı düzeltip yeniden yükleyin.');

            return;
        }

        try {
            $count = $this->importer()->import($result, auth()->user());
        } catch (ColdChainException|SerialException|InactiveLocationException|ProductRuleException|\InvalidArgumentException $e) {
            $this->addError('file', 'İçe aktarma geri alındı, hiçbir kayıt yazılmadı: '.$e->getMessage());

            return;
        }

        $this->resetUpload();
        session()->flash('status', str_replace(':count', (string) $count, self::TABS[$this->tab][2]));
    }

    public function resetUpload(): void
    {
        $this->file?->delete();
        $this->reset(['file', 'preview']);
        $this->resetValidation();
    }

    private function validateFile(): ?ImportResult
    {
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120']], [
            'file.required' => 'Bir dosya seçin.',
            'file.mimes' => 'Yalnızca .xlsx veya .csv dosyası yükleyin.',
            'file.max' => 'Dosya en fazla 5 MB olabilir.',
        ]);

        try {
            $rows = app(SpreadsheetReader::class)->read(
                $this->file->getRealPath(),
                $this->file->getClientOriginalExtension(),
                $this->importer()->headings(),
            );
        } catch (ImportFileException $e) {
            $this->addError('file', $e->getMessage());

            return null;
        }

        return $this->importer()->validate($rows, auth()->user());
    }

    private function importer(): Importer
    {
        return app(self::TABS[$this->tab][0]);
    }

    public function with(): array
    {
        return [
            'tabs' => collect(['products' => 'Ürünler', 'opening-stock' => 'Açılış Stoğu'])
                ->filter(fn ($label, $tab) => Gate::allows(self::TABS[$tab][1])),
        ];
    }
};
?>

<div class="max-w-3xl">
    <div class="mb-8">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Veri Aktarımı</h1>
        <p class="mt-1 text-[14px] text-ink-muted">Ürün kartlarınızı ve elinizdeki stoğu Excel şablonuyla toplu olarak içeri alın. Önce ürünleri, sonra açılış stoğunu yükleyin.</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">
            {{ session('status') }}
        </div>
    @endif

    @if ($tabs->count() > 1)
        <nav class="mb-6 flex flex-wrap gap-1 border-b border-line">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="selectTab('{{ $key }}')"
                    class="px-3 py-2 text-[14px] -mb-px border-b-2 {{ $tab === $key ? 'border-brand-500 text-ink font-medium' : 'border-transparent text-ink-muted hover:text-ink' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    @endif

    <ol class="space-y-4">
        <li class="border border-line rounded-lg bg-surface p-5">
            <div class="flex gap-4">
                <span class="flex-none w-7 h-7 rounded-full border border-line text-[13px] text-ink-muted flex items-center justify-center tabular-nums">1</span>
                <div class="min-w-0">
                    <h2 class="text-[15px] font-medium text-ink">Şablonu indirip doldurun</h2>
                    <p class="mt-1 text-[13px] text-ink-muted leading-relaxed">
                        @if ($tab === 'products')
                            Her satır bir ürün kartıdır. Ürün kodu zorunludur ve her üründe farklı olmalıdır; sistemde zaten olan kodlar içe aktarılmaz. Olmayan kategori ve tedarikçiler adıyla açılır.
                        @else
                            Her satır bir stok girişidir ve "Açılış stoğu" gerekçesiyle kaydedilir. Ürün, kartındaki koduyla bulunur; önce ürünleri içe aktarın.
                        @endif
                        Şablonun "Açıklama" sayfasında her sütunun kuralı yazar.
                    </p>
                    <button type="button" wire:click="downloadTemplate" wire:loading.attr="disabled" wire:target="downloadTemplate"
                        class="mt-4 text-[13px] border border-line rounded-md px-3 py-2 hover:bg-canvas transition-colors">
                        Şablonu indir
                    </button>
                </div>
            </div>
        </li>

        <li class="border border-line rounded-lg bg-surface p-5">
            <div class="flex gap-4">
                <span class="flex-none w-7 h-7 rounded-full border border-line text-[13px] text-ink-muted flex items-center justify-center tabular-nums">2</span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-[15px] font-medium text-ink">Dosyayı yükleyin</h2>
                    <p class="mt-1 text-[13px] text-ink-muted">.xlsx veya .csv, en fazla 2.000 satır. Yüklenen dosya önce kontrol edilir; bu adımda hiçbir şey kaydedilmez.</p>
                    <input type="file" wire:model="file" accept=".xlsx,.csv" wire:key="file-{{ $tab }}"
                        class="mt-4 block w-full text-[13px] text-ink-muted file:mr-3 file:rounded-md file:border file:border-line file:bg-surface file:px-3 file:py-2 file:text-[13px] file:text-ink hover:file:bg-canvas">
                    <p wire:loading wire:target="file" class="mt-2 text-[13px] text-ink-muted">Dosya kontrol ediliyor…</p>
                    @error('file')
                        <div class="mt-3 rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </li>

        @if ($preview)
            <li class="border border-line rounded-lg bg-surface overflow-hidden">
                @if ($preview['errorCount'] === 0)
                    <div class="px-5 py-4 bg-brand-100 border-b border-brand-500/20">
                        <p class="text-[15px] font-medium text-brand-600">{{ $preview['rowCount'] }} satır içe aktarılmaya hazır</p>
                        <p class="mt-0.5 text-[13px] text-brand-600/80">Dosyada hata bulunmadı.</p>
                    </div>
                @else
                    <div class="px-5 py-4 bg-red-50 border-b border-status-critical/20">
                        <p class="text-[15px] font-medium text-status-critical">{{ $preview['rowCount'] }} satırın {{ $preview['errorCount'] }} tanesinde hata var</p>
                        <p class="mt-0.5 text-[13px] text-status-critical/80">Hata varken hiçbir satır kaydedilmez. Dosyayı düzeltip yeniden yükleyin.</p>
                    </div>
                @endif

                <div class="p-5 space-y-5">
                    @foreach ($preview['creates'] as $label => $names)
                        @if ($names)
                            <p class="text-[13px] text-ink">
                                <span class="text-ink-muted">Yeni açılacak {{ $label === 'Kategori' ? 'kategoriler' : 'tedarikçiler' }}:</span>
                                {{ implode(', ', $names) }}
                            </p>
                        @endif
                    @endforeach

                    @if ($preview['errors'])
                        <div class="max-h-80 overflow-y-auto rounded-md border border-line">
                            <table class="min-w-full text-[13px]">
                                <thead class="sticky top-0 bg-canvas">
                                    <tr class="text-left text-ink-muted">
                                        <th class="px-3 py-2 font-medium w-16">Satır</th>
                                        <th class="px-3 py-2 font-medium w-36">Sütun</th>
                                        <th class="px-3 py-2 font-medium">Sorun</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($preview['errors'] as $error)
                                        <tr class="border-t border-line align-top">
                                            <td class="px-3 py-2 tabular-nums text-ink-muted">{{ $error['line'] }}</td>
                                            <td class="px-3 py-2 text-ink">{{ $error['column'] }}</td>
                                            <td class="px-3 py-2 text-ink">{{ $error['message'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($preview['moreErrors'])
                            <p class="text-[13px] text-ink-muted">İlk {{ count($preview['errors']) }} hata gösteriliyor; {{ $preview['moreErrors'] }} hata daha var.</p>
                        @endif
                    @endif

                    <details class="group">
                        <summary class="cursor-pointer text-[13px] text-ink-muted hover:text-ink">Dosyadaki ilk {{ count($preview['sample']) }} satırı göster</summary>
                        <div class="mt-3 overflow-x-auto rounded-md border border-line">
                            <table class="min-w-full text-[12px]">
                                <thead class="bg-canvas">
                                    <tr class="text-left text-ink-muted">
                                        @foreach ($preview['headings'] as $heading)
                                            <th class="px-2 py-1.5 font-medium whitespace-nowrap">{{ $heading }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($preview['sample'] as $row)
                                        <tr class="border-t border-line">
                                            @foreach ($preview['headings'] as $heading)
                                                <td class="px-2 py-1.5 whitespace-nowrap text-ink">{{ $row[$heading] ?? '' }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>

                    <div class="flex items-center gap-3 pt-1">
                        @if ($preview['errorCount'] === 0)
                            <button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import"
                                class="bg-panel-900 text-white rounded-md px-4 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors disabled:opacity-60">
                                {{ $preview['rowCount'] }} satırı içe aktar
                            </button>
                        @endif
                        <button type="button" wire:click="resetUpload" class="text-[14px] text-ink-muted hover:text-ink">{{ $preview['errorCount'] === 0 ? 'Vazgeç' : 'Dosyayı kaldır' }}</button>
                    </div>
                </div>
            </li>
        @endif
    </ol>
</div>
