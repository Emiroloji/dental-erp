<?php

return [
    'slug' => 'ilac-tibbi-cihaz-e-faturasi-nasil-okunur',
    'order' => 7,
    'updated' => '2026-10-18',
    'title' => 'İlaç ve tıbbi cihaz e-faturası (ILAC_TIBBICIHAZ) nasıl okunur',
    'description' => 'ILAC_TIBBICIHAZ senaryolu e-faturada ürün numarası, lot, seri ve tarih bilgisi nerede durur; tedarikçi faturasını ÜTS kaydıyla eşleştirmek.',
    'summary' => 'İlaç ve tıbbi cihaz satan tedarikçiler, İTS ve ÜTS\'ye bildirilen ürünler için e-faturayı ILAC_TIBBICIHAZ senaryosuyla keser. Bu faturada her kalemin takip bilgisi de yazılıdır; faturayı ÜTS kaydıyla eşleştirmeyi mümkün kılan da budur.',
    'sections' => [
        [
            'heading' => 'Senaryo ve fatura tipleri',
            'paragraphs' => [
                'e-Fatura uygulamasına dahil olan ve ilaç ya da tıbbi cihaz ticareti yapan mükellefler, İTS ve ÜTS üzerinden bildirimi yapılan ürünler için ILAC_TIBBICIHAZ senaryosunu kullanır. Bu senaryoda SATIS, ISTISNA, TEVKIFAT, TEVKIFATIADE, IADE ya da IHRACKAYITLI fatura tiplerinden biri seçilir.',
                'GİB\'in teknik kılavuzu ilk kez 06.01.2025\'te yayımlandı; 30.06.2025 tarihli 1.2 sürümüyle takip sistemi dışındaki ürün ve hizmetlerin de aynı faturada gösterilebileceği açıklandı.',
            ],
        ],
        [
            'heading' => 'Takip bilgisi nerede durur',
            'paragraphs' => [
                'Her fatura kaleminin ürün bölümünde ek ürün kimliği alanları bulunur. Kimliğin türü bir şema adıyla belirtilir ve bilgiler parantez içindeki kısaltmalarla tek satırda yazılır.',
            ],
            'items' => [
                'Tıbbi cihaz (şema TIBBICIHAZ): (UNO) ürün numarası, (LNO) lot numarası, (SNO) seri numarası, (URT) üretim tarihi. Yalnızca partiyle takip edilen cihazda seri numarası yazılmaz.',
                'İlaç (şema ILAC): (GTIN) ürün numarası, (BN) parti numarası, (SN) sıra numarası, (XD) son kullanma tarihi.',
                'Takip dışı ürün ya da hizmet (şema DIGER): kimlik olarak 1111111111 yazılır.',
            ],
        ],
        [
            'heading' => 'Örnek',
            'paragraphs' => [
                'İki adet implantın kaleminde, her implant için bir kimlik satırı bulunur: (UNO)08699999999994(LNO)IMP2026A(SNO)SN-778812(URT)260115. Buradaki UNO, LNO ve SNO, tedarikçinin ÜTS\'de size yaptığı verme bildirimindeki ürün, lot ve seri numaralarıyla aynı bilgilerdir. Tarihler yıl, ay, gün sırasıyla altı haneli yazılır; 260115, 15 Ocak 2026\'dır.',
            ],
        ],
        [
            'heading' => 'Kliniğe faydası',
            'paragraphs' => [
                'Tedarikçi ÜTS\'deki verme bildirimine belge numarası olarak fatura ya da irsaliye numarasını yazar; kalemlerdeki ürün, lot ve seri bilgisi de ÜTS kaydıyla aynı alanlardır. Belge numarası faturanınkiyle aynıysa, hangi faturanın hangi ÜTS kabulüne ve hangi stok girişine karşılık geldiği elle eşleştirme yapmadan bulunabilir.',
            ],
        ],
    ],
    'program' => 'Gelen e-faturalar entegratörünüzden yarım saatte bir çekilir ve UBL-TR belgesinden okunur; ilaç ve tıbbi cihaz kalemlerinde ürün numarası, lot, seri ve tarihler ayrıştırılır. Fatura tedarikçi kartınızla, teslim kaydınızla ve ürün kartlarınızla eşleşir; aynı belge numarasıyla ÜTS\'de kabul bekleyen ürünler faturanın altında listelenir.',
    'sources' => [
        ['GİB, İlaç ve Tıbbi Cihaz Teslimlerine İlişkin Fatura Teknik Kılavuzu, sürüm 1.2 (Haziran 2025)', 'https://ebelge.gib.gov.tr/dosyalar/kilavuzlar/Ilac_ve_Tibbi_Cihaz_Teslimlerine_Iliskin_Fatura_Teknik_Kilavuzu_V.1.2.pdf'],
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.1.4 ve 3.4.10', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
