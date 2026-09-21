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
            'modules' => [
                ['Stok giriş/çıkış', 'Tüm stok değişiklikleri tek bir servisten geçer: negatif stok oluşmaz, kayıt silinmez; hatalı hareket iptal edilerek ters kayıtla düzeltilir.'],
                ['Lot, SKT ve seri numarası', 'Her lotun kendi son kullanma tarihi ve alış fiyatı vardır. İmplant gibi ürünler birim birim seri numarasıyla izlenir.'],
                ['Çoklu depo', 'Bir şube altında merkezi depo, cerrahi depo, ortodonti deposu ayrı ayrı tutulur; şube görünümü bunların toplamıdır.'],
                ['Şubeler/depolar arası transfer', 'Talep → onay → hazırlık → gönderim → teslim. Stok gönderim anında kaynaktan düşer, teslimde hedefe eklenir.'],
                ['Satın alma ve sipariş', 'Talep, onay, sipariş ve kısmi teslimat. Teslim alınan miktar lot/SKT/fatura bilgisiyle stoğa girer, kalanı açık sipariş olarak durur.'],
                ['Stok sayımı', 'Sayım oturumu sistem miktarını gösterir, sayılan miktar girilir, onaylanan fark iz bırakan bir düzeltme hareketine dönüşür.'],
                ['İade yönetimi', 'Hasarlı veya yanlış gelen ürünün tedarikçiye iadesi, talepten tamamlanmaya kadar ayrı bir hareket türü olarak izlenir.'],
                ['Raporlar ve tahmin', 'Tarih, şube, depo, kategori ve tedarikçi filtreli raporlar; Excel/PDF çıktısı; geçmiş tüketimden istatistiksel stok tükenme tahmini.'],
                ['Barkod ve mobil', 'Barkod/QR ile hızlı giriş-çıkış; telefonda kamerayla ya da USB/Bluetooth okuyucuyla. Uygulama telefona kurulabilir (PWA).'],
                ['Yetki ve denetim', 'Personele modül bazında okuma/yazma/silme kutucukları ve şube kapsamı verilir; kritik her işlem denetim kaydına yazılır.'],
            ],
        ];
    }
};
?>

<div>
    <section class="max-w-5xl mx-auto px-6 pt-16 pb-10">
        <h1 class="text-[28px] sm:text-[34px] font-medium tracking-tight max-w-2xl leading-tight">
            Bir diş kliniğinin stok işini baştan sona kapsar
        </h1>
        <p class="mt-4 text-[16px] text-ink-muted leading-relaxed max-w-2xl">
            Dental ERP bir stok yönetim sistemidir: ürün kartından lot takibine, transferden satın almaya,
            sayımdan iadeye kadar malzemenin kliniğe girişini ve çıkışını yönetir.
            Hasta, randevu ve tedavi planı gibi klinik modülleri bilinçli olarak kapsam dışıdır.
        </p>
    </section>

    <section class="max-w-5xl mx-auto px-6 pb-16">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-10 gap-y-8 border-t border-line pt-10">
            @foreach ($modules as [$title, $text])
                <article>
                    <h2 class="text-[15px] font-medium">{{ $title }}</h2>
                    <p class="mt-1.5 text-[14px] text-ink-muted leading-relaxed">{{ $text }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="border-y border-line bg-surface">
        <div class="max-w-5xl mx-auto px-6 py-16">
            <h2 class="text-[22px] font-medium tracking-tight">Her klinik kendi verisinde</h2>
            <p class="mt-3 text-[15px] text-ink-muted leading-relaxed max-w-2xl">
                Sistem çok kiracılıdır: her klinik/hastane kendi hesabında, diğer kliniklerden tamamen ayrık çalışır.
                Bir kliniğin kullanıcısı başka bir kliniğin hiçbir kaydını göremez. Kliniğin kendi içinde
                yetkilendirmeyi klinik sahibi yapar; personel yalnızca kendisine işaretlenen modül ve şubelerde işlem yapar.
            </p>
            <p class="mt-4 text-[15px] text-ink-muted leading-relaxed max-w-2xl">
                Hesaplar kendiliğinden açılmaz. Talebinizi aldıktan sonra görüşür, kliniğinizi ve yöneticinin
                hesabını biz oluştururuz; paket değişiklikleri de aynı şekilde talep ve onayla yürür.
            </p>
            <a href="{{ route('marketing.contact') }}" class="mt-7 inline-block bg-panel-900 text-white rounded-md px-5 py-2.5 text-[14px] font-medium hover:bg-panel-800 transition-colors">
                Talep Gönder
            </a>
        </div>
    </section>
</div>
