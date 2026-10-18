<x-layouts::marketing title="ÜTS ve e-fatura rehberleri | Dental ERP" description="Diş klinikleri için ÜTS bildirimleri ve ilaç-tıbbi cihaz e-faturası: alma, kullanım, iade, imha ve token. Resmî kaynaklara dayanan kısa rehberler.">
    <section class="bg-panel-900 text-white">
        <div class="mx-auto max-w-[1120px] px-5 sm:px-6 pt-14 pb-14 sm:pt-20 sm:pb-16">
            <h1 class="text-[34px] sm:text-[46px] leading-[1.05] tracking-[-0.035em] font-semibold max-w-[20ch]">Rehberler</h1>
            <p class="mt-6 text-[17px] text-white/65 leading-relaxed max-w-[62ch]">
                Diş kliniğinin ÜTS ve ilaç-tıbbi cihaz e-faturası işleri, adım adım. Her rehber ÜTS Web Servis
                Tanımları ya da GİB kılavuzları gibi resmî bir kaynağa dayanır ve kaynağını sonunda gösterir.
                Yasal danışmanlık değildir; kurumunuzun yükümlülüğü için mevzuata bakın.
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-[1120px] px-5 sm:px-6 py-12 sm:py-16">
        <ul class="divide-y divide-line border-y border-line">
            @foreach ($guides as $guide)
                <li>
                    <a href="{{ route('guides.show', $guide['slug']) }}" class="group block py-6 sm:grid sm:grid-cols-12 sm:gap-8 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 rounded-sm">
                        <h2 class="sm:col-span-5 text-[19px] leading-snug font-medium tracking-[-0.01em] group-hover:text-brand-600 transition-colors">{{ $guide['title'] }}</h2>
                        <p class="mt-2 sm:mt-0.5 sm:col-span-7 text-[15px] text-ink-muted leading-relaxed max-w-[64ch]">{{ $guide['summary'] }}</p>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts::marketing>
