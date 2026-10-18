<x-layouts::marketing :title="$guide['title'].' | Dental ERP'" :description="$guide['description']">
    <x-slot:head>
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $guide['title'],
            'description' => $guide['description'],
            'dateModified' => $guide['updated'],
            'inLanguage' => 'tr',
            'mainEntityOfPage' => route('guides.show', $guide['slug']),
            'publisher' => ['@type' => 'Organization', 'name' => 'Dental ERP'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    </x-slot:head>

    <article>
        <header class="bg-panel-900 text-white">
            <div class="mx-auto max-w-[1120px] px-5 sm:px-6 pt-12 pb-12 sm:pt-16 sm:pb-14">
                <nav aria-label="Konum" class="text-[13px] text-white/55">
                    <a href="{{ route('guides.index') }}" class="hover:text-white transition-colors">Rehberler</a>
                </nav>
                <h1 class="mt-3 text-[30px] sm:text-[42px] leading-[1.08] tracking-[-0.03em] font-semibold max-w-[24ch]">{{ $guide['title'] }}</h1>
                <p class="mt-5 text-[17px] text-white/65 leading-relaxed max-w-[62ch]">{{ $guide['summary'] }}</p>
                <p class="mt-5 text-[13px] text-white/45">Güncelleme {{ \Illuminate\Support\Carbon::parse($guide['updated'])->format('d.m.Y') }}</p>
            </div>
        </header>

        <div class="mx-auto max-w-[1120px] px-5 sm:px-6 py-12 sm:py-16 lg:grid lg:grid-cols-12 lg:gap-12">
            <div class="lg:col-span-8 max-w-[68ch]">
                @foreach ($guide['sections'] as $section)
                    <section class="{{ $loop->first ? '' : 'mt-11' }}">
                        <h2 class="text-[22px] sm:text-[24px] leading-tight tracking-[-0.02em] font-semibold">{{ $section['heading'] }}</h2>
                        @foreach ($section['paragraphs'] ?? [] as $paragraph)
                            <p class="mt-4 text-[16px] leading-[1.7] text-ink/85">{{ $paragraph }}</p>
                        @endforeach
                        @if (! empty($section['items']))
                            {{-- Numara yalnızca gerçekten sıralı adımlarda ('steps' => true); diğerleri madde imli. --}}
                            <{{ ! empty($section['steps']) ? 'ol' : 'ul' }} @class(['mt-4 space-y-2.5 text-[16px] leading-[1.65] text-ink/85 pl-5 marker:text-ink-muted', 'list-decimal' => ! empty($section['steps']), 'list-disc' => empty($section['steps'])])>
                                @foreach ($section['items'] as $item)
                                    <li class="pl-1">{{ $item }}</li>
                                @endforeach
                            </{{ ! empty($section['steps']) ? 'ol' : 'ul' }}>
                        @endif
                    </section>
                @endforeach

                <aside class="mt-12 rounded-lg border border-brand-500/30 bg-brand-100/40 px-5 py-5">
                    <h2 class="text-[16px] font-semibold">Dental ERP'de</h2>
                    <p class="mt-2 text-[15px] leading-relaxed text-ink/85">{{ $guide['program'] }}</p>
                </aside>

                <section class="mt-12 border-t border-line pt-8">
                    <h2 class="text-[16px] font-semibold">Kaynaklar</h2>
                    <ul class="mt-3 space-y-2 text-[14px] text-ink-muted">
                        @foreach ($guide['sources'] as [$label, $url])
                            <li><a href="{{ $url }}" rel="noopener" target="_blank" class="underline underline-offset-4 decoration-line hover:text-ink">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                    <p class="mt-4 text-[13px] text-ink-muted">Bu rehber bilgilendirme amaçlıdır, yasal danışmanlık değildir.</p>
                </section>
            </div>

            @if ($others)
                <aside class="mt-14 lg:mt-0 lg:col-span-4">
                    <div class="lg:sticky lg:top-24">
                        <h2 class="text-[13px] text-ink-muted">Diğer rehberler</h2>
                        <ul class="mt-3 divide-y divide-line border-y border-line">
                            @foreach ($others as $other)
                                <li><a href="{{ route('guides.show', $other['slug']) }}" class="block py-3.5 text-[15px] leading-snug hover:text-brand-600 transition-colors">{{ $other['title'] }}</a></li>
                            @endforeach
                        </ul>
                        <a href="{{ route('guides.index') }}" class="mt-4 inline-block text-[14px] text-brand-600 hover:text-brand-500 underline underline-offset-4 decoration-brand-500/30">Bütün rehberler</a>
                    </div>
                </aside>
            @endif
        </div>
    </article>

    <section class="bg-panel-900 text-white">
        <div class="mx-auto max-w-[1120px] px-5 sm:px-6 py-14 lg:flex lg:items-center lg:gap-12">
            <p class="lg:flex-1 text-[22px] sm:text-[26px] leading-tight tracking-[-0.02em] font-semibold max-w-[30ch]">
                ÜTS bildirimlerini stok kaydının yanında, ikinci kez yazmadan yapın
            </p>
            <a href="{{ route('marketing.contact') }}" class="mt-7 lg:mt-0 inline-block shrink-0 bg-brand-500 text-white rounded-md px-6 py-3 text-[15px] font-medium hover:bg-brand-400 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">Talep gönder</a>
        </div>
    </section>
</x-layouts::marketing>
