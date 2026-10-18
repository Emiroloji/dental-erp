<?php

return [
    'slug' => 'uts-alma-bildirimi-nasil-yapilir',
    'order' => 2,
    'updated' => '2026-10-18',
    'title' => 'ÜTS\'de alma bildirimi nasıl yapılır',
    'description' => 'Tedarikçinin gönderdiği implant ve tıbbi cihazları ÜTS\'de kabul etmek: kabul bekleyenler, bildirim koduyla alma, eksik adet ve iptal olmaması.',
    'summary' => 'Tedarikçi bir ürünü kliniğinize ÜTS\'de verme bildirimiyle gönderir; ürün sizin olmadan önce sizin alma bildirimi yapmanız gerekir. Alma bildiriminin iptali olmadığı için doğru satırı kabul etmek önemlidir.',
    'sections' => [
        [
            'heading' => 'Alma bildirimi ne işe yarar',
            'paragraphs' => [
                'ÜTS\'de tekil ürünler, kurumlar arasında verme ve alma bildirimleriyle el değiştirir. Tedarikçi verme bildirimini yaptığında ürün kliniğinize yola çıkmış sayılır; siz alma bildirimini yaptığınızda ürünün sahipliği kliniğinize geçer.',
            ],
        ],
        [
            'heading' => 'Kabul bekleyenleri bulmak',
            'paragraphs' => [
                'Kuruma yapılmış ve henüz kabul edilmemiş verme bildirimleri "Kabul Edilecek Tekil Ürün Sorgula" servisiyle listelenir. Liste gönderen kurum koduna, fatura ya da irsaliye numarasına, ürün numarasına ve bildirim koduna göre süzülebilir ve onar kayıtlık sayfalar halinde gelir.',
                'Her satırda gönderen kurum ve unvanı, ürün numarası, lot ya da seri numarası, adet, belge numarası, bildirim kodu ve bildirim zamanı bulunur. Satırı kutudaki ürünle ve faturayla karşılaştırmak için yeterli bilgi budur.',
            ],
        ],
        [
            'heading' => 'Bildirim koduyla kabul edin',
            'paragraphs' => [
                'Alma bildirimi iki yolla yapılabilir: tedarikçinin verme bildiriminin koduyla ya da ürün numarası, lot ve seri numarasıyla. Aynı ürün ve lot bilgisine uyan birden fazla verme bildirimi varsa ÜTS "birden fazla bildirim bulundu" hatası verir ve hangisinin kastedildiğini sorar.',
                'Bu yüzden kabul bekleyenler listesinden seçip doğrudan bildirim koduyla kabul etmek, hem hızlı hem de yanlış satırı kabul etme riskini ortadan kaldıran yoldur.',
            ],
        ],
        [
            'heading' => 'Eksik gelen ürün',
            'paragraphs' => [
                'Lot takipli bir üründen tedarikçinin bildirdiğinden az geldiyse, alma bildiriminde gerçekten aldığınız adedi yazarsınız. Adet yazılmazsa verme bildirimindeki adedin tamamı alınmış sayılır. Seri numarasıyla tek tek takip edilen ürünlerde adet verilmez; her seri ayrı satırdır.',
            ],
        ],
        [
            'heading' => 'Alma bildiriminin iptali yoktur',
            'paragraphs' => [
                'ÜTS\'de pek çok bildirimin iptali vardır, ama alma ve geri çekme alma bildirimlerinin karşılığında iptal bildirimi yoktur. Yanlış kabul edilen ürün ancak gönderene bir verme (iade) bildirimiyle geri verilebilir.',
                'Kabul etmeden önce ürünü, lotu ya da seriyi ve adedi kutuyla karşılaştırın. Gelmeyen ya da yanlış gelen ürünü kabul etmek yerine "almak istemiyorum" olarak işaretleyin.',
            ],
        ],
    ],
    'program' => 'ÜTS → Kabul Bekleyenler ekranında satırlar ürün kartınız, lotunuz ve teslim kaydınızla eşleştirilmiş gelir. Tek tek ya da seçerek toplu kabul edersiniz; her kabul "bu işlem geri alınamaz" uyarılı bir onaydan geçer ve bildirim koduyla gönderilir. Lot takipli üründe kabul edilen adedi değiştirebilirsiniz. Kabul bekleyenler saatte bir taranır, yeni ürün gelince bildirim düşer.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.1.6, 3.2 ve 3.4.10', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
