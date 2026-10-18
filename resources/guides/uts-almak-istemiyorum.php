<?php

return [
    'slug' => 'uts-almak-istemiyorum-ne-zaman-kullanilir',
    'order' => 3,
    'updated' => '2026-10-18',
    'title' => 'ÜTS\'de "almak istemiyorum" ne zaman kullanılır',
    'description' => 'Kliniğe gelmeyen ya da yanlış gönderilen ürünün verme bildirimini kabul bekleyenlerden kaldırmak: almak istemiyorum ve geri alınması.',
    'summary' => 'Tedarikçinin size yaptığı her verme bildirimini kabul etmek zorunda değilsiniz. Ürün gelmediyse ya da yanlış gönderildiyse bildirimi "almak istemiyorum" olarak işaretlersiniz; bu işlem geri alınabilir.',
    'sections' => [
        [
            'heading' => 'Ne zaman kullanılır',
            'paragraphs' => [
                'Kuruma gelen bir verme bildirimi kabul edilmeyecekse, kabul bekleyenler arasında kalmaması için "almak istemiyorum" olarak işaretlenir. Bildirim böylece kabul edilecek ürünler listesinden kalkar.',
                'Tipik durumlar: ürün kliniğe hiç gelmedi, sipariş etmediğiniz bir ürün bildirildi, bildirim başka bir kuruma yapılacakken size yapıldı ya da ürün bildirimden farklı geldi.',
            ],
        ],
        [
            'heading' => 'Nasıl işaretlenir',
            'paragraphs' => [
                'İşaretleme verme bildiriminin koduyla yapılır. Kodu kabul bekleyenler listesinde her satır taşır; ayrıca ürün ya da lot bilgisi gerekmez.',
            ],
        ],
        [
            'heading' => 'Geri alınabilir',
            'paragraphs' => [
                'Alma bildiriminden farklı olarak bu işlem geri alınabilir. Almak istemiyorum olarak işaretlediğiniz bildirimi sonradan almak isterseniz "almak istiyorum" olarak işaretlersiniz; bildirim yeniden kabul edilecek ürünler arasına döner. Almak istemediğiniz verme bildirimleri ayrıca sorgulanabilir.',
            ],
        ],
        [
            'heading' => 'Tedarikçiyle konuşun',
            'paragraphs' => [
                'İşaretleme ÜTS\'deki kaydı düzenler, tedarikçiyle aranızdaki meseleyi çözmez. Yanlış ya da gelmeyen gönderim için tedarikçiye haber verin; gerekirse kendi verme bildirimini iptal etmesi ya da düzeltmesi gerekir.',
            ],
        ],
    ],
    'program' => 'ÜTS → Kabul Bekleyenler\'de her satırda "Almak istemiyorum" bulunur. Onay panelinden geçtikten sonra işaretleme ÜTS\'ye gider, satır listeden kalkar ve kim, ne zaman işaretlediyle Gönderim Geçmişi\'ne yazılır.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.4.22–3.4.25', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
