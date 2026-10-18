<?php

return [
    'slug' => 'uts-tedarikciye-iade-verme-bildirimi',
    'order' => 5,
    'updated' => '2026-10-18',
    'title' => 'Tedarikçiye iade: ÜTS verme bildirimi',
    'description' => 'Hatalı ya da yanlış gelen ürünü tedarikçiye iade ederken ÜTS verme bildirimi: kurum numarası, belge numarası, seri ve lot, iptal.',
    'summary' => 'Kliniğinizdeki bir tekil ürünü başka bir kuruma verdiğinizde, tedarikçiye iade dahil, ÜTS\'ye verme bildirimi yaparsınız. Tedarikçi alma bildirimi yapınca ürün onun üzerine geçer.',
    'sections' => [
        [
            'heading' => 'Ne zaman yapılır',
            'paragraphs' => [
                'Kurum, sahip olduğu tekil ürünü başka bir kuruma verdiğinde verme bildirimi yapar. Satış, hibe ve iade bunun örnekleridir. Diş kliniği için en sık durum, hatalı, hasarlı ya da yanlış gelen ürünün tedarikçiye iadesidir.',
            ],
        ],
        [
            'heading' => 'Hangi bilgiler gerekir',
            'items' => [
                'Ürün numarası; seri takipli üründe seri numarası, lot takipli üründe lot numarası ve adet.',
                'Ürünü alacak kurumun ÜTS\'de tanımlı kurum numarası (zorunlu).',
                'Fatura ya da irsaliye numarası (zorunlu); iadede iade faturası ya da irsaliyesi.',
                'Gerçek işlem tarihi; boş bırakılırsa bildirimin yapıldığı gün alınır.',
            ],
        ],
        [
            'heading' => 'Tedarikçinin kurum numarası',
            'paragraphs' => [
                'Kurum numarası, tedarikçinin ÜTS\'deki numarasıdır; vergi numarası değildir. Tedarikçi size ürün gönderirken yaptığı verme bildiriminde bu numara "gönderen kurum" olarak görünür, bu yüzden kabul bekleyenler listesinden öğrenilebilir.',
            ],
        ],
        [
            'heading' => 'Tedarikçi iadeyi kabul etmezse',
            'paragraphs' => [
                'Verme bildiriminin iptali vardır. Tedarikçi iadeyi reddeder ve ürün kliniğe geri dönerse, yaptığınız verme bildirimini bildirim koduyla iptal edersiniz.',
            ],
        ],
    ],
    'program' => 'İade kargoya verildikten sonra iade detayındaki ÜTS bölümünden tedarikçinin kurum numarası ve iade belge numarasıyla bildirilir; seri takipli üründe her seri ayrı gider. Tedarikçinin kurum numarası kartında tutulur ve ÜTS\'den o tedarikçinin ürünü kabul edildikçe kendiliğinden dolar. Tedarikçi reddederse önce ÜTS\'deki bildirim iptal edilir, sonra ürün stoğa döner.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.1.4 ve 3.2', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
