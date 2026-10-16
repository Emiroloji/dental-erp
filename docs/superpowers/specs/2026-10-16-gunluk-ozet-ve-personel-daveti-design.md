# Günlük Özet E-postası ve Personel Daveti (Aşama 39) Tasarımı

Tarih: 16.10.2026 · Durum: kullanıcı tarafından onaylandı (16.10.2026)

## Amaç

1. Uygulamayı her gün açmayan klinik sahibi ve personelin, kendilerine düşen uyarıları (kritik stok, SKT, ödeme/tahsilat vadeleri, transfer/satın alma/iade/sayım/soğuk zincir) her sabah tek bir e-postayla öğrenmesi.
2. Admin'in yeni personele şifre belirleyip bunu elden iletmek zorunda kalmaması.

## Kullanıcı kararları (16.10.2026)

| # | Soru | Karar | Gerekçe |
|---|---|---|---|
| 1 | Özet neyi anlatır? | Kişinin son özetten beri düşen, okunmamış uygulama içi bildirimleri, türlerine göre gruplu | Alıcı seçimi, şube kapsamı ve tekrar engeli zaten bildirim üretiminde var; ikinci bir hesap yolu açılmaz |
| 2 | Kim alır? | Admin ve personelin hepsi; varsayılan açık, her kullanıcı kendisi kapatabilir | — |
| 3 | Yeni personel | Şifre alanı kalkar; sistem geçici şifre üretip "hesabınız açıldı" e-postası gönderir; ilk girişte şifre değiştirme zorunlu | Admin şifre bilmez ve iletmez |

Davet yöntemi: Ana Klinik Sahibi hesabı açılırken kullanılan desen (proje.md Bölüm 2, `OrganizationAccountCreated`: geçici şifre + `must_change_password`) aynen izlenir. Tek kullanımlık bağlantılı (token) davet reddedildi: ikinci bir şifre aracısı, 7 günlük ayrı token süresi ve yeni bir sayfa gerektirirdi; mevcut desenle tutarlılık daha değerli. Geçici şifre ilk girişte zorunlu değiştirildiği için açıkta kalma süresi sınırlıdır.

## Kapsam dışı

Anlık (her bildirimde) e-posta, bildirim türü bazında e-posta tercihi, SMS/push, özet saati seçimi, e-posta tasarım şablonu (Laravel'in standart MailMessage görünümü kullanılır), Admin'in kendisi için davet (Admin'i Platform Sahibi açar, değişmedi).

## 1. Ortak bildirim türleri

`resources/views/pages/notifications/⚡index.blade.php` içindeki `KINDS` sabiti `App\Support\Notifications\NotificationKind` sınıfına taşınır:

- `NotificationKind::LABELS` — `stock` Stok Uyarıları, `cold_chain` Soğuk Zincir (bugün listede eksik, bu aşamada eklenir), `transfer`, `purchase`, `stock_count`, `return`, `plan`, `finance`.
- `NotificationKind::of(DatabaseNotification $notification): string` — `StockLevelAlert` → `stock`, diğerleri `data.kind`, bilinmeyen → `other` ("Diğer").
- `NotificationKind::summary(DatabaseNotification): string` — tek satırlık metin: `data.message`, yoksa stok uyarısında `"{product_name}: {reasons[0]}"`.

Bildirimler sayfası ve özet e-postası aynı sınıfı kullanır.

## 2. Günlük özet

**Veri:** `users` tablosuna iki sütun (migration):
- `daily_digest` boolean, varsayılan `true`
- `digest_sent_at` timestamp, nullable — son özet penceresinin sonu

**Servis:** `App\Domain\Access\Services\DailyDigestService::send(?CarbonImmutable $now = null): int`
- Aday kullanıcılar: `status = active`, `daily_digest = true`, organizasyonu `active` veya `read_only` (pasif organizasyona e-posta gitmez), Platform Sahibi hariç (`organization_id` dolu).
- Pencere: `(digest_sent_at ?? now − 24 saat, now]`, en fazla 7 gün geriye.
- Penceredeki **okunmamış** bildirimler alınır. Hiç yoksa e-posta gönderilmez.
- Her durumda `digest_sent_at = now` yazılır (pencere ilerler; ertesi gün aynı bildirim tekrar gelmez).
- Kullanıcılar `chunkById(100)` ile işlenir.

**Bildirim:** `App\Domain\Access\Notifications\DailyDigest` (mail kanalı, `ShouldQueue` — gönderimi worker yapar, zamanlayıcı beklemez).
- Konu: `Dental ERP — günlük özet: 7 yeni bildirim`
- Gövde: selamlama; tür başlığına göre gruplu liste (her grupta en fazla 10 satır, fazlası "ve 4 bildirim daha"); her satır `NotificationKind::summary`; "Bildirimleri aç" düğmesi (`route('notifications.index')`); son satırda "Bu e-postayı Bildirimler sayfasından kapatabilirsiniz."
- Grup sırası: Stok Uyarıları, Soğuk Zincir, Finans, sonra diğerleri `LABELS` sırasıyla.

**Komut ve zamanlama:** `notifications:send-digest` → `DailyDigestService::send()`; `routes/console.php` içinde `->dailyAt('07:30')->timezone('Europe/Istanbul')->withoutOverlapping()`. Stok taraması saatlik, finans vade taraması 05:00'te çalıştığı için 07:30 özeti o sabahın uyarılarını da içerir.

**Tercih:** Bildirimler sayfasının başlığında "Günlük özet e-postası: Açık/Kapalı" anahtarı (`toggleDigest()`), yalnızca kullanıcının kendi tercihini değiştirir. Salt-okunur organizasyonda da değiştirilebilir (kişisel tercih, iş verisi değil).

## 3. Personel daveti

- `StaffService::createStaff` artık `password` almaz: `Str::password(12, symbols: false)` ile geçici şifre üretir, `must_change_password = true` yazar. Dönüş değişmez (`User`); geçici şifre transaction **sonrasında** `StaffAccountCreated` bildirimiyle e-postalanır.
- Yeni `StaffService::resendInvite(User $staff): void` — yalnızca aynı organizasyonun aktif personeli için; yeni geçici şifre üretir, `must_change_password = true`, `StaffAccountCreated(isReset: true)` gönderir. Yetki: `staff_management.update`.
- `App\Domain\Access\Notifications\StaffAccountCreated` (mail, senkron — `OrganizationAccountCreated` gibi; şifre kuyruk deposunda beklemez). Metin: "{Organizasyon} için Dental ERP hesabınız açıldı", giriş e-postası, geçici şifre, "Giriş Yap" düğmesi, "İlk girişte kendi şifrenizi belirlemeniz istenecek." Yenilemede konu "geçici şifreniz yenilendi".
- E-posta gönderilemezse (SMTP hatası) personel kaydı yine oluşur; ekranda "Personel oluşturuldu ancak e-posta gönderilemedi. 'Yeni şifre gönder' ile tekrar deneyin." uyarısı görünür ve hata raporlanır (`report($e)`).
- Personel ekranı: şifre alanı kalkar, yerine "Geçici şifre personelin e-postasına gönderilir." açıklaması. Personel listesinde aktif personel satırında "Yeni şifre gönder" eylemi (onay sorulur).

## 4. Testler

- `NotificationKindTest`: tür çözümü, özet metni, `cold_chain` etiketi.
- `DailyDigestServiceTest`: yalnızca okunmamış ve pencere içi bildirimler; bildirim yoksa e-posta yok ama `digest_sent_at` ilerler; ikinci çalıştırmada aynı bildirim tekrar gelmez; tercihi kapalı, pasif kullanıcı, pasif organizasyon ve Platform Sahibi almaz; 7 gün sınırı; gruplama ve konu satırı (`Notification::fake` + `toMail` içeriği).
- `notifications:send-digest` komutu ve zamanlama kaydı (`Schedule` olayında komut ve saat dilimi).
- Bildirimler sayfası: tercih anahtarı yalnızca kendi kaydını değiştirir; `cold_chain` filtresi.
- `StaffManagementTest`: oluşturmada şifre alanı yok, `must_change_password = true`, `StaffAccountCreated` e-postası doğru kişiye; e-postadaki şifreyle giriş → şifre değiştirme sayfasına yönlendirme; "Yeni şifre gönder" eski şifreyi geçersiz kılar; başka organizasyonun personeline ve Admin'e uygulanamaz; yetkisiz personel 403; SMTP hatasında kayıt oluşur ve uyarı görünür.
- Mevcut testler: personel ekranına şifre yazan testlerden `->set('password', …)` kaldırılır; oluşturulan personelle giriş yapan testler şifreyi `Notification::fake()` ile yakalanan `StaffAccountCreated` bildiriminden alır.
