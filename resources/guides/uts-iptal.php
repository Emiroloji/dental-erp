<?php

return [
    'slug' => 'uts-bildirimi-iptal-edilebilir-mi',
    'order' => 11,
    'updated' => '2026-10-19',
    'title' => 'ÜTS bildirimi iptal edilebilir mi',
    'description' => 'Hangi ÜTS bildirimi iptal edilir, hangisi edilmez: alma bildiriminin iptali yok, iptal süresi, SGK kilidi ve iptal nasıl yapılır.',
    'summary' => 'Yanlış yapılan bir ÜTS bildirimi çoğu zaman iptal bildirimiyle geri alınır. Ama her bildirimin iptali yoktur ve iptalin de sınırları vardır.',
    'sections' => [
        [
            'heading' => 'İptal nasıl yapılır',
            'paragraphs' => [
                'Kurum, hatalı yaptığı bildirimi iptal etmek için iptal bildirimi yapar. Her bildirim türünün kendi iptal servisi vardır; hepsinde istenen tek bilgi, iptal edilecek bildirimin kodudur.',
            ],
        ],
        [
            'heading' => 'İptali olan bildirimler',
            'paragraphs' => [
                'Diş kliniğini ilgilendirenlerden: verme (tedarikçiye iade), kullanım, tüketiciye verme, hastanın vücudundan çıkarma, HEK/zayiat, imha/bertaraf, envanter ve tüketim bildirimlerinin iptali vardır.',
            ],
        ],
        [
            'heading' => 'İptali olmayanlar',
            'paragraphs' => [
                'Alma ve geri çekme alma bildirimlerinin karşılığında iptal bildirimi yoktur. Yanlış kabul edilen ürün ancak gönderene verme (iade) bildirimiyle geri verilebilir. Bu yüzden kabul etmeden önce ürünü, lotu ya da seriyi ve adedi kutuyla karşılaştırmak gerekir.',
            ],
        ],
        [
            'heading' => 'İptalin sınırları',
            'items' => [
                'Kurum yalnızca kendi yaptığı bildirimi iptal edebilir (UTS-H005).',
                'İptal süresi dolmuş bir bildirim iptal edilemez (UTS-H055).',
                'Karşı taraf alma bildirimini yaptıysa verme bildirimi artık iptal edilemez (UTS-H115).',
                'SGK tarafından kilitlenmiş bir kullanım bildirimi iptal edilemez (UTS-H221).',
            ],
        ],
        [
            'heading' => 'Hata fark edilince',
            'paragraphs' => [
                'Yanlış bildirimi fark ettiğiniz anda iptal etmek, iptal süresinin dolması ya da karşı tarafın işlem yapması gibi engellere takılma ihtimalini azaltır.',
            ],
        ],
    ],
    'program' => 'Dental ERP\'de iptali olan bildirimler (kullanım, iade, imha, HEK/zayiat) Gönderim Geçmişi\'nden onay paneliyle iptal edilir. Alma bildiriminden önce "geri alınamaz" uyarısı çıkar. ÜTS\'de geçerli bildirimi olan bir stok çıkışı ya da iade, önce ÜTS\'de iptal edilmeden geri alınamaz; böylece stok ile ÜTS ayrışmaz.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.2 ve EK-A (UTS-H005, H055, H115, H221)', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
