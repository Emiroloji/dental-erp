<?php

return [
    'slug' => 'uts-hata-mesajlari-ve-anlamlari',
    'order' => 10,
    'updated' => '2026-10-19',
    'title' => 'Diş kliniğinin sık gördüğü ÜTS hata mesajları',
    'description' => 'Alma, kullanım ve iade bildiriminde karşılaşılan ÜTS hata kodları (UTS-H171, H222, H009...) ne anlama gelir, ne yapmak gerekir.',
    'summary' => 'ÜTS her bildirime bir mesaj listesiyle cevap verir. Mesajın tipi bilgi, uyarı ya da hata olabilir; hata mesajları bir kod ve okunabilir bir açıklama taşır. Aşağıda diş kliniğinin işlerinde en çok karşılaşılabilecekler var.',
    'sections' => [
        [
            'heading' => 'Mesaj nasıl okunur',
            'paragraphs' => [
                'Bildirim cevabında başarılıysa yeni bildirimin kodu döner. Mesaj listesindeki her mesajın tipi (BİLGİ, UYARI, HATA), insanların okuyacağı metni ve programların işleyeceği kodu vardır. HATA tipli bir mesaj varsa bildirim yapılmamıştır.',
            ],
        ],
        [
            'heading' => 'Alma bildiriminde',
            'items' => [
                'UTS-H171 "Verilen tekil ürün bilgilerine uyan birden fazla bildirim bulundu": aynı ürün ve lota uyan birden çok verme bildirimi var. Kabul bekleyenler listesinden seçip bildirim koduyla kabul edin.',
                'UTS-H008 "Alınan adet, verme bildiriminde gönderilen adetten fazla olamaz": kabul edilen adet, tedarikçinin bildirdiğinden fazla yazılmış.',
                'UTS-H034 "Verme bildirimi yetkili olunan kuruma ait değildir": bildirim başka bir kuruma yapılmış; doğru kurum hesabında olduğunuzu ve token\'ın o kuruma ait olduğunu kontrol edin.',
                'UTS-H037 "Verme bildirimi veritabanında bulunmamaktadır": kod yanlış ya da bildirim iptal edilmiş olabilir; kabul bekleyenler listesini yenileyin.',
            ],
        ],
        [
            'heading' => 'Kullanım bildiriminde',
            'items' => [
                'UTS-H222 "Kullanım bildirimi için adet 1 olmalıdır": her adet ayrı bildirilir.',
                'UTS-H066 "Hasta kimlik numarası girilmiş olmalıdır" ve UTS-H164 "Kimlik bilgileri girilmelidir": ürün için kullanırken kimlik gereklidir.',
                'UTS-H199 "Kullanım tarihi bugünden sonra olamaz".',
                'UTS-H067 "Kullanım sayısı sınırsız olan ürünler için kullanım bildirimi yapılamaz".',
            ],
        ],
        [
            'heading' => 'Ürünün durumuyla ilgili',
            'items' => [
                'UTS-H009 "Bu tekil ürün şu an askıdadır" ve UTS-H047 "Askıdaki ürünler için bildirim yapılamaz": ürün henüz kliniğinize geçmemiş olabilir; önce alma bildirimini yapın.',
                'UTS-H081 "Bu tekil ürün şu an geri çekme askıdadır": ürün hakkında geri çekme süreci var; yeni bildirim yapılamaz.',
                'UTS-H051 "Tekil ürünün son kullanma tarihi geçmiştir".',
                'UTS-H002 "Tekil ürün bulunamadı" ve UTS-H014 "Ürün sistemde bulunmamaktadır": ürün numarası, lot ya da seri hatalı yazılmış olabilir.',
            ],
        ],
        [
            'heading' => 'İptal ve kurumla ilgili',
            'items' => [
                'UTS-H055 "Bildirim iptal süresi dolmuştur. Bildirim iptal edilemez".',
                'UTS-H115 "Alma bildirimi yapılmış verme bildirimi iptal edilemez": tedarikçi, siz kabul ettikten sonra kendi bildirimini iptal edemez.',
                'UTS-H005 "Kurum sadece kendine ait bildirimleri iptal edebilir".',
                'UTS-H082 "Verilen kurum sistemde bulunamadı": iade (verme) bildiriminde yazılan kurum numarası yanlış.',
            ],
        ],
    ],
    'program' => 'Dental ERP ÜTS\'nin hata mesajını olduğu gibi gösterir ve her denemeyi mesajıyla birlikte Gönderim Geçmişi\'ne yazar. Kabul her zaman bildirim koduyla yapıldığı için UTS-H171 oluşmaz; hastaya kullanımda her adet ayrı gönderildiği için UTS-H222 de oluşmaz.',
    'sources' => [
        ['ÜTS Takip ve İzleme Web Servis Tanımları Dokümanı, rev. 1.99, bölüm 3.3 ve EK-A Servis Mesajları', 'https://uts.saglik.gov.tr/wp-content/uploads/2021/UTS-PRJ-TakipVeIzlemeWebServisTanimlariDokumani.pdf'],
    ],
];
