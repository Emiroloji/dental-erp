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
        // Hero'daki lot tablosu ürünün kendi ekranından bir kesit: aynı ürünün
        // üç lotu, FEFO sırasıyla ve gerçek gün sayılarıyla.
        return [
            'lots' => [
                ['no' => 'LOT-2291', 'days' => 12, 'quantity' => 18, 'level' => 'critical'],
                ['no' => 'LOT-2310', 'days' => 164, 'quantity' => 40, 'level' => 'normal'],
                ['no' => 'LOT-2344', 'days' => 426, 'quantity' => 60, 'level' => 'normal'],
            ],
        ];
    }
};
?>

<div>
    <section class="bg-panel-900 text-white">
        <div class="mx-auto max-w-[1120px] px-5 sm:px-6 pt-16 pb-20 sm:pt-24 sm:pb-28 lg:grid lg:grid-cols-12 lg:gap-14 lg:items-center">
            <div class="lg:col-span-7">
                <h1 class="text-[34px] sm:text-[44px] lg:text-[52px] leading-[1.08] tracking-[-0.03em] font-semibold max-w-[16ch]">
                    Kliniğin stoğunda tek bir doğru sayı olsun.
                </h1>
                <p class="mt-6 text-[17px] leading-relaxed text-white/65 max-w-[54ch]">
                    Dental ERP, çok şubeli diş kliniklerinin malzeme stoğunu lot ve son kullanma tarihi
                    seviyesinde tutar. Hangi partinin ne zaman geldiğini, nerede durduğunu, ne zaman
                    tükeneceğini ve kimin çıkardığını kaydın kendisinden okursunuz.
                </p>

                <div class="mt-9 flex flex-wrap items-center gap-3">
                    <a href="{{ route('marketing.contact') }}" class="bg-brand-500 text-white rounded-md px-5 py-3 text-[15px] font-medium hover:bg-brand-400 transition-colors">
                        Talep gönderin, size dönelim
                    </a>
                    <a href="{{ route('marketing.features') }}" class="px-5 py-3 text-[15px] text-white/75 hover:text-white rounded-md border border-panel-line hover:border-white/30 transition-colors">
                        Neler yapıyor
                    </a>
                </div>
            </div>

            <div class="mt-14 lg:mt-0 lg:col-span-5">
                <figure class="rounded-lg border border-panel-line bg-panel-800 overflow-hidden">
                    <figcaption class="flex items-baseline justify-between gap-3 px-4 sm:px-5 py-4 border-b border-panel-line">
                        <span class="text-[15px] font-medium text-white">Kompozit A</span>
                        <span class="text-[12px] px-2 py-0.5 rounded border border-status-critical/40 bg-status-critical/15 text-[#e6938a] font-medium">Kırmızı</span>
                    </figcaption>

                    <table class="w-full text-[13px]">
                        <caption class="sr-only">Kompozit A ürününün depodaki lotları</caption>
                        <thead>
                            <tr class="text-white/40 text-[12px]">
                                <th scope="col" class="text-left font-normal pl-4 sm:pl-5 pr-2 pt-3 pb-1.5">Lot</th>
                                <th scope="col" class="text-left font-normal px-2 pt-3 pb-1.5">Son kullanma</th>
                                <th scope="col" class="text-right font-normal pl-2 pr-4 sm:pr-5 pt-3 pb-1.5">Miktar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lots as $lot)
                                <tr class="border-t border-panel-line/70">
                                    <td class="pl-4 sm:pl-5 pr-2 py-3 font-mono text-[12px] sm:text-[13px] text-white/80">{{ $lot['no'] }}</td>
                                    <td class="px-2 py-3">
                                        <span class="text-white/80 font-mono">{{ now()->addDays($lot['days'])->format('d.m.Y') }}</span>
                                        <span class="block mt-0.5 {{ $lot['level'] === 'critical' ? 'text-status-critical' : 'text-white/40' }}">{{ $lot['days'] }} gün kaldı</span>
                                    </td>
                                    <td class="pl-2 pr-4 sm:pr-5 py-3 text-right tabular-nums text-white whitespace-nowrap">{{ $lot['quantity'] }} adet</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <p class="px-4 sm:px-5 py-3.5 border-t border-panel-line text-[13px] text-white/50 leading-relaxed">
                        Çıkış yapıldığında önce LOT-2291 düşer: son kullanma tarihi önce dolan parti önce kullanılır.
                    </p>
                </figure>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-[1120px] px-5 sm:px-6 py-20 sm:py-24">
        <h2 class="text-[26px] sm:text-[30px] leading-tight tracking-[-0.02em] font-semibold max-w-[20ch]">
            Klinik stoğu üç soruya iner
        </h2>
        <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[62ch]">
            Excel tablosu bu üç soruyu birlikte cevaplayamaz: şubeye, depoya ve parti numarasına bölünmez,
            kimin ne zaman ne çıkardığını tutmaz, geriye dönük değiştirildiğinde iz bırakmaz.
        </p>

        <dl class="mt-12 divide-y divide-line border-y border-line">
            <div class="py-7 sm:grid sm:grid-cols-12 sm:gap-8">
                <dt class="sm:col-span-4 text-[17px] font-medium">Elimde tam olarak ne var?</dt>
                <dd class="sm:col-span-8 mt-2 sm:mt-0 text-[15px] text-ink-muted leading-relaxed max-w-[64ch]">
                    Stok, deponun içinde parti parti durur. Her partinin kendi son kullanma tarihi ve alış fiyatı
                    vardır; şube görünümü o şubeye bağlı depoların toplamıdır. Mevcut stok ile toplam kullanım
                    ayrı ayrı raporlanır — 100 adet girip 20 kullandıysanız ikisi de görünür.
                </dd>
            </div>
            <div class="py-7 sm:grid sm:grid-cols-12 sm:gap-8">
                <dt class="sm:col-span-4 text-[17px] font-medium">Ne zaman sipariş vermeliyim?</dt>
                <dd class="sm:col-span-8 mt-2 sm:mt-0 text-[15px] text-ink-muted leading-relaxed max-w-[64ch]">
                    Her ürün kendi uyarı eşiğini taşır: kimi ürün için "60 adedin altına inince",
                    kimi için "son kullanma tarihine 50 gün kala", isterseniz ikisi birden. Eşiğe gelen ürün
                    sarıya, kritik eşiğe gelen kırmızıya düşer ve ilgili personele bildirim gider.
                </dd>
            </div>
            <div class="py-7 sm:grid sm:grid-cols-12 sm:gap-8">
                <dt class="sm:col-span-4 text-[17px] font-medium">Bu kayıt kimin işi?</dt>
                <dd class="sm:col-span-8 mt-2 sm:mt-0 text-[15px] text-ink-muted leading-relaxed max-w-[64ch]">
                    Stok hareketi silinmez. Yanlış giriş iptal edilir, iptal de bir kayıt olarak durur; sayım farkı
                    düzeltme hareketine dönüşür. Kimin ne zaman neyi değiştirdiği, öncesi ve sonrasıyla
                    denetim kaydına yazılır.
                </dd>
            </div>
        </dl>
    </section>

    <section class="bg-canvas border-y border-line">
        <div class="mx-auto max-w-[1120px] px-5 sm:px-6 py-20 sm:py-24">
            <h2 class="text-[26px] sm:text-[30px] leading-tight tracking-[-0.02em] font-semibold">
                Bir kutu eldivenin klinikteki yolu
            </h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[62ch]">
                Sistemdeki her adım gerçek bir işe karşılık gelir. Stoğun hangi adımda değiştiği bellidir;
                arada kalan hiçbir yerde miktar kendiliğinden oynamaz.
            </p>

            <ol class="mt-12 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-px bg-line border border-line rounded-lg overflow-hidden">
                @foreach ([
                    ['1', 'Talep açılır', 'Ortodonti deposunda eldiven azalır; depo sorumlusu satın alma talebi açar.', 'Stok değişmez'],
                    ['2', 'Onaylanır', 'Klinik sahibi talebi onaylar, tedarikçiye sipariş verilir.', 'Stok değişmez'],
                    ['3', 'Teslim alınır', 'Gelen 8 kutu lot numarası, son kullanma tarihi ve fatura bilgisiyle girilir; 2 kutu açık sipariş kalır.', 'Stok artar'],
                    ['4', 'Kullanılır', 'Klinikte kullanılan miktar çıkış olarak düşülür; başka şubeye transfer gönderim anında düşer.', 'Stok azalır'],
                ] as [$step, $title, $text, $effect])
                    <li class="bg-surface px-5 py-6 flex flex-col">
                        <span class="text-[13px] text-ink-muted tabular-nums">Adım {{ $step }}</span>
                        <h3 class="mt-2 text-[16px] font-medium">{{ $title }}</h3>
                        <p class="mt-2 text-[14px] text-ink-muted leading-relaxed flex-1">{{ $text }}</p>
                        <span class="mt-4 text-[13px] {{ $effect === 'Stok değişmez' ? 'text-ink-muted' : 'text-brand-600' }}">{{ $effect }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="mx-auto max-w-[1120px] px-5 sm:px-6 py-20 sm:py-24">
        <div class="lg:grid lg:grid-cols-12 lg:gap-14">
            <div class="lg:col-span-5">
                <h2 class="text-[26px] sm:text-[30px] leading-tight tracking-[-0.02em] font-semibold max-w-[18ch]">
                    Uyarıyı ürünün kendisi belirler
                </h2>
                <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[52ch]">
                    Eldiven adede göre biter, anestezik tarihe göre bozulur, implantın ikisi de önemlidir.
                    Bu yüzden eşik organizasyon geneli tek bir sayı değil, ürün kartında seçilen bir kural.
                </p>
                <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[52ch]">
                    Eşik girmezseniz ürün varsayılanla çalışmaya devam eder; hiçbir ürünü tek tek
                    ayarlamak zorunda değilsiniz.
                </p>
            </div>

            <div class="mt-10 lg:mt-0 lg:col-span-7 space-y-3">
                <article class="rounded-lg border border-line bg-surface px-5 py-5">
                    <div class="flex items-baseline justify-between gap-4">
                        <h3 class="text-[16px] font-medium">Miktara göre</h3>
                        <span class="text-[13px] text-ink-muted">Muayene eldiveni</span>
                    </div>
                    <p class="mt-2.5 text-[14px] text-ink-muted leading-relaxed">
                        60 adedin altına inince sarı, 30 adedin altına inince kırmızı. Son kullanma tarihi
                        uzak olduğu sürece yalnızca adet konuşur.
                    </p>
                </article>
                <article class="rounded-lg border border-line bg-surface px-5 py-5">
                    <div class="flex items-baseline justify-between gap-4">
                        <h3 class="text-[16px] font-medium">Son kullanma tarihine göre</h3>
                        <span class="text-[13px] text-ink-muted">Lokal anestezik</span>
                    </div>
                    <p class="mt-2.5 text-[14px] text-ink-muted leading-relaxed">
                        Tarihe 50 gün kala sarı, 30 gün kala kırmızı. Depoda yüz kutu olsa bile yaklaşan
                        parti uyarı verir.
                    </p>
                </article>
                <article class="rounded-lg border border-brand-500/30 bg-brand-100/60 px-5 py-5">
                    <div class="flex items-baseline justify-between gap-4">
                        <h3 class="text-[16px] font-medium">İkisi birden</h3>
                        <span class="text-[13px] text-ink-muted">İmplant, kompozit</span>
                    </div>
                    <p class="mt-2.5 text-[14px] text-ink-muted leading-relaxed">
                        Hem adet hem tarih izlenir; hangisi önce eşiğe ulaşırsa ürün o seviyeye geçer.
                        Miktar sarıdayken tarih kırmızıya girdiyse ürün kırmızı görünür.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <section class="bg-canvas border-y border-line">
        <div class="mx-auto max-w-[1120px] px-5 sm:px-6 py-20 sm:py-24">
            <h2 class="text-[26px] sm:text-[30px] leading-tight tracking-[-0.02em] font-semibold">
                Sistemde neler var
            </h2>

            <ul class="mt-10 divide-y divide-line border-y border-line">
                @foreach ([
                    ['Ürün ve katalog', 'Kategori, tedarikçi, ürün kartı ve birim çevrimi: "1 kutu = 50 adet" tanımlarsınız, kutu girip adet olarak kaydedilir.'],
                    ['Lot, son kullanma tarihi, seri no', 'Her parti ayrı satır; çıkışta parti seçmezseniz tarihi önce dolan düşer. İmplant gibi ürünler tek tek seri numarasıyla izlenir.'],
                    ['Şube ve depo', 'Bir şubenin altında merkezi depo, cerrahi depo, ortodonti deposu ayrı ayrı tutulur; küçük klinik tek depo gibi çalışır.'],
                    ['Transfer', 'Şubeler ve depolar arası talep, onay, hazırlık, gönderim, teslim. Stok gönderimde kaynaktan düşer, teslimde hedefe eklenir.'],
                    ['Satın alma', 'Talep, onay, sipariş ve kısmi teslimat; teslim alınan miktar stoğa girer, kalanı açık sipariş olarak görünür.'],
                    ['Sayım ve iade', 'Sayım farkı onaylanınca iz bırakan düzeltme hareketine dönüşür; tedarikçiye iade ayrı bir süreç olarak takip edilir.'],
                    ['Raporlar ve tahmin', 'Tarih, şube, depo, kategori ve tedarikçi filtreleri, Excel ve PDF çıktısı, geçmiş tüketimden tükenme tahmini.'],
                    ['Barkod ve telefon', 'Barkodu kamerayla ya da el okuyucusuyla okutup giriş-çıkış yaparsınız; uygulama telefona kurulabilir.'],
                    ['Yetki ve denetim', 'Her personele modül modül okuma, yazma, silme kutucukları ve şube kapsamı verilir; kritik işlemler denetim kaydına yazılır.'],
                ] as [$title, $text])
                    <li class="py-6 sm:grid sm:grid-cols-12 sm:gap-8">
                        <h3 class="sm:col-span-4 text-[16px] font-medium">{{ $title }}</h3>
                        <p class="sm:col-span-8 mt-1.5 sm:mt-0 text-[15px] text-ink-muted leading-relaxed max-w-[66ch]">{{ $text }}</p>
                    </li>
                @endforeach
            </ul>

            <a href="{{ route('marketing.features') }}" class="mt-10 inline-block text-[15px] text-brand-600 hover:text-brand-500 underline underline-offset-4 decoration-brand-500/30">
                Hepsinin ayrıntısı
            </a>
        </div>
    </section>

    <section class="mx-auto max-w-[1120px] px-5 sm:px-6 py-20 sm:py-24">
        <div class="rounded-lg bg-panel-900 text-white px-8 py-12 sm:px-12 sm:py-14 lg:flex lg:items-center lg:gap-12">
            <div class="lg:flex-1">
                <h2 class="text-[24px] sm:text-[28px] leading-tight tracking-[-0.02em] font-semibold max-w-[24ch]">
                    Kliniğinize uyar mı, konuşarak bakalım
                </h2>
                <p class="mt-4 text-[16px] text-white/60 leading-relaxed max-w-[58ch]">
                    Kaç şubeniz ve deponuz olduğunu yazın, size dönelim. Form doldurmak hesap açmaz:
                    görüştükten sonra kliniğinizi ve yönetici hesabınızı biz kurar, giriş bilgilerinizi göndeririz.
                </p>
            </div>
            <a href="{{ route('marketing.contact') }}" class="mt-8 lg:mt-0 inline-block shrink-0 bg-brand-500 text-white rounded-md px-6 py-3 text-[15px] font-medium hover:bg-brand-400 transition-colors">
                Talep gönder
            </a>
        </div>
    </section>
</div>
