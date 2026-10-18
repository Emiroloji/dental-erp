<?php

return [
    'slug' => 'uts-envanter-demirbas-bildirimi',
    'order' => 13,
    'updated' => '2026-10-19',
    'title' => 'ÜTS envanter (demirbaş) bildirimi',
    'description' => 'Sağlık kuruluşunun envantere aldığı tıbbi cihazlar için ÜTS envanter bildirimi: zorunlu alanlar, kalibrasyon ve bakım tarihleri.',
    'summary' => 'Sağlık hizmet sunucuları, envantere almak istedikleri ürünler için ÜTS\'ye envanter bildirimi yapar. Bildirim, cihazın üreticisini, seri numarasını ve envantere alındığı tarihi kaydeder.',
    'sections' => [
        [
            'heading' => 'Hangi bilgiler gider',
            'items' => [
                'Üretici ya da ithalatçı firmanın ÜTS kurum numarası (zorunlu).',
                'Ürün numarası (zorunlu).',
                'Seri numarası (zorunlu); varsa lot numarası.',
                'Envanter tarihi (zorunlu). Bugünden sonraki bir tarih verilemez.',
                'İsteğe bağlı: son kalibrasyon tarihi ve son bakım tarihi.',
            ],
        ],
        [
            'heading' => 'Her ürün envanter ürünü değildir',
            'paragraphs' => [
                'Envanter bildirimi yalnızca ÜTS\'de envanter ürünü olarak tanımlı ürünler için yapılabilir; diğerlerinde ÜTS "Ürün envanter ürünü değildir" cevabını verir. Ayrıca üretici ya da ithalatçı kurum ÜTS\'de kayıtlı ve aktif olmalıdır.',
            ],
        ],
        [
            'heading' => 'İptal',
            'paragraphs' => [
                'Envanter bildiriminin iptali vardır; yanlış yapılan bildirim, bildirim koduyla iptal edilir.',
            ],
        ],
    ],
    'program' => 'Envanter bildirimi şu an Dental ERP\'den gönderilmez, ÜTS portalından yapılır. Programdaki stok, sarf ve implant gibi kullanılıp biten malzemeye odaklanır.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.1.23, 3.2 ve EK-A (UTS-H242, H243, H244, H245)', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
