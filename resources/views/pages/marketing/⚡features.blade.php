<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::marketing')] class extends Component
{
    public function mount(): void
    {
        if (auth()->check()) {
            $this->redirect('/dashboard');
        }
    }

    public function with(): array
    {
        return [
            'index' => [
                ['hiyerarsi', 'Kurgu: platform, klinik, şube, depo'],
                ['stok', 'Stok çekirdeği'],
                ['katalog', 'Ürün ve katalog'],
                ['depo', 'Şube ve depo'],
                ['transfer', 'Şubeler arası transfer'],
                ['satinalma', 'Satın alma ve sipariş'],
                ['sayim', 'Stok sayımı'],
                ['iade', 'İade yönetimi'],
                ['uyari', 'Uyarılar ve bildirimler'],
                ['rapor', 'Raporlar, tahmin, asistan'],
                ['barkod', 'Barkod, etiket, telefon'],
                ['ilac', 'İlaç ve medikal ürünler'],
                ['yetki', 'Yetkiler ve denetim kaydı'],
                ['guvenlik', 'Veri izolasyonu ve güvenlik'],
                ['paket', 'Paketler ve hesap açılışı'],
                ['kapsam', 'Kapsam dışı bıraktıklarımız'],
            ],
            'permissionModules' => [
                'Ürün yönetimi', 'Kategori yönetimi', 'Tedarikçi yönetimi', 'Stok giriş/çıkış',
                'Şubeler arası talep/transfer', 'Satın alma', 'Personel yönetimi', 'Raporlar', 'Sistem ayarları',
            ],
        ];
    }
};
?>

<div>
    <section class="border-b border-line">
        <div class="mx-auto max-w-[1120px] px-5 sm:px-6 pt-16 pb-14 sm:pt-20">
            <h1 class="text-[32px] sm:text-[40px] leading-[1.1] tracking-[-0.03em] font-semibold max-w-[22ch]">
                Bir diş kliniğinin malzeme işini baştan sona kapsar
            </h1>
            <p class="mt-6 text-[17px] text-ink-muted leading-relaxed max-w-[68ch]">
                Dental ERP bir stok yönetim sistemidir. Malzemenin tedarikçiden kliniğe girişini, depolar
                arasında dolaşımını, hastada kullanılmasını ve kayıttan çıkışını yönetir. Aşağıda sistemin
                bugün gerçekten yaptığı işler var; yapmadıklarını da sonunda açıkça yazdık.
            </p>

            <nav aria-label="Sayfa içeriği" class="mt-12 border-t border-line pt-8">
                <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-10 gap-y-2.5 text-[14px]">
                    @foreach ($index as [$anchor, $label])
                        <li>
                            <a href="#{{ $anchor }}" class="text-ink-muted hover:text-ink transition-colors">{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </section>

    <div class="mx-auto max-w-[1120px] px-5 sm:px-6">
        <section id="hiyerarsi" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Kurgu: platform, klinik, şube, depo</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Sistem beş seviyeden oluşur ve her seviye bir üstündekine bağlıdır. Stok fiilen en alttaki
                depoda durur; yukarıdaki her seviye altındakilerin toplamını görür.
            </p>

            <ol class="mt-8 space-y-px bg-line border border-line rounded-lg overflow-hidden">
                @foreach ([
                    ['Platform', 'Yazılımın sahibi. Klinik hesaplarını açar, paketleri yönetir. Hiçbir kliniğin stok verisine girmez.'],
                    ['Klinik (hastane)', 'Sizin hesabınız. Klinik sahibi kendi hesabında sınırsız yetkilidir: şube açar, personel ekler, yetki verir.'],
                    ['Şube', 'Kadıköy, Beşiktaş, Ortodonti merkezi… Her şubenin stoğu ayrı tutulur, klinik sahibi hepsini birlikte görür.'],
                    ['Depo', 'Şubenin içindeki stok alanları: merkezi depo, cerrahi depo, klinik dolabı. Küçük kurulumda tek depo yeter.'],
                    ['Personel', 'Bir veya birden çok şubeye atanır; yalnızca kendisine işaretlenmiş modüllerde işlem yapar.'],
                ] as $i => [$title, $text])
                    <li class="bg-surface px-5 py-5 sm:flex sm:gap-8">
                        <h3 class="sm:w-48 shrink-0 text-[16px] font-medium" style="padding-left: {{ $i * 14 }}px">{{ $title }}</h3>
                        <p class="mt-1.5 sm:mt-0 text-[15px] text-ink-muted leading-relaxed max-w-[62ch]">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        <section id="stok" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Stok çekirdeği</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Sistemin en kritik parçası burası: stok yalnızca tek bir yoldan değişir. Giriş, çıkış, transfer,
                sayım düzeltmesi, iade — hepsi aynı kapıdan geçer. Bu yüzden rakam hiçbir ekranda "elle"
                oynatılamaz ve her değişikliğin bir sahibi, nedeni, tarihi vardır.
            </p>

            <dl class="mt-8 divide-y divide-line border-y border-line">
                @foreach ([
                    ['Negatif stok oluşmaz', 'Depoda 12 adet varken 15 adet çıkış yapılamaz; işlem baştan reddedilir. Transferde de kaynak depoda yeterli stok yoksa gönderim yapılamaz.'],
                    ['Kayıt silinmez, iptal edilir', 'Yanlış girilen hareket silinmez; ters kayıt oluşturan bir iptal işlemiyle geri alınır. Hem yanlış hareket hem iptali geçmişte durur.'],
                    ['Parti seçmezseniz FEFO', 'Çıkışta lot seçebilirsiniz; seçmezseniz son kullanma tarihi önce dolacak parti otomatik düşer. Süresi geçmiş parti ayrıca işaretlenir.'],
                    ['Mevcut stok ve kullanım ayrı', '100 adet girip 20 adet kullandıysanız mevcut stok 80, toplam kullanım 20 olarak ayrı raporlanır. Transfer ve iade, klinik içi kullanıma karışmaz.'],
                    ['Çıkış nedeni zorunlu', 'Klinik içi kullanım, sarf, hasarlı ürün, süresi geçmiş, iade, transfer, diğer… Neden alanı raporlarda kullanımı gerçek tüketimden ayırmayı sağlar.'],
                ] as [$title, $text])
                    <div class="py-6 sm:grid sm:grid-cols-12 sm:gap-8">
                        <dt class="sm:col-span-4 text-[16px] font-medium">{{ $title }}</dt>
                        <dd class="sm:col-span-8 mt-1.5 sm:mt-0 text-[15px] text-ink-muted leading-relaxed max-w-[66ch]">{{ $text }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section id="katalog" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Ürün ve katalog</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Ürün kartı kliniğin sözlüğüdür: aynı malzemeyi herkes aynı adla, aynı birimle kaydeder.
                Kategoriler ve tedarikçiler klinik geneli tutulur, bütün şubeler aynı havuzu kullanır.
            </p>

            <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-x-12 gap-y-8">
                <div>
                    <h3 class="text-[16px] font-medium">Ürün kartında neler var</h3>
                    <p class="mt-2 text-[15px] text-ink-muted leading-relaxed max-w-[58ch]">
                        Ad, ürün kodu, barkod, kategori, tedarikçi, ürün tipi (sarf, ekipman, ilaç), ana birim,
                        alış fiyatı, minimum ve maksimum stok seviyesi, uyarı eşiği ve aktif/pasif durumu.
                        Ürün silinmez, pasife alınır — geçmiş hareketleri ayakta kalır.
                    </p>
                </div>
                <div>
                    <h3 class="text-[16px] font-medium">Birim çevrimi</h3>
                    <p class="mt-2 text-[15px] text-ink-muted leading-relaxed max-w-[58ch]">
                        Ürünün veritabanındaki tek bir ana birimi olur. "1 kutu = 50 adet" tanımlarsınız,
                        kullanıcı 3 kutu girer, sistem 150 adet olarak kaydeder. Ayrı bir çoklu birim stok
                        tablosu tutulmaz; böylece iki farklı birimde iki farklı doğru oluşmaz.
                    </p>
                </div>
            </div>
        </section>

        <section id="depo" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Şube ve depo</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Büyük bir hastanede tek şubenin içinde birden çok stok alanı olur. Bu yüzden şubenin altına
                depo katmanı konuldu: stok depoda durur, şube görünümü o şubeye bağlı depoların toplamıdır.
                Her depoya sorumlu personel atanır, depo bazlı stok raporu ve uyarıları ayrıca alınır.
            </p>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Tek şubeli küçük bir klinikte bu katman görünmez: sistem her şubeye bir varsayılan depo açar
                ve kullanıcı tek depo gibi çalışır.
            </p>
        </section>

        <section id="transfer" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Şubeler arası transfer</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Bir şube başka bir şubeden ürün ister. Talep, onay, hazırlık, gönderim ve teslim adımlarından
                geçer; aynı akış bir şubenin iki deposu arasında da çalışır.
            </p>

            <ol class="mt-8 flex flex-wrap gap-2 text-[14px]">
                @foreach (['Bekliyor', 'Onaylandı', 'Hazırlanıyor', 'Gönderildi', 'Teslim alındı'] as $i => $state)
                    <li class="rounded-md border px-3.5 py-2 {{ in_array($state, ['Gönderildi', 'Teslim alındı']) ? 'border-brand-500/40 bg-brand-100/60 text-brand-600' : 'border-line text-ink-muted' }}">
                        {{ $state }}@if (in_array($state, ['Gönderildi', 'Teslim alındı'])) <span class="text-ink-muted">· stok burada değişir</span> @endif
                    </li>
                @endforeach
            </ol>

            <p class="mt-6 text-[15px] text-ink-muted leading-relaxed max-w-[68ch]">
                Gönderildi adımında stok kaynak depodan düşer, teslim alındığında hedef depoya eklenir.
                Arada kalan miktar yola çıkmış sayılır. Gönderilmeden iptal edilen talebin stoğa hiç etkisi
                olmaz; gönderildikten sonra iptal edilirse düşülen miktar kaynak depoya geri eklenir.
            </p>
        </section>

        <section id="satinalma" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Satın alma ve sipariş</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Satın alma talebi açmak stoğa dokunmaz; bu yüzden stok yetkisi olmayan bir personel de
                talep açabilir. Siparişi onaylamak klinik sahibine aittir. Teslim alma anında stok girişi
                kendiliğinden oluşur.
            </p>

            <dl class="mt-8 divide-y divide-line border-y border-line">
                @foreach ([
                    ['Kısmi teslimat', '10 kutu sipariş ettiniz, 8 kutu geldi: 8 kutu lot ve son kullanma tarihiyle stoğa girer, 2 kutu açık sipariş olarak beklemede kalır.'],
                    ['Fatura ve irsaliye', 'Teslim alırken fatura/irsaliye numarası ve belgesi kaydedilir; oluşan stok girişi hem siparişe hem tedarikçiye bağlı kalır.'],
                    ['Tedarikçi geçmişi', 'Her tedarikçinin sipariş geçmişi, teslimatları ve toplam harcaması kendi kartından görünür.'],
                ] as [$title, $text])
                    <div class="py-6 sm:grid sm:grid-cols-12 sm:gap-8">
                        <dt class="sm:col-span-4 text-[16px] font-medium">{{ $title }}</dt>
                        <dd class="sm:col-span-8 mt-1.5 sm:mt-0 text-[15px] text-ink-muted leading-relaxed max-w-[66ch]">{{ $text }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section id="sayim" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Stok sayımı</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Sayım bir oturum olarak başlar: depo seçilir, sistem beklenen miktarı gösterir, sayan kişi
                gerçek miktarı girer. Sistem farkı hesaplar, fark için neden seçilir (kayıp, hasar, kayıt
                hatası, diğer) ve klinik sahibi onaylar.
            </p>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Onaylanan fark, stoğu sessizce değiştiren bir düzenleme değil, iz bırakan bir düzeltme
                hareketine dönüşür: sistemde 100, sayımda 97 ise −3'lük düzeltme kaydı oluşur ve geçmişte
                kim onayladı, neden düzeltildi görünür.
            </p>
        </section>

        <section id="iade" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">İade yönetimi</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Hasarlı, hatalı veya yanlış sevk edilmiş ürünün tedarikçiye dönüşü ayrı bir hareket türü
                olarak izlenir: iade edilen ürün ve parti, miktar, neden, ilgili sipariş, tedarikçinin yanıtı
                ve kredi notu. Talep edildi, onaylandı, kargoya verildi, tedarikçi onayladı, tamamlandı
                adımlarıyla takip edilir; stoktan düşen miktar kullanım sayılmaz.
            </p>
        </section>

        <section id="uyari" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Uyarılar ve bildirimler</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Her ürün yeşil, sarı veya kırmızıdır. Eşiği ürünün kendisi taşır: ürün kartında miktar bazlı,
                son kullanma tarihi bazlı ya da ikisi birden seçersiniz. Eşik girmezseniz klinik geneli
                varsayılan eşik uygulanır.
            </p>

            <div class="mt-8 overflow-x-auto">
                <table class="w-full text-[15px] border border-line rounded-lg overflow-hidden">
                    <thead>
                        <tr class="bg-canvas text-left text-[13px] text-ink-muted">
                            <th scope="col" class="px-5 py-3 font-medium">Seviye</th>
                            <th scope="col" class="px-5 py-3 font-medium">Ne zaman</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr>
                            <td class="px-5 py-4"><span class="inline-flex px-2 py-0.5 rounded text-[13px] bg-status-good-bg text-status-good">Yeşil</span></td>
                            <td class="px-5 py-4 text-ink-muted">Ürün kendi sarı eşiğinin üzerinde.</td>
                        </tr>
                        <tr>
                            <td class="px-5 py-4"><span class="inline-flex px-2 py-0.5 rounded text-[13px] bg-status-warn-bg text-status-warn">Sarı</span></td>
                            <td class="px-5 py-4 text-ink-muted">Kalan miktar ya da son kullanma tarihine kalan gün, ürünün sarı eşiğine indi.</td>
                        </tr>
                        <tr>
                            <td class="px-5 py-4"><span class="inline-flex px-2 py-0.5 rounded text-[13px] bg-status-critical-bg text-status-critical">Kırmızı</span></td>
                            <td class="px-5 py-4 text-ink-muted">Ürünün kırmızı eşiğine inildi, stok tükendi ya da depoda süresi geçmiş bir parti duruyor.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-6 text-[15px] text-ink-muted leading-relaxed max-w-[68ch]">
                Uyarı, ilgili şube ve depoda stok yetkisi olan personele ve klinik sahibine düşer. Bunun
                dışında yeni talep, transfer, satın alma onayı, sayım ve iade adımları da bildirim üretir;
                bildirimler okundu bilgisiyle listelenir ve doğrudan ilgili ekrana götürür.
            </p>
        </section>

        <section id="rapor" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Raporlar, tahmin ve asistan</h2>

            <dl class="mt-8 divide-y divide-line border-y border-line">
                @foreach ([
                    ['Raporlar', 'Stok durumu, hareket geçmişi, kullanım, satın alma ve maliyet raporları; tarih aralığı, şube, depo, kategori ve tedarikçi filtreleriyle daraltılır, Excel veya PDF olarak indirilir.'],
                    ['Ana sayfa', 'Toplam ürün ve stok, sarı/kırmızı uyarı sayıları, son kullanma tarihi yaklaşan ve geçmiş partiler, bekleyen talepler, günlük giriş-çıkış, aylık kullanım, en çok kullanılan ürünler.'],
                    ['Tükenme tahmini', 'Geçmiş tüketimden hareketle bir ürünün yaklaşık kaç gün sonra biteceğini istatistiksel olarak hesaplar. Tahmin, kararı sizin yerinize vermez; sipariş kararını siz verirsiniz.'],
                    ['Doğal dille sorgulama', '"Geçen ay Kadıköy şubesinde en çok hangi ürün kullanıldı" gibi soruları yazıp cevabını rapor olarak alırsınız.'],
                ] as [$title, $text])
                    <div class="py-6 sm:grid sm:grid-cols-12 sm:gap-8">
                        <dt class="sm:col-span-4 text-[16px] font-medium">{{ $title }}</dt>
                        <dd class="sm:col-span-8 mt-1.5 sm:mt-0 text-[15px] text-ink-muted leading-relaxed max-w-[66ch]">{{ $text }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section id="barkod" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Barkod, etiket ve telefon</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Depoda en çok yapılan iş giriş ve çıkış olduğu için bu ikisi hızlı olmalı. Barkodu telefonun
                kamerasıyla ya da USB/Bluetooth el okuyucusuyla okutursunuz; ürün ve parti doğrudan ekrana
                gelir. Ürün ve parti için yazdırılabilir etiket üretilir, böylece kendi kutularınızı
                etiketleyip okutabilirsiniz.
            </p>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Uygulama telefonda tarayıcıdan çalışır ve ana ekrana eklenip uygulama gibi açılabilir;
                ayrı bir mobil uygulama indirmeniz gerekmez.
            </p>
        </section>

        <section id="ilac" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">İlaç ve medikal ürünler</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Sarf malzemesinden farklı olarak ilaç ve bazı medikal ürünler kayıt ister. Ürün kartında
                GTIN, ÜTS numarası, ruhsat numarası, üretici ve saklama koşulu alanları vardır.
            </p>

            <dl class="mt-8 divide-y divide-line border-y border-line">
                @foreach ([
                    ['Seri numarası takibi', 'İmplant gibi ürünlerde her birim ayrı seri numarasıyla girer ve çıkar; hangi serinin nerede olduğu ve nereye gittiği izlenir.'],
                    ['Soğuk zincir', 'Soğuk zincir ürününde girişte ölçülen sıcaklık istenir; saklama aralığının dışındaki giriş engellenir veya gerekçesiyle kabul edilir.'],
                    ['Kontrollü ürün', 'Kontrollü işaretli üründe her çıkışta açıklama zorunludur ve hareketleri ayrı bir kontrollü ürün defterinde listelenir.'],
                ] as [$title, $text])
                    <div class="py-6 sm:grid sm:grid-cols-12 sm:gap-8">
                        <dt class="sm:col-span-4 text-[16px] font-medium">{{ $title }}</dt>
                        <dd class="sm:col-span-8 mt-1.5 sm:mt-0 text-[15px] text-ink-muted leading-relaxed max-w-[66ch]">{{ $text }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section id="yetki" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Yetkiler ve denetim kaydı</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Sabit rol yoktur. Klinik sahibi her personel için modül modül okuma, yazma ve silme
                kutucuklarını işaretler; ayrıca kapsamı seçer: yalnızca kendi şubesi, seçili şubeler ya da
                tüm şubeler. Böylece "muhasebeci sadece raporları görsün", "depo görevlisi stok girsin ama
                silmesin" gibi kombinasyonlar kurulur.
            </p>

            <div class="mt-8 overflow-x-auto">
                <table class="w-full text-[15px] border border-line rounded-lg overflow-hidden">
                    <thead>
                        <tr class="bg-canvas text-left text-[13px] text-ink-muted">
                            <th scope="col" class="px-5 py-3 font-medium">Modül</th>
                            <th scope="col" class="px-5 py-3 font-medium text-center">Okuma</th>
                            <th scope="col" class="px-5 py-3 font-medium text-center">Yazma</th>
                            <th scope="col" class="px-5 py-3 font-medium text-center">Silme</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($permissionModules as $module)
                            <tr>
                                <td class="px-5 py-3">{{ $module }}</td>
                                @for ($i = 0; $i < 3; $i++)
                                    <td class="px-5 py-3 text-center text-ink-muted" aria-label="işaretlenebilir">
                                        <span class="inline-block w-4 h-4 rounded-[3px] border border-line align-middle"></span>
                                    </td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-6 text-[15px] text-ink-muted leading-relaxed max-w-[68ch]">
                Stok hareketinde "silme" kutusu bilinçli olarak iş görmez: hareket silinmez, iptal edilir.
                Ürün, stok, personel, tedarikçi ve sipariş üzerindeki her kritik işlem — kim, ne zaman,
                önceki değer, yeni değer — denetim kaydına yazılır. Klinik sahibi tamamını görür, personel
                yalnızca yetkili olduğu şube ve modüllerde görür.
            </p>
        </section>

        <section id="guvenlik" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Veri izolasyonu ve güvenlik</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Her klinik kendi hesabında, diğer kliniklerden tamamen ayrık çalışır. Bir kliniğin kullanıcısı
                başka bir kliniğin hiçbir kaydını hiçbir ekranda göremez; bu ayrım tek tek ekranlarda değil,
                verinin kendisine bağlı bir kural olarak uygulanır ve her sürümde ayrıca test edilir.
            </p>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Şifre sıfırlama e-posta ile tek kullanımlık ve süreli bağlantı üzerinden yapılır. Veritabanı
                her gün yedeklenir, yedekten geri dönüş prosedürü denenmiş durumdadır. Sunucu hataları
                izlenir; sistemin ayakta olup olmadığı dışarıdan da kontrol edilebilir.
            </p>
        </section>

        <section id="paket" class="py-16 border-b border-line scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Paketler ve hesap açılışı</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Paketler yalnızca sayısal sınırlarla ayrılır: kaç şube, kaç kullanıcı, ne kadar dosya alanı.
                Modüller pakete göre kısıtlanmaz — en küçük pakette de transfer, satın alma, sayım ve
                raporların tamamı açıktır.
            </p>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Sistemde ödeme alınmaz. Paket yükseltme ya da düşürme talebinizi panelinizden gönderirsiniz;
                biz ödemeyi sistem dışında teyit edip talebi elle onaylarız, onayla birlikte yeni sınırlar
                hemen geçerli olur. Hesabınız da kendiliğinden açılmaz: talebinizi aldıktan sonra kliniğinizi
                ve yönetici hesabınızı biz oluştururuz.
            </p>
        </section>

        <section id="kapsam" class="py-16 scroll-mt-20">
            <h2 class="text-[26px] leading-tight tracking-[-0.02em] font-semibold">Kapsam dışı bıraktıklarımız</h2>
            <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">
                Sistemin ne olmadığını bilmek, ne olduğunu bilmek kadar önemli. Aşağıdakiler eksik değil,
                bilinçli tercih:
            </p>

            <ul class="mt-8 divide-y divide-line border-y border-line">
                @foreach ([
                    ['Hasta, randevu, tedavi planı', 'Klinik yönetimi modülleri kapsam dışı. Ürün net bir stok yönetim sistemi olarak kalıyor.'],
                    ['Ödeme ve e-fatura entegrasyonu', 'Sanal pos, banka entegrasyonu ya da e-fatura yok. Paket işlemleri talep ve manuel onayla yürür.'],
                    ['Muhasebe programı yerine geçmez', 'Alış fiyatı, maliyet ve tedarikçi harcaması raporlanır; resmî muhasebe kayıtları sizin mali müşavirinizde kalır.'],
                ] as [$title, $text])
                    <li class="py-6 sm:grid sm:grid-cols-12 sm:gap-8">
                        <h3 class="sm:col-span-4 text-[16px] font-medium">{{ $title }}</h3>
                        <p class="sm:col-span-8 mt-1.5 sm:mt-0 text-[15px] text-ink-muted leading-relaxed max-w-[66ch]">{{ $text }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="bg-panel-900 text-white">
        <div class="mx-auto max-w-[1120px] px-5 sm:px-6 py-16 lg:flex lg:items-center lg:gap-12">
            <div class="lg:flex-1">
                <h2 class="text-[24px] sm:text-[28px] leading-tight tracking-[-0.02em] font-semibold max-w-[26ch]">
                    Kliniğinizde nasıl kurulacağını konuşalım
                </h2>
                <p class="mt-4 text-[16px] text-white/60 leading-relaxed max-w-[58ch]">
                    Şube ve depo sayınızı, bugün stoğu nasıl tuttuğunuzu yazın; kurulumun sizde nasıl
                    görüneceğini birlikte çıkaralım.
                </p>
            </div>
            <a href="{{ route('marketing.contact') }}" class="mt-8 lg:mt-0 inline-block shrink-0 bg-brand-500 text-white rounded-md px-6 py-3 text-[15px] font-medium hover:bg-brand-400 transition-colors">
                Talep gönder
            </a>
        </div>
    </section>
</div>
