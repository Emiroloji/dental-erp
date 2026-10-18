<?php

return [
    'slug' => 'uts-sistem-token-nasil-alinir',
    'order' => 1,
    'updated' => '2026-10-18',
    'title' => 'ÜTS sistem token\'ı nasıl alınır',
    'description' => 'ÜTS\'ye programdan bildirim yapmak için gereken sistem token\'ı: kim üretebilir, hangi menüden alınır, test ortamı nasıl kullanılır.',
    'summary' => 'Bir programın sizin adınıza ÜTS\'ye bildirim yapabilmesi için kurumunuza ait sistem token\'ı gerekir. Token\'ı kurumun imza yetkilisi e-imzayla, birkaç dakikada üretir.',
    'sections' => [
        [
            'heading' => 'Token nedir',
            'paragraphs' => [
                'ÜTS\'nin bütün işlevleri, kendi ekranlarının yanında başka bilgi sistemlerinin de kullanabildiği servislerdir. Bir program bu servisleri çağırabilmek için önce ÜTS\'den bir entegrasyon anahtarı almalıdır. Bu anahtar e-imzayla üretilir ve sistem token\'ı olarak adlandırılır.',
                'Token, ÜTS\'ye bildirim gönderen programın kimliğini doğrular ve yetkisinin sınırını belirler. Program her çağrıda token\'ı "utsToken" başlığıyla gönderir; token yoksa ÜTS yetki hatası verir ve işlemi yapmaz.',
            ],
        ],
        [
            'heading' => 'Kim üretebilir',
            'paragraphs' => [
                'Token\'ı yalnızca kurumda imza yetkilisi rolündeki kullanıcı üretebilir. ÜTS bu rolü MERSİS\'ten kendiliğinden sorgular. Kliniğinizde bu kişi genellikle şirketin yetkilisidir; personel hesabıyla token üretilemez.',
            ],
        ],
        [
            'heading' => 'Adım adım',
            'steps' => true,
            'items' => [
                'İmza yetkilisi utsuygulama.saglik.gov.tr adresinden ÜTS\'ye girer. Birden fazla kurumda yetkisi varsa "Hesap Değiştir" ile kliniğin hesabına geçer.',
                'Menüden Kullanıcı → Sistem Kullanıcısı Tanımlama İşlemleri seçilir.',
                'Ekranda çıkan metin e-imzayla imzalanır.',
                'ÜTS token\'ı üretir; token kopyalanıp bildirimi yapacak programa yapıştırılır.',
            ],
        ],
        [
            'heading' => 'Önce test ortamında deneyin',
            'paragraphs' => [
                'Ürün hareketleri ve ürün geri çağırma modülleri ÜTS\'nin test ortamında (utstest.saglik.gov.tr) da çalışır. Test ortamındaki veriler gerçek ortama aktarılmaz; bu yüzden bir programı ilk kez bağlarken test ortamı token\'ıyla denemek güvenli bir başlangıçtır. Test ve gerçek ortamın token\'ları ayrıdır.',
            ],
        ],
        [
            'heading' => 'Token\'ı nasıl korumalı',
            'paragraphs' => [
                'Token, kurumunuz adına ÜTS\'ye bildirim yapma yetkisi taşır. Bir şifre gibi davranın: e-postayla dolaştırmayın, yalnızca bildirimi yapacak programın ayar ekranına girin. Bir programla çalışmayı bıraktığınızda token\'ı orada da kaldırın.',
            ],
        ],
    ],
    'program' => 'Ayarlar → ÜTS Bağlantısı\'na token\'ı ve ortamı (test ya da gerçek) girer, "Bağlantıyı dene" ile doğrularsınız. Token şifreli saklanır, ekranda geri gösterilmez ve hiçbir kayda yazılmaz. Token\'ı yalnızca klinik sahibi girip değiştirebilir.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 2.1', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
