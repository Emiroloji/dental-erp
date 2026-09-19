<?php

use App\Domain\Assistant\Contracts\QueryInterpreter;
use App\Domain\Assistant\Exceptions\InterpreterException;
use App\Domain\Assistant\Exceptions\QueryLimitException;
use App\Domain\Assistant\Interpreters\UnconfiguredQueryInterpreter;
use App\Domain\Assistant\Models\AssistantQuery;
use App\Domain\Assistant\Services\NaturalQueryService;
use App\Domain\Assistant\Support\QueryIntent;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Rapor Asistanı (Faz 4 — Aşama 25): doğal dille rapor sorgulama.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    public string $question = '';

    /** Son cevap: soru, anlaşılan filtreler, uyarılar ve rapor sonucu. */
    public ?array $result = null;

    public function ask(NaturalQueryService $assistant): void
    {
        Gate::authorize('reports.viewAny');

        $this->validate([
            'question' => ['required', 'string', 'min:3', 'max:'.config('assistant.max_question_length')],
        ], [
            'question.required' => 'Bir soru yazın.',
            'question.min' => 'Soru çok kısa.',
            'question.max' => 'Soru en fazla :max karakter olabilir.',
        ]);

        // Kullanıcı başına dakikada en fazla 10 soru (kötüye kullanım koruması).
        if (! RateLimiter::attempt('assistant:'.auth()->id(), 10, fn () => true, 60)) {
            $this->addError('question', 'Çok hızlı soru gönderiyorsunuz. Bir dakika sonra tekrar deneyin.');

            return;
        }

        try {
            $response = $assistant->ask(auth()->user(), $this->question);
        } catch (QueryLimitException|InterpreterException $e) {
            $this->result = null;
            $this->addError('question', $e->getMessage());

            return;
        }

        $intent = $response['intent'];

        $this->result = [
            'question' => $this->question,
            'understood' => $intent->understood,
            'clarification' => $intent->clarification,
            'warnings' => $intent->warnings,
            'chips' => $intent->understood ? $this->chips($intent) : [],
            'answer' => $response['answer'],
        ];
    }

    public function useQuestion(string $question): void
    {
        $this->question = mb_substr($question, 0, (int) config('assistant.max_question_length'));
        $this->resetValidation();
    }

    /**
     * Kullanıcının, yapay zekânın soruyu nasıl anladığını görüp doğrulayabilmesi için.
     *
     * @return array<int, string>
     */
    private function chips(QueryIntent $intent): array
    {
        return array_values(array_filter([
            $intent->report->label(),
            $intent->from ? $intent->from->format('d.m.Y').' – '.$intent->to->format('d.m.Y') : null,
            $intent->branchId ? 'Şube: '.Branch::find($intent->branchId)?->name : null,
            $intent->warehouseId ? 'Depo: '.Warehouse::find($intent->warehouseId)?->name : null,
            $intent->categoryId ? 'Kategori: '.Category::find($intent->categoryId)?->name : null,
            $intent->supplierId ? 'Tedarikçi: '.Supplier::find($intent->supplierId)?->name : null,
            $intent->movementType ? 'Hareket: '.$intent->movementType->label() : null,
            $intent->risk ? 'Durum: '.$intent->risk->label() : null,
            $intent->search ? "Ürün: \"{$intent->search}\"" : null,
            "İlk {$intent->limit}",
        ]));
    }

    public function with(NaturalQueryService $assistant, QueryInterpreter $interpreter): array
    {
        return [
            'usage' => $assistant->usage(auth()->user()->organization_id),
            'configured' => ! $interpreter instanceof UnconfiguredQueryInterpreter,
            'provider' => $interpreter->name(),
            'history' => AssistantQuery::where('user_id', auth()->id())->latest('id')->limit(8)->get(),
            'examples' => [
                'Bu ay en çok kullanılan 5 ürün hangisi?',
                'Geçen ay eldiven kullanımı ne kadardı?',
                'Yakında tükenecek ürünler neler?',
                'Kritik seviyedeki ürünleri göster',
                'Dün yapılan stok çıkışları',
                'Bu ay hangi tedarikçiden ne kadar sipariş verdik?',
            ],
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">Raporlar</h1>
        <p class="text-[14px] text-ink-muted mt-1">
            Sorunuzu yazın; asistan hangi raporun hangi filtrelerle çalışacağını belirler, rakamları sistem kendi raporlarından hesaplar.
            Yapay zekâ servisine yalnızca soru metni ve filtre seçenekleri (şube, depo, kategori, tedarikçi adları) gönderilir; stok rakamları gönderilmez.
        </p>
    </div>

    <x-report-tabs />

    @unless ($configured)
        <div class="mb-4 rounded-md bg-status-warn-bg border border-status-warn/20 text-status-warn text-[13px] px-4 py-3">
            Rapor asistanı henüz yapılandırılmadı: yapay zekâ API anahtarı (GEMINI_API_KEY) tanımlı değil.
        </div>
    @endunless

    <form wire:submit="ask" class="mb-3">
        <div class="flex flex-col sm:flex-row gap-2">
            <input type="text" wire:model="question" maxlength="{{ config('assistant.max_question_length') }}" placeholder="Ör. Geçen ay Kadıköy şubesinde en çok kullanılan 5 ürün" autofocus
                class="flex-1 border border-line rounded-md px-3 py-2.5 text-[15px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500">
            <button type="submit" wire:loading.attr="disabled" class="bg-panel-900 text-white rounded-md px-5 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors disabled:opacity-60">
                <span wire:loading.remove wire:target="ask">Sor</span>
                <span wire:loading wire:target="ask">Yorumlanıyor…</span>
            </button>
        </div>
        @error('question') <span class="text-status-critical text-[13px] block mt-1.5">{{ $message }}</span> @enderror
    </form>

    <div class="mb-6 flex flex-wrap items-center gap-2 text-[12px]">
        @foreach ($examples as $example)
            <button type="button" wire:click="useQuestion(@js($example))" class="border border-line rounded-full px-3 py-1 text-ink-muted hover:text-ink hover:bg-canvas">{{ $example }}</button>
        @endforeach
        <span class="ml-auto text-ink-muted">Organizasyon kotası — bugün {{ $usage['daily'] }}/{{ $usage['daily_limit'] }} · bu ay {{ $usage['monthly'] }}/{{ $usage['monthly_limit'] }}</span>
    </div>

    @if ($result)
        <section class="mb-8 border border-line rounded-lg bg-surface">
            <div class="px-5 py-4 border-b border-line">
                <p class="text-[13px] text-ink-muted">“{{ $result['question'] }}”</p>

                @if (! $result['understood'])
                    <p class="mt-2 text-[14px] text-ink">{{ $result['clarification'] }}</p>
                @else
                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                        <span class="text-[12px] text-ink-muted mr-1">Anlaşılan:</span>
                        @foreach ($result['chips'] as $chip)
                            <span class="inline-flex items-center px-2 py-0.5 rounded bg-brand-100 text-brand-600 text-[12px]">{{ $chip }}</span>
                        @endforeach
                    </div>
                    @foreach ($result['warnings'] as $warning)
                        <p class="mt-2 text-[12px] text-status-warn">{{ $warning }}</p>
                    @endforeach
                @endif
            </div>

            @if ($result['answer'])
                @php $answer = $result['answer']; @endphp
                <div class="px-5 py-3 flex flex-wrap items-center justify-between gap-2 text-[13px]">
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-ink">
                        @foreach ($answer['summary'] as $line)
                            <span>{{ $line }}</span>
                        @endforeach
                    </div>
                    <a href="{{ $answer['url'] }}" wire:navigate class="text-brand-600 hover:underline shrink-0">{{ $answer['title'] }} raporunda aç →</a>
                </div>
                <div class="overflow-x-auto border-t border-line">
                    <table class="w-full text-[14px]">
                        <thead>
                            <tr class="text-left text-ink-muted text-[12px] border-b border-line">
                                @foreach ($answer['headings'] as $heading)
                                    <th class="px-5 py-2.5 font-medium">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($answer['rows'] as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        <td class="px-5 py-2.5">{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($answer['headings']) }}" class="px-5 py-6 text-center text-ink-muted text-[13px]">Bu filtrelerle kayıt bulunamadı.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="px-5 py-2.5 border-t border-line text-[12px] text-ink-muted">
                    {{ count($answer['rows']) }} / {{ $answer['total'] }} satır gösteriliyor. Rakamlar sistemin kendi raporlarından hesaplandı; yapay zekâ ({{ $provider }}) yalnızca soruyu yorumladı.
                </p>
            @endif
        </section>
    @endif

    @if ($history->isNotEmpty())
        <section>
            <h2 class="text-[13px] font-medium text-ink mb-2">Son sorularınız</h2>
            <ul class="border border-line rounded-lg bg-surface divide-y divide-line text-[13px]">
                @foreach ($history as $item)
                    <li class="px-4 py-2 flex items-center justify-between gap-3">
                        <button type="button" wire:click="useQuestion(@js($item->question))" class="text-left text-ink hover:text-brand-600 truncate">{{ $item->question }}</button>
                        <span class="text-ink-muted shrink-0">{{ $item->status->label() }} · {{ $item->created_at->format('d.m H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
