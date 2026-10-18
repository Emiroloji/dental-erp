<?php

return [
    'slug' => 'uts-hastanin-vucudundan-cikarma-bildirimi',
    'order' => 8,
    'updated' => '2026-10-19',
    'title' => 'İmplant çıkarılınca ÜTS\'de hastanın vücudundan çıkarma bildirimi',
    'description' => 'Başarısız, geri çağrılan ya da ömrünü dolduran implant hastadan çıkarılınca yapılan ÜTS bildirimi: gerekçeler, hasta bilgisi, tarih.',
    'summary' => 'Hastaya takılmış bir tıbbi cihaz geri alındığında ÜTS\'ye hastanın vücudundan çıkarma bildirimi yapılır. Diş kliniğinde bu çoğunlukla tutmayan ya da değiştirilmesi gereken implanttır.',
    'sections' => [
        [
            'heading' => 'Ne zaman yapılır',
            'paragraphs' => [
                'Hakkında geri çekme kararı olan, miadı dolmuş ya da görevini tamamlamış bir tıbbi cihaz tüketiciden geri alındığında bu bildirim yapılır. Kullanım bildirimiyle hastaya geçen ürün, bu bildirimle hastadan geri alınmış olur.',
            ],
        ],
        [
            'heading' => 'Gerekçe',
            'paragraphs' => [
                'Bildirimde gerekçe zorunludur ve şu değerlerden biri seçilir:',
            ],
            'items' => [
                'Doku uyumsuzluğu',
                'Geri çağırma',
                'Kullanım ömrünü doldurma',
                'Revizyon',
                'Diğer (açıklama yazılması zorunlu)',
            ],
        ],
        [
            'heading' => 'Hangi bilgiler gider',
            'items' => [
                'Ürün numarası; seri takipli üründe seri numarası, lot takipli üründe lot numarası.',
                'Çıkarma tarihi (zorunlu). Bugünden sonraki bir tarih verilemez.',
                'Kişi kimlik bilgisinin türü (zorunlu): T.C. kimlik, yabancı kimlik, pasaport, YUPASS, kimliği belirsiz ya da diğer.',
                'Ürün için kullanırken kimlik gerekliyse hastanın adı, soyadı ve kimlik numarası; numara bilinmiyorsa kişiyi tanımlayan bir numara.',
            ],
        ],
        [
            'heading' => 'Sonra ne olur',
            'paragraphs' => [
                'Çıkarılan cihaz imha edilecekse, imha/bertaraf bildiriminde "hastanın vücudundan çıkarılmış" gerekçesi ayrıca vardır. Bildirimlerin iptali vardır; yanlış yapılan bildirim, bildirim koduyla iptal edilir.',
            ],
        ],
    ],
    'program' => 'Dental ERP\'de hastanın implant kartındaki üründe "Vücuttan çıkar" ile gerekçe ve çıkarma tarihi seçilir. Kullanımı ÜTS\'ye bildirilmiş üründe çıkarma bildirimi aynı hasta bilgileriyle kendiliğinden gider; tarih takılma tarihinden önce ya da ileri bir tarih olamaz. Çıkarma hasta kartında ve Seri Takibi\'nde görünür; ÜTS\'deki bildirim Gönderim Geçmişi\'nden iptal edilir.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.1.22, 3.1.21, 3.2 ve EK-A (UTS-H198)', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
