<?php

return [
    'slug' => 'uts-kullanim-tuketim-tuketiciye-verme-farki',
    'order' => 9,
    'updated' => '2026-10-19',
    'title' => 'ÜTS\'de kullanım, tüketim ve tüketiciye verme: hangisi ne zaman',
    'description' => 'Hastaya kullanılan ürün için kullanım, tüketim ya da tüketiciye verme bildirimi: üç bildirimin farkı ve diş kliniği için doğru olanı.',
    'summary' => 'Bir ürünün hastaya ulaştığını anlatan üç ayrı ÜTS bildirimi vardır. Hangisinin yapılacağı, ürünün türüne ve kliniğin ürünü nasıl verdiğine bağlıdır.',
    'sections' => [
        [
            'heading' => 'Kullanım bildirimi',
            'paragraphs' => [
                'Sağlık kuruluşu tekil ürünü bir hastaya kullandığında yapılır. İmplant, kemik grefti ve membran gibi hastada kalan ürünlerin olağan bildirimi budur. Ürün için kullanırken kimlik gerekliyse hastanın kimliği istenir; adet her bildirimde 1\'dir.',
            ],
        ],
        [
            'heading' => 'Tüketim bildirimi',
            'paragraphs' => [
                'Sağlık hizmet sunucuları belirli şartlara uyan tekil ürünler için tüketim bildirimi yapabilir. Ürün şu şartların hepsini sağlamalıdır:',
            ],
            'items' => [
                'Vücuda implante edilebilir olmamalıdır.',
                'SUT eşleşmesi olmamalıdır.',
                'Sınırsız kullanımlık olmamalıdır.',
                'Tek bir hastaya kullanılabilir olmamalıdır.',
            ],
        ],
        [
            'heading' => 'Tüketiciye verme bildirimi',
            'paragraphs' => [
                'Satış yeri, tüketiciye verdiği tekil ürünler için yapar; satış ve hibe gibi yöntemler bu bildirimle bildirilir. Ürün için satarken kimlik gerekliyse tüketicinin kimliği istenir. Reçete karşılığı verilen ve SGK geri ödemesindeki optik ürünler bu bildirimle değil, MEDULA üzerinden bildirilir.',
            ],
        ],
        [
            'heading' => 'Diş kliniği için kısa yol',
            'paragraphs' => [
                'Hastanın ağzına yerleştirilen ve orada kalan ürün implante edilebilir olduğu için tüketim bildirimine uygun değildir; kullanım bildirimi yapılır. Tüketim bildirimi, implante edilmeyen ve tek hastaya özgü olmayan ürünler içindir. Ürünün hangi takip ayarına sahip olduğu ÜTS\'deki ürün kaydında görünür; emin olamadığınız durumda kurumunuzun ÜTS sorumlusuna danışın.',
            ],
        ],
    ],
    'program' => 'Dental ERP\'de ÜTS takipli ürün "Klinik içi kullanım" nedeniyle çıkarıldığında hasta seçilir ve kullanım bildirimi kendiliğinden gider; lottan birden çok adet kullanıldıysa her adet ayrı bildirilir. Tüketim ve tüketiciye verme bildirimleri programda yoktur.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.1.9, 3.1.10, 3.1.24 ve 3.4.1', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
