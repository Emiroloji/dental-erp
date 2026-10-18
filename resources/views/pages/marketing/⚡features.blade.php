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
        // Her bölüm: [çapa, başlık, giriş paragrafları, maddeler, örnek ekran (components/marketing/screens) ya da null].
        return [
            'sections' => [
                ['hiyerarsi', 'Kurgu: platform, klinik, şube, depo', [
                    'Sistem beş seviyeden oluşur ve her seviye bir üstündekine bağlıdır. Stok fiilen en alttaki depoda durur; yukarıdaki her seviye altındakilerin toplamını görür.',
                ], [
                    ['Platform', 'Yazılımın sahibi. Klinik hesaplarını açar, paketleri yönetir. Hiçbir kliniğin stok ya da finans verisine girmez.'],
                    ['Klinik (hastane)', 'Sizin hesabınız. Klinik sahibi kendi hesabında sınırsız yetkilidir: şube açar, personel ekler, yetki verir.'],
                    ['Şube', 'Kadıköy, Beşiktaş, Ortodonti merkezi… Her şubenin stoğu ayrı tutulur, klinik sahibi hepsini birlikte görür.'],
                    ['Depo', 'Şubenin içindeki stok alanları: merkezi depo, cerrahi depo, klinik dolabı. Tek şubeli klinikte sistem bir varsayılan depo açar, katman görünmez.'],
                    ['Personel', 'Bir veya birden çok şubeye atanır; yalnızca kendisine işaretlenmiş modüllerde işlem yapar.'],
                ], null],

                ['stok', 'Stok ve katalog', [
                    'Stok yalnızca tek bir yoldan değişir. Giriş, çıkış, transfer, sayım düzeltmesi, iade: hepsi aynı kapıdan geçer. Bu yüzden rakam hiçbir ekranda elle oynatılamaz ve her değişikliğin bir sahibi, nedeni, tarihi vardır.',
                ], [
                    ['Negatif stok oluşmaz', 'Depoda 12 adet varken 15 adet çıkış yapılamaz; işlem baştan reddedilir.'],
                    ['Kayıt silinmez, iptal edilir', 'Yanlış hareket ters kayıt oluşturan bir iptalle geri alınır. Hem yanlış hareket hem iptali geçmişte durur.'],
                    ['Parti seçmezseniz FEFO', 'Çıkışta lot seçebilirsiniz; seçmezseniz son kullanma tarihi önce dolacak parti düşer. Süresi geçmiş parti ayrıca işaretlenir.'],
                    ['Mevcut stok ve kullanım ayrı', '100 adet girip 20 kullandıysanız mevcut stok 80, toplam kullanım 20 olarak ayrı raporlanır. Transfer ve iade kullanıma karışmaz.'],
                    ['Ürün kartı', 'Ad, kod, barkod, kategori, tedarikçi, ürün tipi, ana birim, alış fiyatı, en az ve en çok stok, uyarı eşiği. Ürün silinmez, pasife alınır.'],
                    ['Birim çevrimi', '"1 kutu = 50 adet" tanımlarsınız; kullanıcı 3 kutu girer, sistem 150 adet olarak kaydeder.'],
                ], 'lots'],

                ['satinalma', 'Satın alma ve sipariş', [
                    'Satın alma talebi açmak stoğa dokunmaz; bu yüzden stok yetkisi olmayan bir personel de talep açabilir. Siparişi onaylamak klinik sahibine aittir. Teslim alma anında stok girişi kendiliğinden oluşur.',
                ], [
                    ['Kısmi teslimat', '10 kutu sipariş ettiniz, 8 kutu geldi: 8 kutu lot ve son kullanma tarihiyle stoğa girer, 2 kutu açık siparişte bekler.'],
                    ['Fatura ve irsaliye', 'Teslim alırken fatura/irsaliye numarası ve belgesi kaydedilir; stok girişi hem siparişe hem tedarikçiye bağlı kalır.'],
                    ['Tedarikçi geçmişi', 'Her tedarikçinin siparişleri, teslimatları ve toplam harcaması kendi kartından görünür.'],
                ], 'purchase'],

                ['efatura', 'Gelen e-fatura', [
                    'Tedarikçinin kliniğe kestiği e-fatura entegratörünüzden yarım saatte bir kendiliğinden gelir. Kimse faturayı elle girmez; program faturanın kendisini okur ve kliniğin kayıtlarıyla eşleştirir.',
                ], [
                    ['Kendiliğinden gelir', 'Entegratörünüzün anahtarını bir kez girersiniz; yeni faturalar yarım saatte bir çekilir, istediğiniz an "Şimdi çek" ile de alınır. İlk bağlı entegratör Nilvera; diğerleri aynı yapıyla eklenir.'],
                    ['Kalemleriyle okunur', 'Her kalemin adı, miktarı, birim fiyatı ve KDV oranı görünür. İlaç ve tıbbi cihaz faturasında GTIN, lot, seri numarası ve tarihler de okunur.'],
                    ['Eşleşir', 'Gönderen tedarikçi kartınızla, fatura numarası teslim alma kaydınızla, kalemler ürün kartlarınızla eşleşir. Tedarikçi kayıtlı değilse tek tıkla eklenir.'],
                    ['Stoğa girer', 'Siparişsiz gelen malzemede faturadan "Stoğa al" dersiniz: ürün, adet, lot, son kullanma tarihi ve seri numaraları faturadan dolar, kutularla karşılaştırıp onaylarsınız. Maliyet faturadaki fiyattır; yanlış girişi geri alabilirsiniz.'],
                    ['ÜTS ile buluşur', 'Aynı fatura numarasıyla ÜTS\'de kabul bekleyen ürünler faturanın altında listelenir; kabul ekranına oradan geçersiniz.'],
                    ['Finans\'a aktarılır', 'Fatura tek tıkla tedarikçiye ödenecek borç olarak Finans\'a geçer; vadesi yaklaşınca hatırlatılır. Aynı fatura iki kez aktarılamaz.'],
                ], 'einvoice'],

                ['transfer', 'Şubeler arası transfer', [
                    'Bir şube başka bir şubeden ürün ister. Talep, onay, hazırlık, gönderim ve teslim adımlarından geçer; aynı akış bir şubenin iki deposu arasında da çalışır.',
                ], [
                    ['Stok nerede değişir', 'Gönderildi adımında kaynak depodan düşer, teslim alındığında hedef depoya eklenir. Arada kalan miktar yolda sayılır.'],
                    ['İptal', 'Gönderilmeden iptal edilen talep stoğa dokunmaz; gönderildikten sonra iptal edilirse düşülen miktar kaynağa geri eklenir.'],
                ], 'transfer'],

                ['sayim', 'Sayım ve iade', [
                    'Sayım bir oturum olarak başlar: depo seçilir, sistem beklenen miktarı gösterir, sayan kişi gerçek miktarı girer. Fark için neden seçilir ve klinik sahibi onaylar.',
                ], [
                    ['İz bırakan düzeltme', 'Sistemde 100, sayımda 97 ise −3\'lük düzeltme hareketi oluşur; kimin onayladığı ve nedeni geçmişte görünür.'],
                    ['Tedarikçiye iade', 'Hasarlı, hatalı ya da yanlış gelen ürün; parti, miktar, neden, ilgili sipariş ve alacak dekontuyla izlenir. Talep edildi, onaylandı, kargoya verildi, tedarikçi onayladı, tamamlandı.'],
                    ['Stok ne zaman düşer', 'İade edilen miktar kargoya verildiği anda partiden düşer ve kullanım sayılmaz.'],
                ], 'count'],

                ['uyari', 'Uyarılar ve bildirimler', [
                    'Her ürün yeşil, sarı ya da kırmızıdır. Eşiği ürünün kendisi taşır: miktar bazlı, son kullanma tarihi bazlı ya da ikisi birden. Eşik girmezseniz klinik geneli varsayılan uygulanır.',
                ], [
                    ['Sarı', 'Kalan miktar ya da son kullanma tarihine kalan gün, ürünün sarı eşiğine indi.'],
                    ['Kırmızı', 'Kırmızı eşiğe inildi, stok tükendi ya da depoda süresi geçmiş bir parti duruyor.'],
                    ['Kime gider', 'İlgili şube ve depoda stok yetkisi olan personele ve klinik sahibine. Talep, transfer, satın alma onayı, sayım, iade ve finans vadeleri de bildirim üretir.'],
                ], 'alert'],

                ['ilac', 'İlaç, medikal ürün ve ÜTS', [
                    'İlaç ve bazı medikal ürünler kayıt ister. Ürün kartında GTIN, ÜTS numarası, ruhsat numarası, üretici ve saklama koşulu alanları vardır.',
                    'ÜTS takipli ürünün kliniğe girişinden hastaya takılmasına, iadesinden imhasına kadar her adımı ÜTS\'ye programdan bildirilir. ÜTS portalına ayrıca girip aynı bilgiyi ikinci kez yazmazsınız.',
                ], [
                    ['Seri numarası', 'İmplant gibi ürünlerde her birim ayrı seri numarasıyla girer ve çıkar; hangi serinin nerede olduğu izlenir.'],
                    ['Soğuk zincir', 'Girişte ölçülen sıcaklık istenir; saklama aralığının dışındaki giriş engellenir ya da gerekçesiyle kabul edilir.'],
                    ['Kontrollü ürün', 'Her çıkışta açıklama zorunludur; hareketler ayrı bir kontrollü ürün defterinde listelenir.'],
                    ['Kabul bekleyenler', 'Tedarikçinin size gönderdiği ürünler ÜTS\'den saatte bir taranır, yeni gelen olunca bildirim düşer. Tek tek ya da toplu kabul edersiniz (Alma bildirimi); gelmeyen ürünü "Almak istemiyorum" ile reddedersiniz. Kabulün ÜTS\'de iptali olmadığı için her kabul onaydan geçer.'],
                    ['Hastaya kullanım', 'İmplant hastaya takılınca Stok Çıkışı\'nda hasta seçilir; Kullanım bildirimi kendiliğinden gider ve ürün hastanın kartına yazılır.'],
                    ['İade, imha, zayiat', 'Tedarikçiye iadede Verme bildirimi; son kullanma tarihi geçen ya da hasar gören üründe İmha ya da HEK/Zayiat bildirimi aynı ekrandan yapılır.'],
                    ['Gönderim geçmişi', 'Her bildirim kim, ne zaman, hangi ürün ve ÜTS\'nin cevabıyla kayıtlıdır. Gönderilemeyen tekrar gönderilir; iptali olan bildirim buradan iptal edilir.'],
                    ['Lot ve seri doğrulama', 'Kutudaki kare kod okutulunca lot ve seri numarası ÜTS\'de sorgulanır ve kendi kaydınızla karşılaştırılır.'],
                ], 'medical'],

                ['hasta', 'Hasta ve implant kartı', [
                    'ÜTS, hastaya takılan ürün için hastanın adını ve kimlik numarasını ister. Program bunun için gereken kadarını tutar; bir hasta dosyası ya da tedavi kaydı değildir.',
                ], [
                    ['En az veri', 'Ad, soyad, kimlik türü (T.C., yabancı kimlik, pasaport ya da kimliği belirsiz) ve isteğe bağlı doğum yılı. Telefon, adres, tedavi bilgisi tutulmaz.'],
                    ['Kimlik korunur', 'Kimlik numarası şifreli saklanır; listelerde maskeli görünür (100******46). Numarayla arama yalnızca numaranın tamamıyla yapılır.'],
                    ['Ayrı yetki', 'Hastaları görmek ayrı bir yetkidir; stok personeline kendiliğinden açılmaz.'],
                    ['İmplant kartı', 'Hastanın kartında ona kullanılan her ürün; tarih, lot, seri numarası, şube ve ÜTS durumuyla görünür. "Bu implant kime takıldı" sorusunun cevabı da seri numarasından bulunur.'],
                    ['Hastadan çıkarma', 'Tutmayan ya da değiştirilen implant karttan "vücuttan çıkar" ile işaretlenir; gerekçe ve tarihle ÜTS\'ye hastanın vücudundan çıkarma bildirimi gider.'],
                ], 'patient'],

                ['finans', 'Finans: ön muhasebe', [
                    'Finans bölümü kliniğin ön muhasebesidir: paranın nerede durduğunu, nereden gelip nereye gittiğini ve önümüzdeki günlerde ne ödeyip ne tahsil edeceğinizi gösterir.',
                    'Stoktan ve satın almadan bilinçli olarak ayrıdır: malzemenin alındığı gün ile parasının ödendiği gün çoğu zaman aynı değildir. Tedarikçinin e-faturası ise istediğinizde tek tıkla ödenecek borç olarak aktarılır.',
                ], [
                    ['Hesaplar', 'Kasa, banka ve POS hesapları açılış bakiyesiyle açılır. Bakiye hareketlerden hesaplanır, bu yüzden ekranlar birbirini tutar. Kullanılmayan hesap pasife alınır.'],
                    ['Gelir ve gider', 'Kategoriyle ve isteğe bağlı şube etiketiyle kaydedilir. Hesaplar arası transfer gelir ya da gider sayılmaz; POS\'tan bankaya geçişteki komisyon ayrı gider olur.'],
                    ['Cariler', 'Sigorta kurumu, depo, laboratuvar gibi kurum ve firmalar. Cari bakiyesi açık alacaklar eksi açık borçlardır. Hasta cari açılmaz.'],
                    ['Ödemeler ve tahsilatlar', 'Vadeli borç ve alacaklar. Kısmi ödeme yapılabilir; durum Açık, Kısmi, Ödendi olarak kendiliğinden güncellenir. Vadesi yaklaşan ya da geçen kayıt için bildirim gelir.'],
                    ['Tekrarlayan kalemler', 'Kira, maaş, abonelik haftalık, aylık ya da yıllık tanımlanır; önümüzdeki dönemin ödemesi listeye kendiliğinden düşer.'],
                    ['Mutabakat', 'Banka ekstresindeki bakiye programdakiyle karşılaştırılır; fark düzeltme hareketiyle kapatılır ya da yalnızca not edilir.'],
                    ['Raporlar', 'Finans Özeti (toplam nakit, bu ayın geliri ve gideri, 90 günlük nakit projeksiyonu), Gelir-Gider, Nakit Akışı, Yaşlandırma ve hesap ekstresi; Excel ve PDF.'],
                    ['İptal ve yetki', 'Yanlış hareket silinmez, iptal edilir; kapanmış ayın raporu değişmez. Finans yetkisi yalnızca "tüm şubeler" kapsamıyla verilir.'],
                ], 'finance'],

                ['rapor', 'Raporlar, tahmin ve asistan', [
                    'Raporlar tarih aralığı, şube, depo, kategori ve tedarikçi süzgeçleriyle daraltılır; Excel ya da PDF olarak indirilir.',
                ], [
                    ['Stok raporları', 'Stok durumu, depo bazında stok, hareket geçmişi, kullanım, satın alma ve maliyet, kontrollü ürün defteri.'],
                    ['Ana sayfa', 'Toplam ürün ve stok, sarı ve kırmızı uyarılar, yaklaşan ve geçmiş son kullanma tarihleri, bekleyen talepler, günlük giriş-çıkış.'],
                    ['Tükenme tahmini', 'Geçmiş tüketimden bir ürünün yaklaşık kaç gün sonra biteceği hesaplanır. Tahmin kararı sizin yerinize vermez.'],
                    ['Asistan', '"Geçen ay Kadıköy\'de en çok hangi ürün kullanıldı" gibi soruları düz Türkçe yazar, cevabını rapor olarak alırsınız.'],
                ], 'report'],

                ['barkod', 'Barkod, etiket ve telefon', [
                    'Depoda en çok yapılan iş giriş ve çıkış olduğu için bu ikisi hızlı olmalı. Barkodu telefonun kamerasıyla ya da USB/Bluetooth el okuyucusuyla okutursunuz; ürün ve parti doğrudan ekrana gelir.',
                ], [
                    ['Etiket', 'Ürün ve parti için yazdırılabilir etiket üretilir; kendi kutularınızı etiketleyip okutabilirsiniz.'],
                    ['Telefon', 'Program tarayıcıda çalışır, ana ekrana eklenip uygulama gibi açılır; ayrı bir mobil uygulama indirmeniz gerekmez.'],
                ], null],

                ['yetki', 'Yetkiler ve denetim kaydı', [
                    'Sabit rol yoktur. Klinik sahibi her personel için modül modül okuma, yazma ve silme kutucuklarını işaretler; kapsamı da seçer: yalnızca kendi şubesi, seçili şubeler ya da tüm şubeler.',
                ], [
                    ['Kombinasyonlar', '"Muhasebeci yalnızca finans raporlarını görsün", "depo görevlisi stok girsin ama silmesin" gibi ayarlar kurulur.'],
                    ['Denetim kaydı', 'Ürün, stok, personel, tedarikçi, sipariş ve finans üzerindeki her kritik işlem; kim, ne zaman, önceki ve yeni değeriyle yazılır.'],
                ], 'permissions'],

                ['guvenlik', 'Veri izolasyonu ve güvenlik', [
                    'Her klinik kendi hesabında, diğerlerinden tamamen ayrı çalışır. Bir kliniğin kullanıcısı başka bir kliniğin hiçbir kaydını hiçbir ekranda göremez; bu ayrım verinin kendisine bağlı bir kural olarak uygulanır ve her sürümde test edilir.',
                    'Şifre sıfırlama tek kullanımlık ve süreli bağlantıyla yapılır. Veritabanı her gün yedeklenir, yedekten geri dönüş denenmiş durumdadır. Sunucu hataları izlenir.',
                ], [], null],

                ['paket', 'Paketler ve hesap açılışı', [
                    'Paketler yalnızca sayısal sınırlarla ayrılır: kaç şube, kaç kullanıcı, ne kadar dosya alanı. Modüller pakete göre kısıtlanmaz; en küçük pakette de transfer, satın alma, sayım, finans ve raporların tamamı açıktır.',
                    'Sistemde ödeme alınmaz. Paket değişikliği talebinizi panelinizden gönderirsiniz, ödemeyi sistem dışında teyit edip onaylarız. Hesabınız da kendiliğinden açılmaz: talebinizi aldıktan sonra kliniğinizi ve yönetici hesabınızı biz oluştururuz.',
                ], [], null],

                ['kapsam', 'Kapsam dışı bıraktıklarımız', [
                    'Sistemin ne olmadığını bilmek, ne olduğunu bilmek kadar önemli. Aşağıdakiler eksik değil, bilinçli tercih.',
                ], [
                    ['Randevu, tedavi planı, hasta dosyası', 'Klinik yönetimi modülleri kapsam dışı. Hasta kaydı yalnızca ÜTS\'nin istediği ad, soyad ve kimlikten ibarettir; program kliniğin malzemesine ve parasına odaklanır.'],
                    ['Resmî muhasebe', 'Finans ön muhasebedir: KDV, döviz, çek/senet ve resmî defter tutmaz. Yıl sonunda mali müşavirinize Excel dökümü verirsiniz.'],
                    ['Banka, ödeme ve giden fatura', 'Sanal pos, banka bağlantısı ve kliniğin kestiği e-fatura/e-arşiv yok. Tedarikçiden gelen e-fatura alınır; banka hareketleri elle girilir, mutabakatla doğrulanır.'],
                ], null],
            ],
        ];
    }
};
?>

<div x-data="{ active: 'hiyerarsi' }">
    <section class="bg-panel-900 text-white">
        <div class="mx-auto max-w-[1180px] px-5 sm:px-6 pt-14 pb-14 sm:pt-20 sm:pb-16">
            <h1 class="text-[34px] sm:text-[46px] leading-[1.05] tracking-[-0.035em] font-semibold max-w-[20ch]">
                Bir diş kliniğinin malzeme ve para işini baştan sona kapsar
            </h1>
            <p class="mt-6 text-[17px] text-white/65 leading-relaxed max-w-[64ch]">
                Malzemenin tedarikçiden kliniğe girişini, ÜTS bildirimlerini, depolar arasında dolaşımını, hastada
                kullanılmasını, faturasının gelip ödenmesini ve kasanın gününü aynı programda tutarsınız. Aşağıda sistemin bugün
                gerçekten yaptığı işler var; yapmadıklarını da sonunda açıkça yazdık.
            </p>
        </div>
    </section>

    {{-- Telefonda: üstte yatay kayan bölüm şeridi. --}}
    <nav aria-label="Sayfa içeriği" class="lg:hidden sticky top-[60px] z-20 bg-surface/95 backdrop-blur border-b border-line">
        <ul class="flex gap-1 overflow-x-auto px-4 py-2 text-[13px] whitespace-nowrap">
            @foreach ($sections as $i => [$id, $title])
                <li>
                    <a href="#{{ $id }}" @class(['block rounded-full px-3 py-1.5 transition-colors', 'bg-panel-900 text-white' => $i === 0, 'text-ink-muted hover:text-ink' => $i > 0])
                        x-bind:class="{ 'bg-panel-900 text-white': active === '{{ $id }}', 'text-ink-muted hover:text-ink': active !== '{{ $id }}' }"
                        x-bind:aria-current="active === '{{ $id }}' && 'true'">{{ \Illuminate\Support\Str::before($title, ':') }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="mx-auto max-w-[1180px] px-5 sm:px-6 lg:grid lg:grid-cols-12 lg:gap-12">
        {{-- Geniş ekranda: kaydırdıkça hangi bölümde olunduğunu gösteren içindekiler. --}}
        <nav aria-label="Sayfa içeriği" class="hidden lg:block lg:col-span-3">
            <ul class="sticky top-[84px] py-14 space-y-0.5 text-[14px] border-l border-line">
                @foreach ($sections as $i => [$id, $title])
                    <li>
                        <a href="#{{ $id }}" @class(['block -ml-px pl-4 py-1.5 border-l-2 transition-colors', 'border-brand-500 text-ink font-medium' => $i === 0, 'border-transparent text-ink-muted hover:text-ink' => $i > 0])
                            x-bind:class="{ 'border-brand-500 text-ink font-medium': active === '{{ $id }}', 'border-transparent text-ink-muted hover:text-ink': active !== '{{ $id }}' }"
                            x-bind:aria-current="active === '{{ $id }}' && 'true'">{{ \Illuminate\Support\Str::before($title, ':') }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="lg:col-span-9">
            @foreach ($sections as [$id, $title, $intro, $facts, $screen])
                <section id="{{ $id }}" @class(['py-14 sm:py-16 scroll-mt-28 lg:scroll-mt-20', 'border-b border-line' => ! $loop->last])
                    x-intersect.margin.-35%.0.-60%.0="active = '{{ $id }}'">
                    <h2 class="text-[26px] sm:text-[30px] leading-tight tracking-[-0.02em] font-semibold">{{ $title }}</h2>
                    @foreach ($intro as $paragraph)
                        <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-[68ch]">{{ $paragraph }}</p>
                    @endforeach

                    @if ($facts || $screen)
                        <div @class(['mt-9', 'xl:grid xl:grid-cols-12 xl:gap-10 xl:items-start' => $screen])>
                            @if ($facts)
                                <dl @class(['divide-y divide-line border-y border-line', 'xl:col-span-7' => $screen])>
                                    @foreach ($facts as [$term, $text])
                                        <div @class(['py-5', 'sm:grid sm:grid-cols-12 sm:gap-6' => ! $screen])>
                                            <dt @class(['text-[16px] font-medium', 'sm:col-span-4' => ! $screen])>{{ $term }}</dt>
                                            <dd @class(['mt-1.5 text-[15px] text-ink-muted leading-relaxed max-w-[64ch]', 'sm:col-span-8 sm:mt-0' => ! $screen])>{{ $text }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                            @if ($screen)
                                <div class="mt-8 xl:mt-0 xl:col-span-5 xl:sticky xl:top-24 max-w-[520px]">
                                    <x-marketing.window :title="\Illuminate\Support\Str::before($title, ':')">
                                        <x-dynamic-component :component="'marketing.screens.'.$screen" />
                                    </x-marketing.window>
                                    <p class="mt-2.5 text-[12px] text-ink-muted">Örnek ekran, veriler örnektir.</p>
                                </div>
                            @endif
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>

    <section class="bg-panel-900 text-white">
        <div class="mx-auto max-w-[1180px] px-5 sm:px-6 py-16 lg:flex lg:items-center lg:gap-12">
            <div class="lg:flex-1">
                <h2 class="text-[24px] sm:text-[30px] leading-tight tracking-[-0.02em] font-semibold max-w-[26ch]">
                    Kliniğinizde nasıl kurulacağını konuşalım
                </h2>
                <p class="mt-4 text-[16px] text-white/60 leading-relaxed max-w-[58ch]">
                    Şube ve depo sayınızı, bugün stoğu ve kasayı nasıl tuttuğunuzu yazın; kurulumun sizde nasıl
                    görüneceğini birlikte çıkaralım.
                </p>
            </div>
            <a href="{{ route('marketing.contact') }}" class="mt-8 lg:mt-0 inline-block shrink-0 bg-brand-500 text-white rounded-md px-6 py-3 text-[15px] font-medium hover:bg-brand-400 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">
                Talep gönder
            </a>
        </div>
    </section>
</div>
