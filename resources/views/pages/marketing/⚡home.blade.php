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
};
?>

<div>
    <section class="bg-panel-900 text-white relative overflow-hidden">
        <div class="absolute inset-0 opacity-[0.07]" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 22px 22px;"></div>

        <div class="relative max-w-5xl mx-auto px-6 py-20 sm:py-28">
            <p class="text-[13px] uppercase tracking-wide text-brand-400">Diş klinikleri ve hastaneler için stok yönetimi</p>
            <h1 class="mt-4 text-[32px] sm:text-[42px] leading-tight font-medium tracking-tight max-w-2xl">
                Şubeler, depolar ve lotlar arasında stoğunuzun tek doğru sayısı.
            </h1>
            <p class="mt-5 text-[16px] text-white/60 leading-relaxed max-w-xl">
                Her giriş, çıkış ve transfer kayıt altında. Hangi lotun ne zaman geldiğini,
                son kullanma tarihinin ne zaman dolacağını ve stoğun ne zaman biteceğini her an bilin.
            </p>

            <div class="mt-9 flex flex-wrap items-center gap-4">
                <a href="{{ route('marketing.contact') }}" class="bg-brand-500 text-white rounded-md px-5 py-2.5 text-[14px] font-medium hover:bg-brand-600 transition-colors">
                    Talep gönderin, biz arayalım
                </a>
                <a href="{{ route('marketing.features') }}" class="text-[14px] text-white/70 hover:text-white transition-colors">
                    Neler yapıyor?
                </a>
            </div>
        </div>
    </section>

    <section class="max-w-5xl mx-auto px-6 py-20">
        <h2 class="text-[22px] font-medium tracking-tight">Klinikte asıl sorun stoğu saymak değil, doğru saymak</h2>
        <p class="mt-2 text-[15px] text-ink-muted max-w-2xl leading-relaxed">
            Excel tablosu şubeye, depoya ve lota bölünmez; kimin ne zaman ne çıkardığını tutmaz.
            Dental ERP bu üç soruyu kaydın kendisinden cevaplar.
        </p>

        <div class="mt-10 grid grid-cols-1 sm:grid-cols-3 gap-5">
            <article class="border border-line rounded-lg bg-surface px-5 py-6">
                <h3 class="text-[15px] font-medium">Lot ve son kullanma tarihi</h3>
                <p class="mt-2 text-[14px] text-ink-muted leading-relaxed">
                    Her giriş kendi lot numarası, SKT'si ve alış fiyatıyla durur. Çıkışta lot seçilmezse
                    SKT'si önce dolacak lot otomatik düşer (FEFO); süresi geçmiş lot kullanımda işaretlenir.
                </p>
            </article>
            <article class="border border-line rounded-lg bg-surface px-5 py-6">
                <h3 class="text-[15px] font-medium">Şube ve depo ayrımı</h3>
                <p class="mt-2 text-[14px] text-ink-muted leading-relaxed">
                    Stok fiilen depoda tutulur, şube görünümü o şubenin depolarının toplamıdır.
                    Tek şubeli klinikte tek depo gibi, çok şubeli hastanede gerçek hiyerarşi gibi çalışır.
                </p>
            </article>
            <article class="border border-line rounded-lg bg-surface px-5 py-6">
                <h3 class="text-[15px] font-medium">Ürününe göre uyarı</h3>
                <p class="mt-2 text-[14px] text-ink-muted leading-relaxed">
                    Her ürün kendi sarı/kırmızı eşiğini taşır: kimi ürün için "60 adetin altı",
                    kimi için "SKT'ye 50 gün kala". Uyarı ilgili personele ve klinik sahibine düşer.
                </p>
            </article>
        </div>
    </section>

    <section class="border-y border-line bg-surface">
        <div class="max-w-5xl mx-auto px-6 py-16">
            <h2 class="text-[22px] font-medium tracking-tight">Günlük akış</h2>
            <ol class="mt-8 grid grid-cols-1 sm:grid-cols-4 gap-6">
                @foreach ([
                    ['Talep', 'Depodaki ürün azalır, personel satın alma talebi açar.'],
                    ['Onay', 'Klinik sahibi talebi onaylar, tedarikçiye sipariş verilir.'],
                    ['Teslim', 'Gelen miktar lot ve SKT ile stoğa girer; kalanı açık sipariş kalır.'],
                    ['Kullanım', 'Çıkış, transfer ve sayım farkları iz bırakan hareketler olarak işlenir.'],
                ] as $index => [$title, $text])
                    <li>
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-brand-100 text-brand-600 text-[13px] font-medium tabular-nums">{{ $index + 1 }}</span>
                        <h3 class="mt-3 text-[15px] font-medium">{{ $title }}</h3>
                        <p class="mt-1.5 text-[14px] text-ink-muted leading-relaxed">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="max-w-5xl mx-auto px-6 py-20">
        <div class="border border-line rounded-lg bg-surface px-8 py-10 sm:flex sm:items-center sm:gap-10">
            <div class="flex-1">
                <h2 class="text-[20px] font-medium tracking-tight">Kliniğinize uygun mu, birlikte bakalım</h2>
                <p class="mt-2 text-[14px] text-ink-muted leading-relaxed max-w-lg">
                    Formu doldurun; şube ve depo sayınıza göre neyin nasıl kurulacağını konuşalım.
                    Form doldurmak hesap açmaz — hesabınızı biz, görüştükten sonra açarız.
                </p>
            </div>
            <a href="{{ route('marketing.contact') }}" class="mt-6 sm:mt-0 inline-block bg-panel-900 text-white rounded-md px-5 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors shrink-0">
                Talep Gönder
            </a>
        </div>
    </section>
</div>
