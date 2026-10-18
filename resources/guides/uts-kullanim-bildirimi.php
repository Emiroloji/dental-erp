<?php

return [
    'slug' => 'uts-kullanim-bildirimi-implant',
    'order' => 4,
    'updated' => '2026-10-18',
    'title' => 'Hastaya implant takılınca ÜTS kullanım bildirimi',
    'description' => 'İmplant, greft ve membran hastaya kullanıldığında ÜTS kullanım bildirimi: hangi bilgiler istenir, kimlik ne zaman gerekir, iptal nasıl olur.',
    'summary' => 'Sağlık kuruluşu tekil ürünü bir hastaya kullandığında ÜTS\'ye kullanım bildirimi yapar. Bildirim ürünün kimliğini, kullanım tarihini ve gerekiyorsa hastanın kimliğini taşır.',
    'sections' => [
        [
            'heading' => 'Kullanım bildirimi nedir',
            'paragraphs' => [
                'Kullanım bildirimi, kliniğin sahip olduğu bir tekil ürünü hastaya kullandığını ÜTS\'ye bildirmesidir. Diş kliniğinde bu çoğunlukla implant, kemik grefti, membran ve benzeri ürünlerdir.',
            ],
        ],
        [
            'heading' => 'Hangi bilgiler gider',
            'items' => [
                'Ürün numarası (barkod/GTIN).',
                'Seri numarasıyla takip edilen üründe seri numarası; lot takipli üründe lot numarası.',
                'Kullanım tarihi. Bugünden sonraki bir tarih verilemez.',
                'Gerekiyorsa hastanın adı, soyadı ve kimlik bilgisi.',
            ],
        ],
        [
            'heading' => 'Her adet ayrı bildirilir',
            'paragraphs' => [
                'Kullanım bildiriminde adet 1\'dir; adet yazılmazsa 1 kabul edilir ve 1\'den farklı adet ÜTS tarafından reddedilir. Aynı lottan bir hastaya iki birim kullandıysanız iki ayrı kullanım bildirimi yapılır.',
            ],
        ],
        [
            'heading' => 'Hasta kimliği ne zaman gerekir',
            'paragraphs' => [
                'Hasta adı, soyadı ve kimlik numarası, ürün için "kullanırken kimlik numarası gerekli" ayarı varsa istenir. Bu ayarın ürününüzde olup olmadığı, ÜTS\'de tekil ürün sorgulandığında dönen bilgide görünür.',
                'Kimlik türüne göre T.C. kimlik numarası, yabancı kimlik numarası ya da pasaport numarası verilir. Kimlik numarası bilinmiyorsa kişiyi tanımlayan bir numara ve kimliğin belirsiz olduğu belirtilir. Yeni doğan için ayrıca işaret vardır.',
            ],
        ],
        [
            'heading' => 'Yanlış bildirimin iptali',
            'paragraphs' => [
                'Kullanım bildiriminin iptali vardır. Yanlış hastaya ya da yanlış ürünle yapılan bildirim, bildirim koduyla iptal edilir.',
            ],
        ],
        [
            'heading' => 'Kişisel veri',
            'paragraphs' => [
                'Hasta kimliği kişisel ve sağlıkla ilişkili veridir. Yalnızca gereken kadarını tutun, yetkisiz kişilerin görmesini engelleyin. Aydınlatma ve saklama süresi gibi konular için hukuk danışmanınıza başvurun.',
            ],
        ],
    ],
    'program' => 'ÜTS takipli ürünü Stok Çıkışı\'nda "Klinik içi kullanım" nedeniyle çıkarırken hasta ve kullanım tarihi istenir; kayıttan sonra kullanım bildirimi kendiliğinden gider ve ürün hastanın implant kartına yazılır. Kimlik numarası şifreli saklanır, ekranlarda maskeli görünür. Yanlış bildirim Gönderim Geçmişi\'nden iptal edilir; iptal edilmeden stok çıkışı geri alınamaz.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.1.9, 3.2, 3.4.1 ve EK-A (UTS-H199, UTS-H222)', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
