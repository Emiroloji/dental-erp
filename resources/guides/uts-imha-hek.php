<?php

return [
    'slug' => 'uts-imha-ve-hek-zayiat-farki',
    'order' => 6,
    'updated' => '2026-10-18',
    'title' => 'ÜTS\'de imha ile HEK/zayiat bildirimi arasındaki fark',
    'description' => 'Son kullanma tarihi geçen, hasar gören ya da kaybolan ürün için ÜTS\'de hangi bildirim yapılır: imha/bertaraf ve HEK/zayiat, gerekçeler, iptal.',
    'summary' => 'Kullanılamayacak hale gelen bir tekil ürün ÜTS\'de ya imha/bertaraf ya da HEK/zayiat bildirimiyle kliniğin üzerinden düşer. İkisi farklı durumlar için, farklı bilgilerle yapılır.',
    'sections' => [
        [
            'heading' => 'İmha / bertaraf',
            'paragraphs' => [
                'Kurum, tekil ürünü herhangi bir gerekçeyle imha ettiğinde imha/bertaraf bildirimi yapar. Bildirimde gerekçe ve imha belge numarası zorunludur.',
            ],
            'items' => [
                'Son kullanma tarihi geçmiş',
                'Taşıma ve saklama koşulları bozulmuş',
                'Kurum kararı',
                'Gönüllü geri çekme',
                'Hastanın vücudundan çıkarılmış',
                'Sahte / kaçak',
                'Diğer (açıklama yazılması zorunlu)',
            ],
        ],
        [
            'heading' => 'HEK / zayiat',
            'paragraphs' => [
                'Ekonomik ömrünü tamamlayan ya da zayi olan tekil ürün için HEK/zayiat bildirimi yapılır. HEK, hurda, enkaz ve köhnenin kısaltmasıdır. Bildirimde tür seçilir: HEK, doğal afet, yangın, çalınma, stok düzeltme ya da diğer (açıklama zorunlu). Belge numarası istenmez.',
            ],
        ],
        [
            'heading' => 'Hangisini seçmeli',
            'paragraphs' => [
                'Ürünü bilerek ortadan kaldırdıysanız, örneğin son kullanma tarihi geçen implantı imha ettiyseniz, imha/bertaraf bildirimi yapılır. Ürün çalındı, yangında ya da afette kayboldu ya da sayımda bulunamadıysa HEK/zayiat bildirimi daha uygun düşer. Emin olamadığınız durumda kurumunuzun ÜTS sorumlusuna danışın.',
            ],
        ],
        [
            'heading' => 'Ortak noktalar',
            'paragraphs' => [
                'İki bildirimde de seri takipli üründe seri numarası, lot takipli üründe lot numarası ve adet verilir. İkisinin de iptali vardır; yanlış yapılan bildirim, bildirim koduyla iptal edilir.',
            ],
        ],
    ],
    'program' => 'ÜTS takipli ürünü Stok Çıkışı\'nda "SKT geçmiş" ya da "Hasarlı ürün" nedeniyle çıkarırken ÜTS bildirimi bölümü açılır: imha/bertaraf ya da HEK/zayiat, gerekçe ve imha tutanağı numarası. Gerekçe çıkış nedenine göre önceden seçili gelir. Gönderilemeyen bildirim Gönderim Geçmişi\'nden aynı gerekçeyle tekrar gönderilir; iptal de oradan yapılır.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.1.17, 3.1.21 ve 3.2', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
