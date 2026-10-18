<?php

return [
    'slug' => 'uts-seri-ve-lot-takibi-farki',
    'order' => 12,
    'updated' => '2026-10-19',
    'title' => 'ÜTS\'de seri (tekil) ve lot takibi arasındaki fark',
    'description' => 'Bir ürünün ÜTS\'de seri numarasıyla mı lotla mı takip edildiği bildirimleri nasıl değiştirir: adet, seri ve lot alanları, kimlik ayarları.',
    'summary' => 'ÜTS\'deki her tekil ürünün bir takip ayarı vardır: ya tek tek seri numarasıyla ya da lot (parti) numarasıyla izlenir. Bu ayar, her bildirimde hangi alanların doldurulacağını belirler.',
    'sections' => [
        [
            'heading' => 'Takip ayarı nereden gelir',
            'paragraphs' => [
                'Üretici ya da ithalatçı ürünü ÜTS\'ye kaydederken takibi belirler: yalnızca seri numarası verilirse ürün tek tek takip edilir ve adedi 1\'dir; seri ve lot birlikte verilirse yine tek tek takip edilir; yalnızca lot numarası verilirse ürün lot olarak takip edilir ve adedi 1 ya da daha fazla olabilir.',
                'Kurum üzerindeki bir tekil ürünü sorguladığınızda ürünün takip ayarı (TEKİL ya da LOT) cevapta görünür.',
            ],
        ],
        [
            'heading' => 'Bildirimlerde ne değişir',
            'items' => [
                'Seri takipli üründe seri numarası verilir, adet verilmez; her birim ayrı satırdır.',
                'Lot takipli üründe lot numarası ve adet verilir (verme, imha, HEK/zayiat gibi bildirimlerde).',
                'Kullanım bildiriminde lot takipli üründe de adet 1\'dir; birden çok adet ayrı ayrı bildirilir.',
            ],
        ],
        [
            'heading' => 'Kimlik ayarları',
            'paragraphs' => [
                'Ürün kaydında iki ayrı ayar daha vardır: satarken kimlik numarası gerekli mi ve kullanırken kimlik numarası gerekli mi. Kullanırken kimlik gerekli olan üründe kullanım bildirimi hastanın kimliğini ister.',
            ],
        ],
        [
            'heading' => 'Klinikte neden önemli',
            'paragraphs' => [
                'Seri takipli bir implantı stokta tek tek seri numarasıyla tutmazsanız, hangi serinin hangi hastaya takıldığını ve hangi serinin iade edildiğini ÜTS\'ye doğru bildiremezsiniz. Stok kaydınızın takibi ÜTS\'deki takip ayarıyla uyumlu olmalıdır.',
            ],
        ],
    ],
    'program' => 'Dental ERP\'de ürün kartında "Seri numarası takibi" açılan ürün girişte ve çıkışta seri seri işlenir; seri numarası kutudaki kare kod okutularak da girilebilir. Lot/Seri Doğrula ekranı bir ürünün ÜTS\'deki kaydını, takip ayarını ve son kullanma tarihini sizin kaydınızla karşılaştırır.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.1.1, 3.1.9 ve 3.4.1', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
