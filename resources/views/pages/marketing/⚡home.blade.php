<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::marketing')] class extends Component
{
    /**
     * Tanıtım sayfası ziyaretçiler içindir; oturumu açık kullanıcı kendi
     * paneline gider (Aşama 29.2 — giriş akışı değişmedi).
     */
    public function mount(): void
    {
        if (auth()->check()) {
            $this->redirect('/dashboard');
        }
    }

    public function with(): array
    {
        // Örnek ekranlar (components/marketing/screens) ekran görüntüsü değil,
        // sitenin kendi token'larıyla çizilmiş HTML kopyalarıdır; veriler örnektir.
        return [
            'scenes' => [
                ['Stok', 'Stok Durumu', 'lots'],
                ['Uyarı', 'Bildirimler', 'alert'],
                ['Finans', 'Finans', 'finance'],
            ],
            'journey' => [
                ['Talep açılır', 'Ortodonti deposunda eldiven azalınca depo sorumlusu satın alma talebi açar.', 'Stok değişmez'],
                ['Onaylanır', 'Klinik sahibi talebi onaylar, tedarikçiye sipariş verilir.', 'Stok değişmez'],
                ['Teslim alınır', 'Gelen 8 kutu lot numarası ve son kullanma tarihiyle girilir; 2 kutu açık siparişte kalır. ÜTS takipli ürün ÜTS\'de de tek tıkla kabul edilir.', 'Stok artar'],
                ['Kullanılır', 'Çıkışta parti seçilmezse tarihi önce dolan düşer. İmplant hastaya takılınca hasta kartına yazılır, ÜTS\'ye kullanım bildirilir.', 'Stok azalır'],
                ['Fatura ödenir', 'Tedarikçinin e-faturası kendiliğinden gelir, teslimle eşleşir ve tek tıkla Finans\'a borç olarak geçer; ödediğinizde bankadan düşer.', 'Para çıkar'],
            ],
            'modules' => [
                ['stok', 'Stok', 'Lot, son kullanma tarihi ve seri numarasıyla, depo depo.', 'lots', [
                    'Stok yalnızca kayıtlı bir hareketle değişir; eksiye düşmez.',
                    'Yanlış kayıt silinmez, gerekçesiyle iptal edilir.',
                    '"1 kutu = 50 adet" tanımlanır, kutu girip adet olarak tutulur.',
                ]],
                ['satinalma', 'Satın alma', 'Talepten teslime, kısmi teslimatla birlikte.', 'purchase', [
                    'Talep, onay, sipariş ve teslim adımları ayrı ayrı izlenir.',
                    'Teslim alınan miktar stoğa girer, kalanı açık siparişte bekler.',
                    'Her tedarikçinin sipariş ve harcama geçmişi kendi kartında.',
                ]],
                ['efatura', 'e-Fatura', 'Tedarikçi faturası kendiliğinden gelir.', 'einvoice', [
                    'Entegratörden yarım saatte bir çekilir, kalemleriyle okunur.',
                    'Tedarikçi, teslim kaydı ve ürün kartıyla eşleşir.',
                    'Tek tıkla Finans\'a ödenecek borç olarak geçer.',
                ]],
                ['transfer', 'Transfer', 'Şubeler ve depolar arası, adım adım.', 'transfer', [
                    'Talep, onay, hazırlık, gönderim ve teslim.',
                    'Stok gönderimde kaynaktan düşer, teslimde hedefe eklenir.',
                    'Gönderilmeden iptal edilen transfer stoğa dokunmaz.',
                ]],
                ['sayim', 'Sayım ve iade', 'Farklar iz bırakır, iadeler takip edilir.', 'count', [
                    'Sayan kişi gerçek miktarı girer, sistem farkı hesaplar.',
                    'Onaylanan fark düzeltme hareketine dönüşür.',
                    'Tedarikçiye iade; kargo, onay ve alacak dekontuyla izlenir.',
                ]],
                ['ilac', 'ÜTS ve hasta', 'Kabulden imhaya ÜTS bildirimleri, implant kartı.', 'medical', [
                    'Kabul bekleyen ürünler saatte bir taranır, tek tıkla kabul edilir.',
                    'Hastaya kullanım, iade, imha ve zayiat programdan ÜTS\'ye bildirilir.',
                    'Hangi implantın hangi hastaya takıldığı hasta kartında görünür.',
                ]],
                ['finans', 'Finans', 'Kasa, banka, POS, cariler ve vadeler.', 'finance', [
                    'Gelir ve gider kategorileriyle, şube etiketiyle kaydedilir.',
                    'Ödenecek ve tahsil edilecekler vadesiyle takip edilir.',
                    'Banka ekstresiyle mutabakat yapılır, fark düzeltme kaydıyla kapanır.',
                ]],
                ['rapor', 'Raporlar ve asistan', 'Filtreli raporlar, tahmin, düz Türkçe sorular.', 'report', [
                    'Stok, hareket, kullanım, satın alma ve finans raporları.',
                    'Geçmiş tüketimden ürünün yaklaşık bitiş günü tahmin edilir.',
                    'Excel ve PDF olarak indirilir.',
                ]],
                ['yetki', 'Yetki ve denetim', 'Modül modül yetki, şube kapsamı, kayıt izi.', 'permissions', [
                    'Her personele okuma, yazma, silme ayrı ayrı verilir.',
                    'Kapsam: kendi şubesi, seçili şubeler ya da tüm şubeler.',
                    'Kim neyi ne zaman değiştirdi, öncesi ve sonrasıyla yazılır.',
                ]],
            ],
            'months' => collect(range(5, 0))->map(fn (int $ago) => now()->startOfMonth()->subMonths($ago)->translatedFormat('M'))->all(),
            'income' => [42, 48, 45, 51, 50, 56],
            'expense' => [38, 41, 44, 40, 61, 43],
            'faq' => [
                ['Hesabımı nasıl açarım?', 'Talep formunu doldurursunuz, sizi arayıp kurulumu konuşuruz. Kliniğinizi, şubelerinizi ve yönetici hesabınızı biz açar, giriş bilgilerinizi göndeririz. Form doldurmak hesap açmaz.'],
                ['Fiyatlandırma nasıl?', 'Paketler yalnızca şube, kullanıcı ve dosya alanı sınırıyla ayrılır; en küçük pakette de bütün modüller açıktır. Sistemde ödeme alınmaz, paket değişikliği talep ve onayla yürür. Fiyat için talep gönderin.'],
                ['Verilerimiz başka kliniklerle karışır mı?', 'Hayır. Her klinik kendi hesabında, diğerlerinden tamamen ayrı çalışır. Bu ayrım ekran ekran değil, verinin kendisine bağlı bir kural olarak uygulanır ve her sürümde test edilir.'],
                ['Telefonda çalışır mı?', 'Evet. Program tarayıcıda çalışır ve telefonun ana ekranına eklenip uygulama gibi açılır. Barkodu telefonun kamerasıyla ya da el okuyucusuyla okutabilirsiniz.'],
                ['Muhasebe programımın yerine geçer mi?', 'Hayır. Finans modülü ön muhasebedir: kliniğin parasını günlük olarak izlersiniz. KDV ve döviz tutulmaz; resmî defterler mali müşavirinizde kalır, ona Excel dökümü verirsiniz.'],
                ['Yanlış girilen kaydı silebilir miyim?', 'Stok ve para hareketleri silinmez, gerekçesiyle iptal edilir. İptal de bir kayıt olarak durur; böylece geçmiş ay raporları sonradan değişmez.'],
                ['ÜTS bildirimlerini program mı yapıyor?', 'Evet. Tedarikçinin gönderdiği ürünün kabulü, hastaya kullanımı, tedarikçiye iadesi, imhası ve zayiatı programdan ÜTS\'ye bildirilir. Bunun için kurumunuzun ÜTS sistem anahtarını bir kez girersiniz.'],
                ['Hasta kaydı tutuyor mu?', 'ÜTS\'nin istediği kadar: ad, soyad ve kimlik numarası şifreli tutulur, hastaya takılan ürünler hasta kartında görünür. Randevu, tedavi planı ve hasta dosyası yoktur.'],
                ['e-Fatura ile çalışıyor mu?', 'Tedarikçinin kliniğe kestiği e-fatura entegratörünüzden kendiliğinden gelir ve eşleştirilir. Kliniğin hastaya kestiği fatura (giden e-fatura, e-arşiv) programda yoktur.'],
            ],
        ];
    }
};
?>

<div>
    {{-- Açılış: programın kendisi, üç sahnede. Sahne 6 saniyede bir değişir; üstüne gelince, odaklanınca ve sekme arka plandayken durur. "Hareketi azalt" tercihinde kendiliğinden değişmez. --}}
    <section class="bg-panel-900 text-white overflow-hidden">
        <div class="mx-auto max-w-[1180px] px-5 sm:px-6 pt-14 pb-20 sm:pt-20 sm:pb-24 lg:grid lg:grid-cols-12 lg:gap-12 lg:items-center">
            <div class="lg:col-span-6">
                <h1 class="text-[36px] sm:text-[48px] lg:text-[56px] leading-[1.04] tracking-[-0.035em] font-semibold max-w-[15ch]">
                    Kliniğin stoğu da parası da tek doğru kayıtta.
                </h1>
                <p class="mt-6 text-[17px] leading-relaxed text-white/65 max-w-[52ch]">
                    Dental ERP, çok şubeli diş kliniklerinin malzemesini lot ve son kullanma tarihiyle,
                    parasını kasa, banka ve POS hesaplarıyla tutar. Hangi parti nerede, ne zaman bitecek,
                    bu ay ne kazandınız, gelecek hafta ne ödeyeceksiniz: hepsi kaydın kendisinde.
                </p>

                <div class="mt-9 flex flex-wrap items-center gap-3">
                    <a href="{{ route('marketing.contact') }}" class="bg-brand-500 text-white rounded-md px-5 py-3 text-[15px] font-medium hover:bg-brand-400 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">
                        Talep gönderin, size dönelim
                    </a>
                    <a href="{{ route('marketing.features') }}" class="px-5 py-3 text-[15px] text-white/75 hover:text-white rounded-md border border-panel-line hover:border-white/30 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">
                        Bütün özellikler
                    </a>
                </div>
            </div>

            <div class="mt-14 lg:mt-0 lg:col-span-6"
                x-data="{
                    scene: 0, elapsed: 0, duration: 6000, paused: false, still: false,
                    init() {
                        this.still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                        if (this.still) return;
                        setInterval(() => {
                            if (this.paused || document.hidden) return;
                            this.elapsed += 100;
                            if (this.elapsed >= this.duration) this.go((this.scene + 1) % {{ count($scenes) }});
                        }, 100);
                    },
                    go(i) { this.scene = i; this.elapsed = 0; },
                    progress(i) { return this.scene !== i ? 0 : (this.still ? 1 : this.elapsed / this.duration); },
                }"
                x-on:mouseenter="paused = true" x-on:mouseleave="paused = false"
                x-on:focusin="paused = true" x-on:focusout="paused = false">
                <div class="flex gap-2 mb-3" role="tablist" aria-label="Programdan örnek ekranlar">
                    @foreach ($scenes as $i => [$label])
                        <button type="button" role="tab" class="flex-1 text-left group focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-400 rounded-sm"
                            aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                            x-on:click="go({{ $i }})"
                            x-bind:aria-selected="scene === {{ $i }}"
                            aria-controls="hero-scene-{{ $i }}">
                            <span class="block h-0.5 rounded-full bg-white/15 overflow-hidden">
                                <span class="block h-full bg-brand-400 origin-left transition-transform duration-100 ease-linear" style="transform: scaleX(0)"
                                    x-bind:style="`transform: scaleX(${progress({{ $i }})})`"></span>
                            </span>
                            <span @class(['block mt-2 text-[13px] transition-colors', 'text-white' => $i === 0, 'text-white/45 group-hover:text-white/75' => $i > 0])
                                x-bind:class="{ 'text-white': scene === {{ $i }}, 'text-white/45 group-hover:text-white/75': scene !== {{ $i }} }">{{ $label }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="grid">
                    @foreach ($scenes as $i => [$label, $title, $screen])
                        <div id="hero-scene-{{ $i }}" role="tabpanel" class="col-start-1 row-start-1 mk-scene"
                            x-show="scene === {{ $i }}" @if ($i > 0) x-cloak @endif>
                            <x-marketing.window :title="$title" path="dental-erp">
                                <x-dynamic-component :component="'marketing.screens.'.$screen" :animate="true" />
                            </x-marketing.window>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Yolculuk: kaydırdıkça ulaşılan adım dolar. Gerçek bir sıra olduğu için numaralı. --}}
    <section class="bg-canvas border-b border-line">
        <div class="mx-auto max-w-[1180px] px-5 sm:px-6 py-20 sm:py-28 lg:grid lg:grid-cols-12 lg:gap-12">
            <div class="lg:col-span-5">
                <div class="lg:sticky lg:top-28">
                    <h2 class="text-[28px] sm:text-[34px] leading-[1.1] tracking-[-0.025em] font-semibold max-w-[16ch]">
                        Bir kutu eldivenin klinikteki yolu
                    </h2>
                    <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[46ch]">
                        Programdaki her adım klinikte gerçekten yapılan bir işe karşılık gelir. Stoğun ve paranın
                        hangi adımda değiştiği bellidir; arada hiçbir rakam kendiliğinden oynamaz.
                    </p>
                </div>
            </div>

            <ol class="mt-12 lg:mt-0 lg:col-span-7">
                @foreach ($journey as $i => [$title, $text, $effect])
                    <li class="mk-step flex gap-5" x-data="{ reached: false }" x-intersect.margin.-40%.0.-40%.0.once="reached = true" x-bind:class="reached && 'is-reached'">
                        <div class="flex flex-col items-center" aria-hidden="true">
                            <span class="mk-step-dot w-9 h-9 shrink-0 rounded-full border-2 border-line bg-surface flex items-center justify-center text-[14px] font-medium tabular-nums"
                                x-bind:class="reached ? 'text-white' : 'text-ink-muted'">{{ $i + 1 }}</span>
                            @unless ($loop->last)
                                <span class="relative w-0.5 flex-1 bg-line my-2">
                                    <span class="mk-step-line absolute inset-0 bg-brand-500"></span>
                                </span>
                            @endunless
                        </div>
                        <div @class(['pt-1.5', 'pb-12' => ! $loop->last])>
                            <h3 class="text-[19px] font-medium tracking-[-0.01em]">{{ $title }}</h3>
                            <p class="mt-2 text-[15px] text-ink-muted leading-relaxed max-w-[54ch]">{{ $text }}</p>
                            <p @class([
                                'mt-3 inline-block text-[13px] rounded px-2 py-0.5',
                                'bg-surface border border-line text-ink-muted' => $effect === 'Stok değişmez',
                                'bg-brand-100 text-brand-600' => in_array($effect, ['Stok artar', 'Stok azalır']),
                                'bg-[#f3ebe5] text-finance-out' => $effect === 'Para çıkar',
                            ])>{{ $effect }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Modül gezgini: ziyaretçi seçer, ilgili ekranın örneği açılır. --}}
    <section class="bg-surface" x-data="{ active: '{{ $modules[0][0] }}' }">
        <div class="mx-auto max-w-[1180px] px-5 sm:px-6 py-20 sm:py-28">
            <h2 class="text-[28px] sm:text-[34px] leading-[1.1] tracking-[-0.025em] font-semibold max-w-[20ch]">
                Programda neler var
            </h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[60ch]">
                Dokuz bölüm, tek kayıt. Bir bölüm seçin, o ekranın örneğini görün. Paketler modülleri kısıtlamaz;
                en küçük pakette de hepsi açıktır.
            </p>

            <div class="mt-12 lg:grid lg:grid-cols-12 lg:gap-10">
                <div class="lg:col-span-4 -mx-5 px-5 sm:mx-0 sm:px-0 overflow-x-auto lg:overflow-visible">
                    <div class="flex lg:flex-col gap-1.5 min-w-max lg:min-w-0" role="tablist" aria-label="Programın bölümleri" aria-orientation="vertical">
                        {{-- İlk durum sunucuda basılır; Alpine nesne sözdizimiyle yalnızca değişeni açıp kapar (yüklenirken titreme olmaz). --}}
                        @foreach ($modules as $i => [$key, $name, $summary])
                            <button type="button" role="tab" id="module-tab-{{ $key }}" aria-controls="module-panel-{{ $key }}"
                                @class(['text-left rounded-lg px-4 py-2.5 lg:py-3.5 border transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500', 'bg-panel-900 border-panel-900 text-white' => $i === 0, 'border-transparent hover:bg-canvas text-ink' => $i > 0])
                                aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                                x-on:click="active = '{{ $key }}'"
                                x-bind:aria-selected="active === '{{ $key }}'"
                                x-bind:class="{ 'bg-panel-900 border-panel-900 text-white': active === '{{ $key }}', 'border-transparent hover:bg-canvas text-ink': active !== '{{ $key }}' }">
                                <span class="block text-[15px] font-medium whitespace-nowrap">{{ $name }}</span>
                                <span @class(['hidden lg:block mt-0.5 text-[13px] leading-snug', 'text-white/60' => $i === 0, 'text-ink-muted' => $i > 0])
                                    x-bind:class="{ 'text-white/60': active === '{{ $key }}', 'text-ink-muted': active !== '{{ $key }}' }">{{ $summary }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="mt-8 lg:mt-0 lg:col-span-8 grid">
                    @foreach ($modules as $i => [$key, $name, $summary, $screen, $facts])
                        <div id="module-panel-{{ $key }}" role="tabpanel" aria-labelledby="module-tab-{{ $key }}"
                            class="col-start-1 row-start-1 mk-scene md:grid md:grid-cols-12 md:gap-8 md:items-start"
                            x-show="active === '{{ $key }}'" @if ($i > 0) x-cloak @endif>
                            <x-marketing.window :title="$name" class="md:col-span-7">
                                <x-dynamic-component :component="'marketing.screens.'.$screen" />
                            </x-marketing.window>
                            <div class="mt-6 md:mt-2 md:col-span-5">
                                <p class="lg:hidden text-[15px] text-ink-muted">{{ $summary }}</p>
                                <ul class="mt-4 lg:mt-0 space-y-3">
                                    @foreach ($facts as $fact)
                                        <li class="flex gap-3 text-[15px] leading-relaxed">
                                            <span class="mt-[9px] w-1.5 h-1.5 rounded-full bg-brand-500 shrink-0" aria-hidden="true"></span>
                                            <span>{{ $fact }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <a href="{{ route('marketing.features') }}#{{ $key }}" class="mt-6 inline-block text-[15px] text-brand-600 hover:text-brand-500 underline underline-offset-4 decoration-brand-500/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 rounded-sm">
                                    {{ $name }} bölümünün ayrıntısı
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Finans vitrini: grafik görünür olunca bir kez çizilir. --}}
    <section class="bg-panel-900 text-white">
        <div class="mx-auto max-w-[1180px] px-5 sm:px-6 py-20 sm:py-28 lg:grid lg:grid-cols-12 lg:gap-14 lg:items-center">
            <div class="lg:col-span-5">
                <h2 class="text-[28px] sm:text-[34px] leading-[1.1] tracking-[-0.025em] font-semibold max-w-[18ch]">
                    Stoğun yanında, kliniğin parası da
                </h2>
                <p class="mt-4 text-[16px] text-white/60 leading-relaxed max-w-[50ch]">
                    Finans bölümü kliniğin ön muhasebesidir. Kasa, banka ve POS hesaplarınızı, gelir ve
                    giderinizi, kime ne borçlu olduğunuzu ve kimden ne alacağınızı aynı programda tutarsınız.
                </p>

                <dl class="mt-10 grid sm:grid-cols-2 gap-x-8 gap-y-6">
                    @foreach ([
                        ['Kasa, banka, POS', 'Her hesabın bakiyesi hareketlerden hesaplanır; POS\'tan bankaya geçişte komisyon ayrı gider olur.'],
                        ['Cariler ve vadeler', 'Sigorta kurumu, depo, laboratuvar. Vade boşsa carinin vade süresiyle dolar, yaklaşınca hatırlatılır.'],
                        ['Tekrarlayan kalemler', 'Kira, maaş, abonelik bir kez tanımlanır; her dönemin ödemesi kendiliğinden listeye düşer.'],
                        ['Mutabakat', 'Banka ekstresindeki bakiyeyi girersiniz; fark varsa düzeltme kaydıyla kapanır.'],
                    ] as [$title, $text])
                        <div>
                            <dt class="text-[15px] font-medium">{{ $title }}</dt>
                            <dd class="mt-1.5 text-[14px] text-white/55 leading-relaxed">{{ $text }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="mt-14 lg:mt-0 lg:col-span-7">
                <figure class="mk-reveal rounded-xl border border-panel-line bg-panel-800 p-5 sm:p-7"
                    x-data="{ seen: false }" x-intersect.once="seen = true" x-bind:class="seen && 'is-visible'">
                    <figcaption class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
                        <span class="text-[15px] font-medium">Gelir-Gider, son 6 ay</span>
                        <span class="flex gap-4 text-[12px] text-white/55">
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-brand-400" aria-hidden="true"></span>Gelir</span>
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-[#c98a63]" aria-hidden="true"></span>Gider</span>
                            <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-white/80" aria-hidden="true"></span>Toplam nakit</span>
                        </span>
                    </figcaption>

                    @php
                        $plotH = 160;
                        $max = 70;
                        $slot = 100;
                        $cash = [150, 154, 155, 166, 155, 168];
                        $points = collect($cash)->map(fn ($v, $i) => ($i * $slot + 50).','.round(185 - ($v - 140) / 40 * 150))->implode(' ');
                    @endphp
                    <svg viewBox="0 0 600 200" class="mt-6 w-full h-auto" role="img"
                        aria-label="Son altı ayın örnek gelir ve gider çubukları; toplam nakit çizgisi yükseliyor. Beşinci ayda gider gelirden fazla.">
                        @foreach ([0, 0.5, 1] as $grid)
                            <line x1="0" x2="600" y1="{{ 180 - $grid * $plotH }}" y2="{{ 180 - $grid * $plotH }}" stroke="rgb(255 255 255 / 0.08)" />
                        @endforeach
                        @foreach ($income as $i => $value)
                            <rect class="mk-bar" style="--d: {{ $i * 0.08 }}s" x="{{ $i * $slot + 26 }}" y="{{ 180 - $value / $max * $plotH }}" width="22" height="{{ $value / $max * $plotH }}" rx="2" fill="#4fb3a9" />
                            <rect class="mk-bar" style="--d: {{ $i * 0.08 + 0.04 }}s" x="{{ $i * $slot + 52 }}" y="{{ 180 - $expense[$i] / $max * $plotH }}" width="22" height="{{ $expense[$i] / $max * $plotH }}" rx="2" fill="#c98a63" />
                        @endforeach
                        <polyline class="mk-line" points="{{ $points }}" pathLength="1" fill="none" stroke="rgb(255 255 255 / 0.85)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    </svg>
                    <div class="mt-2 grid grid-cols-6 text-center text-[12px] text-white/45">
                        @foreach ($months as $month)
                            <span>{{ $month }}</span>
                        @endforeach
                    </div>

                    <p class="mt-6 pt-5 border-t border-panel-line text-[13px] text-white/50 leading-relaxed">
                        Raporlar sekmesinde Gelir-Gider, Nakit Akışı ve Yaşlandırma; şube, kategori ve hesap
                        süzgeçleriyle, Excel ve PDF olarak. Rakamlar örnektir.
                    </p>
                </figure>
            </div>
        </div>
    </section>

    {{-- Sık sorulanlar --}}
    <section class="bg-canvas border-b border-line">
        <div class="mx-auto max-w-[1180px] px-5 sm:px-6 py-20 sm:py-28 lg:grid lg:grid-cols-12 lg:gap-12">
            <h2 class="lg:col-span-4 text-[28px] sm:text-[34px] leading-[1.1] tracking-[-0.025em] font-semibold">
                Sık sorulanlar
            </h2>

            <div class="mt-10 lg:mt-0 lg:col-span-8 divide-y divide-line border-y border-line">
                @foreach ($faq as $i => [$question, $answer])
                    <div x-data="{ open: false }">
                        <h3>
                            <button type="button" class="w-full flex items-center justify-between gap-6 py-5 text-left text-[17px] font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 rounded-sm"
                                x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="faq-{{ $i }}">
                                {{ $question }}
                                <svg viewBox="0 0 16 16" class="w-4 h-4 shrink-0 text-ink-muted transition-transform duration-300" x-bind:class="open && 'rotate-45'" aria-hidden="true">
                                    <path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                </svg>
                            </button>
                        </h3>
                        <div id="faq-{{ $i }}" x-show="open" x-collapse x-cloak>
                            <p class="pb-6 text-[15px] text-ink-muted leading-relaxed max-w-[66ch]">{{ $answer }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-[1180px] px-5 sm:px-6 py-20 sm:py-24">
        <div class="rounded-xl bg-panel-900 text-white px-8 py-12 sm:px-12 sm:py-14 lg:flex lg:items-center lg:gap-12">
            <div class="lg:flex-1">
                <h2 class="text-[24px] sm:text-[30px] leading-tight tracking-[-0.02em] font-semibold max-w-[24ch]">
                    Kliniğinize uyar mı, konuşarak bakalım
                </h2>
                <p class="mt-4 text-[16px] text-white/60 leading-relaxed max-w-[58ch]">
                    Kaç şubeniz ve deponuz olduğunu, bugün stoğu ve kasayı nasıl tuttuğunuzu yazın, size dönelim.
                    Görüştükten sonra kliniğinizi ve yönetici hesabınızı biz kurarız.
                </p>
            </div>
            <a href="{{ route('marketing.contact') }}" class="mt-8 lg:mt-0 inline-block shrink-0 bg-brand-500 text-white rounded-md px-6 py-3 text-[15px] font-medium hover:bg-brand-400 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">
                Talep gönder
            </a>
        </div>
    </section>
</div>
