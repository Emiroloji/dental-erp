# ÜTS Sorgulama (Aşama 33 — Aşama A) Uygulama Planı

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Klinik, ÜTS'deki (Ürün Takip Sistemi) kabul bekleyen Verme bildirimlerini görsün ve bir lot/seri numarasını ÜTS'de doğrulasın; hiçbir şey ÜTS'ye yazılmasın.

**Architecture:** `app/Domain/Uts` altında `UtsClient` arayüzü (gerçek `HttpUtsClient`, `FakeUtsClient`, `UnconfiguredUtsClient`), mevcut `QueryInterpreter` deseniyle `AppServiceProvider`'da bağlanır; token organizasyon başına şifreli `uts_connections` tablosunda tutulur. Canlı sorgu, yerel eşitleme yok. İki Livewire sayfası (Kabul Bekleyenler, Doğrula) ve Ayarlar'da bağlantı kartı.

**Tech Stack:** Laravel + Livewire (tek dosyalı `⚡` sayfalar), PHPUnit, `Http::fake`, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-10-01-uts-sorgulama-design.md`

## Global Constraints

- Arayüz metinleri, hata mesajları, yorumlar ve commit mesajları **Türkçe** (`feat: ... — Aşama 33 (n/m)`); commit sonuna `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- ÜTS'ye **yazma yok**: yalnızca `.../tekilUrun/sorgula` ve `.../bildirim/alma/bekleyenler/sorgula`.
- Token: her istekte `utsToken` başlığı; `encrypted` cast ile saklanır; arayüzde, günlükte, denetim kaydında ve hata mesajında **asla** görünmez. `uts_connections` modeline `Auditable` **eklenmez**.
- Adresler: test `https://utstest.saglik.gov.tr`, gerçek `https://utsuygulama.saglik.gov.tr`; yollar `/UTS/uh/rest/...`.
- Token girme/silme yetkisi `uts_connection.update` (yalnızca Admin; salt-okunur organizasyonda kapalı). Ekranları görme `stock_movement.viewAny` ve şube kapsamı.
- Tenant izolasyonu: modeller `BelongsToOrganization`; `StockLot` kapsamı `whereHas('product')` ile, `PurchaseReceipt` kapsamı `whereHas('order')` ile sağlanır (modelin kendi kapsamı yok).
- Yeni Tailwind sınıfı eklenirse `npm run build`; arayüz gerçekten render edilip görülmeden "çalışıyor" denmez (`frontend-design` skill'i).
- Her task sonunda `vendor/bin/pint --dirty` ve ilgili testler yeşil.

## Dosya Yapısı

| Dosya | Sorumluluk |
|---|---|
| `config/uts.php` | adresler, zaman aşımı |
| `app/Domain/Uts/Contracts/UtsClient.php` | arayüz |
| `app/Domain/Uts/Exceptions/UtsException.php`, `UtsAuthException.php` | kullanıcıya gösterilebilir hatalar |
| `app/Domain/Uts/Support/PendingReceipt.php`, `PendingReceiptPage.php`, `UtsItem.php` | değer nesneleri |
| `app/Domain/Uts/Clients/HttpUtsClient.php`, `FakeUtsClient.php`, `UnconfiguredUtsClient.php` | uygulamalar |
| `app/Domain/Uts/Models/UtsConnection.php` + migration | organizasyon başına token |
| `app/Domain/Uts/Services/UtsConnectionService.php` | kaydet/test/sil |
| `app/Domain/Uts/Services/UtsMatcher.php` | ÜTS kaydını yerel veriyle eşleştirme |
| `resources/views/pages/uts/⚡pending.blade.php`, `⚡verify.blade.php` | ekranlar |
| `resources/views/pages/settings/⚡index.blade.php` | bağlantı kartı (değişiklik) |
| `tests/Feature/Uts/*` | testler |

---

### Task 1: Arayüz, değer nesneleri, istisnalar, sahte ve yapılandırılmamış istemci

**Files:**
- Create: `config/uts.php`, `app/Domain/Uts/Contracts/UtsClient.php`, `app/Domain/Uts/Exceptions/UtsException.php`, `app/Domain/Uts/Exceptions/UtsAuthException.php`, `app/Domain/Uts/Support/PendingReceipt.php`, `app/Domain/Uts/Support/PendingReceiptPage.php`, `app/Domain/Uts/Support/UtsItem.php`, `app/Domain/Uts/Clients/FakeUtsClient.php`, `app/Domain/Uts/Clients/UnconfiguredUtsClient.php`
- Test: `tests/Feature/Uts/UtsClientContractTest.php`

**Interfaces:**
- Produces: `UtsClient::pendingReceipts(?int $senderCode = null, int $page = 0): PendingReceiptPage`, `UtsClient::lookup(string $uno, ?string $lot = null, ?string $serial = null): array` (`list<UtsItem>`), `UtsClient::name(): string`. `PendingReceipt::fromArray(array $row)` ve `UtsItem::fromArray(array $row)` ÜTS JSON kodlarını okur. `FakeUtsClient::__construct(array $pending = [], array $items = [], ?UtsException $failure = null)`.

- [ ] **Step 1: Failing test yaz**

```php
<?php

namespace Tests\Feature\Uts;

use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Clients\UnconfiguredUtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceipt;
use App\Domain\Uts\Support\UtsItem;
use Tests\TestCase;

class UtsClientContractTest extends TestCase
{
    public function test_pending_receipt_reads_uts_json_codes(): void
    {
        $receipt = PendingReceipt::fromArray([
            'GKK' => 7, 'UNO' => '08699999999990', 'LNO' => 'L1', 'SNO' => 'S1', 'ADT' => 3,
            'BID' => 'abc-1', 'BNO' => 'IRS-9', 'BZA' => '2026-10-01 09:30:00',
            'GKU' => 'Dental Tedarik A.Ş.', 'MME' => 'Marka Model',
        ]);

        $this->assertSame(7, $receipt->senderCode);
        $this->assertSame('08699999999990', $receipt->uno);
        $this->assertSame('L1', $receipt->lot);
        $this->assertSame('S1', $receipt->serial);
        $this->assertSame(3, $receipt->quantity);
        $this->assertSame('IRS-9', $receipt->documentNo);
        $this->assertSame('Dental Tedarik A.Ş.', $receipt->senderName);
    }

    public function test_item_reads_uts_json_codes(): void
    {
        $item = UtsItem::fromArray([
            'UTP' => 'TIBBI_CIHAZ', 'UNO' => '08699999999990', 'LNO' => 'L1', 'ADT' => 5,
            'SKT' => '2028-01-31', 'UAK' => 'LOT', 'UDI' => 'UDI-1', 'MME' => 'Marka Model',
        ]);

        $this->assertSame('TIBBI_CIHAZ', $item->productType);
        $this->assertSame('2028-01-31', $item->expiryDate);
        $this->assertSame('LOT', $item->tracking);
    }

    public function test_fake_client_returns_configured_data_and_pages(): void
    {
        $rows = array_map(fn ($n) => PendingReceipt::fromArray(['UNO' => "U{$n}"]), range(1, 12));
        $client = new FakeUtsClient(pending: $rows);

        $first = $client->pendingReceipts(null, 0);
        $second = $client->pendingReceipts(null, 1);

        $this->assertCount(10, $first->items);
        $this->assertTrue($first->hasMore);
        $this->assertCount(2, $second->items);
        $this->assertFalse($second->hasMore);
    }

    public function test_fake_client_can_fail(): void
    {
        $this->expectException(UtsException::class);

        (new FakeUtsClient(failure: new UtsException('ÜTS şu an yanıt vermiyor.')))->lookup('1');
    }

    public function test_unconfigured_client_explains_missing_token(): void
    {
        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('ÜTS bağlantısı kurulmamış');

        (new UnconfiguredUtsClient)->pendingReceipts();
    }
}
```

- [ ] **Step 2: Çalıştır, düştüğünü gör**

Run: `php artisan test tests/Feature/Uts/UtsClientContractTest.php`
Expected: FAIL (`Class ... PendingReceipt not found`)

- [ ] **Step 3: Gerçekle**

`config/uts.php`:

```php
<?php

return [

    /*
    | ÜTS (Ürün Takip Sistemi) web servisleri — "Takip ve İzleme Web Servis
    | Tanımları Dokümanı" rev. 1.47. Token organizasyon başına veritabanında
    | (şifreli) tutulur; burada yalnızca adresler vardır.
    */
    'urls' => [
        'test' => env('UTS_TEST_URL', 'https://utstest.saglik.gov.tr'),
        'production' => env('UTS_PRODUCTION_URL', 'https://utsuygulama.saglik.gov.tr'),
    ],

    'timeout' => (int) env('UTS_TIMEOUT', 15),

];
```

`app/Domain/Uts/Exceptions/UtsException.php`:

```php
<?php

namespace App\Domain\Uts\Exceptions;

use RuntimeException;

/**
 * ÜTS'ye ulaşılamadı, yapılandırılmamış veya ÜTS bir hata döndürdü. Mesaj
 * kullanıcıya gösterilir; token hiçbir zaman mesaja girmez.
 */
class UtsException extends RuntimeException
{
    //
}
```

`app/Domain/Uts/Exceptions/UtsAuthException.php`:

```php
<?php

namespace App\Domain\Uts\Exceptions;

/**
 * Token geçersiz, süresi dolmuş veya yetkisiz (HTTP 401/403).
 */
class UtsAuthException extends UtsException
{
    //
}
```

`app/Domain/Uts/Support/PendingReceipt.php`:

```php
<?php

namespace App\Domain\Uts\Support;

/**
 * Kuruma yapılan, henüz kabul edilmemiş bir Verme bildirimi satırı
 * (doküman 3.4.7.3, Tablo 64).
 */
final readonly class PendingReceipt
{
    public function __construct(
        public ?int $senderCode,
        public string $uno,
        public ?string $lot,
        public ?string $serial,
        public ?int $quantity,
        public ?string $notificationId,
        public ?string $documentNo,
        public ?string $notifiedAt,
        public ?string $senderName,
        public ?string $brand,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            isset($row['GKK']) ? (int) $row['GKK'] : null,
            (string) ($row['UNO'] ?? ''),
            self::text($row['LNO'] ?? null),
            self::text($row['SNO'] ?? null),
            isset($row['ADT']) ? (int) $row['ADT'] : null,
            self::text($row['BID'] ?? null),
            self::text($row['BNO'] ?? null),
            self::text($row['BZA'] ?? null),
            self::text($row['GKU'] ?? null),
            self::text($row['MME'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
```

`app/Domain/Uts/Support/PendingReceiptPage.php`:

```php
<?php

namespace App\Domain\Uts\Support;

/**
 * ÜTS sorgu sonuçlarını en fazla 10'luk sayfalarla döndürür (SAN = sayfa no).
 */
final readonly class PendingReceiptPage
{
    public const PAGE_SIZE = 10;

    /**
     * @param  list<PendingReceipt>  $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public bool $hasMore,
    ) {}
}
```

`app/Domain/Uts/Support/UtsItem.php`:

```php
<?php

namespace App\Domain\Uts\Support;

/**
 * Kurum üzerindeki tekil ürün kaydı (doküman 3.4.1.3, Tablo 51).
 */
final readonly class UtsItem
{
    public function __construct(
        public ?string $productType,
        public string $uno,
        public ?string $lot,
        public ?string $serial,
        public ?int $quantity,
        public ?string $productionDate,
        public ?string $expiryDate,
        public ?string $importDate,
        public ?int $manufacturerCode,
        public ?string $tracking,
        public ?string $udi,
        public ?string $brand,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $text = fn (string $key): ?string => isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '' ? trim((string) $row[$key]) : null;

        return new self(
            $text('UTP'),
            (string) ($row['UNO'] ?? ''),
            $text('LNO'),
            $text('SNO'),
            isset($row['ADT']) ? (int) $row['ADT'] : null,
            $text('URT'),
            $text('SKT'),
            $text('ITT'),
            isset($row['UIK']) ? (int) $row['UIK'] : null,
            $text('UAK'),
            $text('UDI'),
            $text('MME'),
        );
    }
}
```

`app/Domain/Uts/Contracts/UtsClient.php`:

```php
<?php

namespace App\Domain\Uts\Contracts;

use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceiptPage;
use App\Domain\Uts\Support\UtsItem;

/**
 * ÜTS web servisleri (yalnızca okuma). Uygulamalar: HttpUtsClient (gerçek),
 * FakeUtsClient (test), UnconfiguredUtsClient (token yok). Yazma bildirimleri
 * (Alma, Kullanım...) Aşama B'de bu arayüze eklenecek.
 */
interface UtsClient
{
    /**
     * Kuruma yapılmış, kabul bekleyen Verme bildirimleri (3.4.7).
     *
     * @throws UtsException
     */
    public function pendingReceipts(?int $senderCode = null, int $page = 0): PendingReceiptPage;

    /**
     * Kurum üzerindeki tekil ürünü ürün no + lot/seri ile sorgular (3.4.1).
     *
     * @return list<UtsItem>
     *
     * @throws UtsException
     */
    public function lookup(string $uno, ?string $lot = null, ?string $serial = null): array;

    public function name(): string;
}
```

`app/Domain/Uts/Clients/UnconfiguredUtsClient.php`:

```php
<?php

namespace App\Domain\Uts\Clients;

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceiptPage;

/**
 * Organizasyon ÜTS token'ı girmemişse kullanılır: hiçbir dış çağrı yapmaz.
 */
class UnconfiguredUtsClient implements UtsClient
{
    public function pendingReceipts(?int $senderCode = null, int $page = 0): PendingReceiptPage
    {
        $this->fail();
    }

    public function lookup(string $uno, ?string $lot = null, ?string $serial = null): array
    {
        $this->fail();
    }

    public function name(): string
    {
        return 'Yapılandırılmamış';
    }

    private function fail(): never
    {
        throw new UtsException('ÜTS bağlantısı kurulmamış. Ayarlar ekranından ÜTS sistem token\'ını girin.');
    }
}
```

`app/Domain/Uts/Clients/FakeUtsClient.php`:

```php
<?php

namespace App\Domain\Uts\Clients;

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceipt;
use App\Domain\Uts\Support\PendingReceiptPage;
use App\Domain\Uts\Support\UtsItem;

/**
 * Testler ve geliştirme için bellek içi ÜTS. Çağrıları kaydeder.
 */
class FakeUtsClient implements UtsClient
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    /**
     * @param  list<PendingReceipt>  $pending
     * @param  list<UtsItem>  $items
     */
    public function __construct(
        private readonly array $pending = [],
        private readonly array $items = [],
        private readonly ?UtsException $failure = null,
    ) {}

    public function pendingReceipts(?int $senderCode = null, int $page = 0): PendingReceiptPage
    {
        $this->calls[] = ['pendingReceipts', [$senderCode, $page]];

        if ($this->failure) {
            throw $this->failure;
        }

        $rows = array_values(array_filter($this->pending, fn (PendingReceipt $r) => $senderCode === null || $r->senderCode === $senderCode));
        $slice = array_slice($rows, $page * PendingReceiptPage::PAGE_SIZE, PendingReceiptPage::PAGE_SIZE);

        return new PendingReceiptPage($slice, $page, count($rows) > ($page + 1) * PendingReceiptPage::PAGE_SIZE);
    }

    public function lookup(string $uno, ?string $lot = null, ?string $serial = null): array
    {
        $this->calls[] = ['lookup', [$uno, $lot, $serial]];

        if ($this->failure) {
            throw $this->failure;
        }

        return array_values(array_filter($this->items, fn (UtsItem $i) => $i->uno === $uno
            && ($lot === null || $i->lot === $lot)
            && ($serial === null || $i->serial === $serial)));
    }

    public function name(): string
    {
        return 'Sahte ÜTS';
    }
}
```

- [ ] **Step 4: Testler yeşil**

Run: `php artisan test tests/Feature/Uts/UtsClientContractTest.php`
Expected: PASS (5 test)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add config/uts.php app/Domain/Uts tests/Feature/Uts
git commit -m "feat: ÜTS istemci arayüzü, değer nesneleri, sahte ve yapılandırılmamış istemci — Aşama 33 (1/6)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Gerçek HTTP istemcisi

**Files:**
- Create: `app/Domain/Uts/Clients/HttpUtsClient.php`
- Test: `tests/Feature/Uts/HttpUtsClientTest.php`

**Interfaces:**
- Consumes: `UtsClient`, `PendingReceipt::fromArray`, `UtsItem::fromArray`, `UtsException`, `UtsAuthException`.
- Produces: `new HttpUtsClient(string $token, string $baseUrl, int $timeout)`.

Yanıt biçimi varsayımı (doküman Tablo 51/64'e göre): `SNC` bir **liste**, hatada `MSJ` listesi (`TIP`, `MET`, `KOD`) gelir. Gerçek test ortamında doğrulanacak (Task 6).

- [ ] **Step 1: Failing test yaz**

```php
<?php

namespace Tests\Feature\Uts;

use App\Domain\Uts\Clients\HttpUtsClient;
use App\Domain\Uts\Exceptions\UtsAuthException;
use App\Domain\Uts\Exceptions\UtsException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpUtsClientTest extends TestCase
{
    private function client(): HttpUtsClient
    {
        return new HttpUtsClient('gizli-token', 'https://utstest.saglik.gov.tr', 5);
    }

    public function test_pending_receipts_posts_to_the_documented_endpoint_with_token_header(): void
    {
        Http::fake(['utstest.saglik.gov.tr/*' => Http::response(['SNC' => [
            ['GKK' => 7, 'UNO' => '08699999999990', 'LNO' => 'L1', 'ADT' => 2, 'BNO' => 'IRS-1'],
        ]])]);

        $page = $this->client()->pendingReceipts(7, 0);

        $this->assertCount(1, $page->items);
        $this->assertSame('IRS-1', $page->items[0]->documentNo);
        $this->assertFalse($page->hasMore);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://utstest.saglik.gov.tr/UTS/uh/rest/bildirim/alma/bekleyenler/sorgula'
            && $r->header('utsToken') === ['gizli-token']
            && $r['GKK'] === 7
            && $r['SAN'] === 0);
    }

    public function test_full_page_means_more_results(): void
    {
        Http::fake(['*' => Http::response(['SNC' => array_map(fn ($n) => ['UNO' => "U{$n}"], range(1, 10))])]);

        $this->assertTrue($this->client()->pendingReceipts(null, 0)->hasMore);
    }

    public function test_pending_receipts_omits_sender_when_not_given(): void
    {
        Http::fake(['*' => Http::response(['SNC' => []])]);

        $this->client()->pendingReceipts();

        Http::assertSent(fn (Request $r) => ! array_key_exists('GKK', $r->data()));
    }

    public function test_lookup_posts_uno_lot_and_serial(): void
    {
        Http::fake(['*' => Http::response(['SNC' => [
            ['UTP' => 'TIBBI_CIHAZ', 'UNO' => '08699999999990', 'LNO' => 'L1', 'SKT' => '2028-01-31', 'UAK' => 'LOT'],
        ]])]);

        $items = $this->client()->lookup('08699999999990', 'L1');

        $this->assertCount(1, $items);
        $this->assertSame('2028-01-31', $items[0]->expiryDate);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/UTS/uh/rest/tekilUrun/sorgula')
            && $r['UNO'] === '08699999999990' && $r['LNO'] === 'L1' && ! isset($r['SNO']) && $r['SAN'] === 0);
    }

    public function test_uts_error_message_is_shown_to_the_user(): void
    {
        Http::fake(['*' => Http::response(['MSJ' => [['TIP' => 'HATA', 'MET' => 'Ürün bulunamadı.', 'KOD' => 1234]]])]);

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('Ürün bulunamadı.');

        $this->client()->lookup('1');
    }

    public function test_info_messages_do_not_fail(): void
    {
        Http::fake(['*' => Http::response(['SNC' => [], 'MSJ' => [['TIP' => 'BILGI', 'MET' => 'Kayıt yok.', 'KOD' => 1]]])]);

        $this->assertSame([], $this->client()->lookup('1'));
    }

    public function test_unauthorized_token_raises_auth_exception_without_leaking_it(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        try {
            $this->client()->pendingReceipts();
            $this->fail('Exception bekleniyordu.');
        } catch (UtsAuthException $e) {
            $this->assertStringNotContainsString('gizli-token', $e->getMessage());
            $this->assertStringContainsString('token', $e->getMessage());
        }
    }

    public function test_connection_failure_is_reported_in_turkish(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('ÜTS şu an yanıt vermiyor');

        $this->client()->pendingReceipts();
    }
}
```

- [ ] **Step 2: Düştüğünü gör**

Run: `php artisan test tests/Feature/Uts/HttpUtsClientTest.php`
Expected: FAIL (`Class HttpUtsClient not found`)

- [ ] **Step 3: Gerçekle**

```php
<?php

namespace App\Domain\Uts\Clients;

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsAuthException;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceipt;
use App\Domain\Uts\Support\PendingReceiptPage;
use App\Domain\Uts\Support\UtsItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ÜTS REST servisleri (Takip ve İzleme Web Servis Tanımları rev. 1.47).
 * Kimlik doğrulama her istekte `utsToken` başlığıdır (doküman 2.1). Token
 * günlüğe ve hata mesajlarına yazılmaz.
 */
class HttpUtsClient implements UtsClient
{
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    public function name(): string
    {
        return 'ÜTS';
    }

    public function pendingReceipts(?int $senderCode = null, int $page = 0): PendingReceiptPage
    {
        $body = ['SAN' => $page];

        if ($senderCode !== null) {
            $body['GKK'] = $senderCode;
        }

        $rows = $this->rows($this->post('/UTS/uh/rest/bildirim/alma/bekleyenler/sorgula', $body));
        $items = array_map(fn (array $row) => PendingReceipt::fromArray($row), $rows);

        return new PendingReceiptPage($items, $page, count($items) >= PendingReceiptPage::PAGE_SIZE);
    }

    public function lookup(string $uno, ?string $lot = null, ?string $serial = null): array
    {
        $body = ['UNO' => $uno, 'SAN' => 0];

        if ($lot !== null && $lot !== '') {
            $body['LNO'] = $lot;
        }

        if ($serial !== null && $serial !== '') {
            $body['SNO'] = $serial;
        }

        return array_map(fn (array $row) => UtsItem::fromArray($row), $this->rows($this->post('/UTS/uh/rest/tekilUrun/sorgula', $body)));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withHeaders(['utsToken' => $this->token])
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($path, $body);
        } catch (ConnectionException $e) {
            Log::warning('ÜTS bağlantı hatası', ['path' => $path, 'error' => $e->getMessage()]);

            throw new UtsException('ÜTS şu an yanıt vermiyor. Lütfen biraz sonra tekrar deneyin.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new UtsAuthException('ÜTS token\'ı geçersiz veya süresi dolmuş. Ayarlar ekranından yeni bir sistem token\'ı girin.');
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        $this->throwOnErrorMessages($json, $response, $path);

        if ($response->failed()) {
            Log::warning('ÜTS hata yanıtı', ['path' => $path, 'status' => $response->status()]);

            throw new UtsException('ÜTS isteği reddetti veya şu an cevap veremiyor. Lütfen biraz sonra tekrar deneyin.');
        }

        return $json;
    }

    /**
     * MSJ listesindeki HATA tipli ilk mesaj kullanıcıya gösterilir (doküman 3.3.1).
     *
     * @param  array<string, mixed>  $json
     */
    private function throwOnErrorMessages(array $json, Response $response, string $path): void
    {
        foreach ((array) ($json['MSJ'] ?? []) as $message) {
            if (is_array($message) && ($message['TIP'] ?? null) === 'HATA') {
                Log::warning('ÜTS hata mesajı', ['path' => $path, 'status' => $response->status(), 'kod' => $message['KOD'] ?? null, 'mesaj' => $message['MET'] ?? null]);

                throw new UtsException((string) ($message['MET'] ?? 'ÜTS bir hata döndürdü.'));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function rows(array $json): array
    {
        return array_values(array_filter((array) ($json['SNC'] ?? []), 'is_array'));
    }
}
```

- [ ] **Step 4: Yeşil**

Run: `php artisan test tests/Feature/Uts`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Domain/Uts tests/Feature/Uts
git commit -m "feat: ÜTS HTTP istemcisi — utsToken başlığı, MSJ hata eşleme, kabul bekleyenler ve tekil ürün sorgusu — Aşama 33 (2/6)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Token saklama, yetki ve istemci bağlama

**Files:**
- Create: `database/migrations/2026_10_01_090000_create_uts_connections_table.php`, `app/Domain/Uts/Models/UtsConnection.php`, `app/Domain/Uts/Services/UtsConnectionService.php`
- Modify: `app/Providers/AppServiceProvider.php` (register), `app/Providers/AuthServiceProvider.php` (boot)
- Test: `tests/Feature/Uts/UtsConnectionTest.php`

**Interfaces:**
- Consumes: `HttpUtsClient`, `UnconfiguredUtsClient`, `config('uts')`.
- Produces: `UtsConnection` (`organization_id`, `environment` `'test'|'production'`, `token` şifreli, `last_verified_at`); `UtsConnectionService::save(string $environment, string $token): UtsConnection`, `::test(UtsClient $client): void`, `::remove(): void`, `::current(): ?UtsConnection`; `app(UtsClient::class)` oturumdaki organizasyonun bağlantısıyla `HttpUtsClient`, yoksa `UnconfiguredUtsClient` döner; gate `uts_connection.update`.

- [ ] **Step 1: Failing test yaz**

```php
<?php

namespace Tests\Feature\Uts;

use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Clients\HttpUtsClient;
use App\Domain\Uts\Clients\UnconfiguredUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Models\UtsConnection;
use App\Domain\Uts\Services\UtsConnectionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UtsConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $name = 'Klinik'): Organization
    {
        return Organization::create(['name' => $name, 'status' => 'active', 'plan' => 'starter']);
    }

    private function admin(Organization $org): User
    {
        return User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
    }

    public function test_token_is_stored_encrypted(): void
    {
        $admin = $this->admin($this->org());
        $this->actingAs($admin);

        app(UtsConnectionService::class)->save('test', 'cok-gizli-token');

        $raw = DB::table('uts_connections')->value('token');
        $this->assertStringNotContainsString('cok-gizli-token', $raw);
        $this->assertSame('cok-gizli-token', UtsConnection::first()->token);
    }

    public function test_saving_again_replaces_the_token_and_resets_verification(): void
    {
        $this->actingAs($this->admin($this->org()));
        $service = app(UtsConnectionService::class);

        $service->save('test', 'ilk');
        UtsConnection::first()->update(['last_verified_at' => now()]);
        $service->save('production', 'ikinci');

        $this->assertSame(1, UtsConnection::count());
        $connection = UtsConnection::first();
        $this->assertSame('production', $connection->environment);
        $this->assertSame('ikinci', $connection->token);
        $this->assertNull($connection->last_verified_at);
    }

    public function test_client_is_unconfigured_without_a_token_and_http_with_one(): void
    {
        $this->actingAs($this->admin($this->org()));
        $this->assertInstanceOf(UnconfiguredUtsClient::class, app(UtsClient::class));

        app(UtsConnectionService::class)->save('test', 'tok');

        $this->assertInstanceOf(HttpUtsClient::class, app(UtsClient::class));
    }

    public function test_another_organizations_token_is_never_used(): void
    {
        $a = $this->org('A');
        $this->actingAs($this->admin($a));
        app(UtsConnectionService::class)->save('test', 'a-token');

        $this->actingAs($this->admin($this->org('B')));

        $this->assertNull(app(UtsConnectionService::class)->current());
        $this->assertInstanceOf(UnconfiguredUtsClient::class, app(UtsClient::class));
    }

    public function test_only_admin_may_manage_the_connection_and_read_only_org_may_not(): void
    {
        $org = $this->org();
        $staff = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_STAFF, 'status' => 'active']);

        $this->assertTrue(Gate::forUser($this->admin($org))->allows('uts_connection.update'));
        $this->assertFalse(Gate::forUser($staff)->allows('uts_connection.update'));

        $org->update(['status' => 'read_only']);
        $this->assertFalse(Gate::forUser($this->admin($org->fresh()))->allows('uts_connection.update'));
    }

    public function test_remove_deletes_the_connection(): void
    {
        $this->actingAs($this->admin($this->org()));
        $service = app(UtsConnectionService::class);
        $service->save('test', 'tok');

        $service->remove();

        $this->assertSame(0, UtsConnection::count());
    }
}
```

> Not: `User::ROLE_STAFF` ve organizasyon "salt-okunur" durum değeri (`read_only`) kodda farklı adlandırılmış olabilir; `app/Models/User.php` ve `app/Domain/Organization/Support/OrganizationStatus.php` dosyalarına bakıp testi gerçek sabit/değerle düzelt (diğer testlerde aynı kullanım var, örn. `tests/Feature/Platform`).

- [ ] **Step 2: Düştüğünü gör**

Run: `php artisan test tests/Feature/Uts/UtsConnectionTest.php`
Expected: FAIL (`Class UtsConnection not found`)

- [ ] **Step 3: Gerçekle**

Migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uts_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('environment', 16)->default('test');
            $table->text('token');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uts_connections');
    }
};
```

`app/Domain/Uts/Models/UtsConnection.php`:

```php
<?php

namespace App\Domain\Uts\Models;

use App\Domain\Organization\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Organizasyonun ÜTS sistem token'ı. Token APP_KEY ile şifreli saklanır ve
 * arayüze/günlüğe/denetim kaydına yazılmaz (bu yüzden Auditable kullanılmaz).
 */
class UtsConnection extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'environment', 'token', 'last_verified_at'];

    protected $hidden = ['token'];

    protected $casts = [
        'token' => 'encrypted',
        'last_verified_at' => 'datetime',
    ];
}
```

`app/Domain/Uts/Services/UtsConnectionService.php`:

```php
<?php

namespace App\Domain\Uts\Services;

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Models\UtsConnection;

class UtsConnectionService
{
    public function current(): ?UtsConnection
    {
        return UtsConnection::first();
    }

    public function save(string $environment, string $token): UtsConnection
    {
        return UtsConnection::updateOrCreate(
            ['organization_id' => auth()->user()->organization_id],
            ['environment' => $environment, 'token' => trim($token), 'last_verified_at' => null],
        );
    }

    /**
     * Bağlantıyı en ucuz sorguyla dener (kabul bekleyenlerin ilk sayfası);
     * başarılıysa doğrulama zamanını işler, değilse UtsException fırlar.
     */
    public function test(UtsClient $client): void
    {
        $client->pendingReceipts(null, 0);

        $this->current()?->update(['last_verified_at' => now()]);
    }

    public function remove(): void
    {
        $this->current()?->delete();
    }
}
```

`AppServiceProvider::register()` içine (Gemini bağlamasından sonra; `use` satırlarını ekle: `App\Domain\Uts\Clients\HttpUtsClient`, `App\Domain\Uts\Clients\UnconfiguredUtsClient`, `App\Domain\Uts\Contracts\UtsClient`, `App\Domain\Uts\Models\UtsConnection`):

```php
        // Aşama 33: ÜTS istemcisi arayüz arkasında; token organizasyon başına
        // veritabanında (şifreli). Token yoksa hiçbir dış çağrı yapılmaz.
        $this->app->bind(UtsClient::class, function () {
            $connection = auth()->check() ? UtsConnection::first() : null;

            return $connection
                ? new HttpUtsClient(
                    $connection->token,
                    (string) config('uts.urls.'.$connection->environment, config('uts.urls.test')),
                    (int) config('uts.timeout'),
                )
                : new UnconfiguredUtsClient;
        });
```

`AuthServiceProvider::boot()` içinde `ownership.update` satırının altına:

```php
        // ÜTS token'ı (Aşama 33) yalnızca Ana Klinik Sahibi'nin (Admin) işidir;
        // ".update" soneki salt-okunur organizasyonda kapatır.
        Gate::define('uts_connection.update', fn (User $user) => false);
```

- [ ] **Step 4: Yeşil**

Run: `php artisan test tests/Feature/Uts`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add database app tests
git commit -m "feat: ÜTS token'ı organizasyon başına şifreli saklanır, istemci bağlaması ve yetki — Aşama 33 (3/6)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Ayarlar ekranında ÜTS Bağlantısı kartı

**Files:**
- Modify: `resources/views/pages/settings/⚡index.blade.php`
- Test: `tests/Feature/Uts/UtsSettingsScreenTest.php`

**Interfaces:**
- Consumes: `UtsConnectionService` (`current`, `save`, `test`, `remove`), `UtsClient`, `UtsException`, gate `uts_connection.update`, `route('settings.index')`.
- Produces: Livewire eylemleri `saveUts()`, `testUts()`, `removeUts()`; özellikler `utsEnvironment`, `utsToken`.

- [ ] **Step 1: Failing test yaz**

```php
<?php

namespace Tests\Feature\Uts;

use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsAuthException;
use App\Domain\Uts\Models\UtsConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UtsSettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    public function test_admin_saves_the_token_and_it_is_never_rendered_back(): void
    {
        Livewire::test('pages::settings.index')
            ->set('utsEnvironment', 'test')
            ->set('utsToken', 'cok-gizli-token')
            ->call('saveUts')
            ->assertHasNoErrors()
            ->assertSet('utsToken', '')
            ->assertDontSee('cok-gizli-token')
            ->assertSee('Token kayıtlı');

        $this->assertSame('cok-gizli-token', UtsConnection::first()->token);
    }

    public function test_token_is_required_and_environment_is_validated(): void
    {
        Livewire::test('pages::settings.index')
            ->set('utsEnvironment', 'sahte')
            ->set('utsToken', '')
            ->call('saveUts')
            ->assertHasErrors(['utsEnvironment', 'utsToken']);
    }

    public function test_connection_test_marks_verification_on_success(): void
    {
        UtsConnection::create(['organization_id' => $this->admin->organization_id, 'environment' => 'test', 'token' => 'tok']);
        $this->app->bind(UtsClient::class, fn () => new FakeUtsClient);

        Livewire::test('pages::settings.index')->call('testUts')->assertSee('Bağlantı başarılı');

        $this->assertNotNull(UtsConnection::first()->last_verified_at);
    }

    public function test_connection_test_shows_the_error_and_does_not_mark_verification(): void
    {
        UtsConnection::create(['organization_id' => $this->admin->organization_id, 'environment' => 'test', 'token' => 'tok']);
        $this->app->bind(UtsClient::class, fn () => new FakeUtsClient(failure: new UtsAuthException('ÜTS token\'ı geçersiz veya süresi dolmuş.')));

        Livewire::test('pages::settings.index')->call('testUts')->assertSee('ÜTS token\'ı geçersiz');

        $this->assertNull(UtsConnection::first()->last_verified_at);
    }

    public function test_remove_deletes_the_token(): void
    {
        UtsConnection::create(['organization_id' => $this->admin->organization_id, 'environment' => 'test', 'token' => 'tok']);

        Livewire::test('pages::settings.index')->call('removeUts');

        $this->assertSame(0, UtsConnection::count());
    }

    public function test_staff_cannot_manage_the_token(): void
    {
        $staff = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => User::ROLE_STAFF, 'status' => 'active']);
        $this->actingAs($staff);

        Livewire::test('pages::settings.index')
            ->set('utsToken', 'x')
            ->call('saveUts')
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Düştüğünü gör**

Run: `php artisan test tests/Feature/Uts/UtsSettingsScreenTest.php`
Expected: FAIL (`Property [$utsEnvironment] not found`)

- [ ] **Step 3: Gerçekle**

`⚡index.blade.php` PHP bölümüne (mevcut `use` satırlarına `UtsConnectionService`, `UtsClient`, `UtsException` ekle):

```php
    public string $utsEnvironment = 'test';

    public string $utsToken = '';

    public ?string $utsMessage = null;

    public ?string $utsError = null;

    public function saveUts(UtsConnectionService $uts): void
    {
        Gate::authorize('uts_connection.update');

        $validated = $this->validate([
            'utsEnvironment' => ['required', Rule::in(['test', 'production'])],
            'utsToken' => ['required', 'string', 'max:2000'],
        ]);

        $uts->save($validated['utsEnvironment'], $validated['utsToken']);

        // Token geri gösterilmez; alan temizlenir.
        $this->reset('utsToken');
        $this->utsMessage = 'Token kaydedildi. "Bağlantıyı dene" ile doğrulayın.';
        $this->utsError = null;
    }

    public function testUts(UtsConnectionService $uts, UtsClient $client): void
    {
        Gate::authorize('uts_connection.update');

        $this->utsMessage = $this->utsError = null;

        try {
            $uts->test($client);
            $this->utsMessage = 'Bağlantı başarılı.';
        } catch (UtsException $e) {
            $this->utsError = $e->getMessage();
        }
    }

    public function removeUts(UtsConnectionService $uts): void
    {
        Gate::authorize('uts_connection.update');

        $uts->remove();
        $this->utsMessage = 'ÜTS bağlantısı kaldırıldı.';
        $this->utsError = null;
    }
```

`mount()` içine `if ($connection = app(UtsConnectionService::class)->current()) { $this->utsEnvironment = $connection->environment; }`; `with()` dizisine `'utsConnection' => app(UtsConnectionService::class)->current()`.

Blade'de mevcut `</form>` sonrasına ayrı kart (kendi `<form>`'u; mevcut ayar formunun içine **girmez**):

```blade
    <section class="max-w-2xl mt-8 border border-line rounded-lg bg-surface p-5">
        <h2 class="text-[15px] font-medium text-ink">ÜTS Bağlantısı</h2>
        <p class="text-[13px] text-ink-muted mt-1 mb-4">Kabul bekleyen bildirimleri görmek ve lot/seri doğrulamak için kurumunuzun ÜTS sistem token'ı. Token'ı kurum yetkilisi ÜTS portalında e-imzayla üretir (Kullanıcı → Sistem Kullanıcısı Tanımlama İşlemleri). Bu aşamada ÜTS'ye bildirim gönderilmez.</p>

        @if ($utsMessage) <div class="mb-3 rounded-md bg-brand-100 border border-brand-500/20 text-brand-600 text-[13px] px-4 py-3">{{ $utsMessage }}</div> @endif
        @if ($utsError) <div class="mb-3 rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">{{ $utsError }}</div> @endif

        @if ($utsConnection)
            <p class="text-[13px] text-ink mb-3">
                Token kayıtlı ({{ $utsConnection->environment === 'production' ? 'Gerçek ortam' : 'Test ortamı' }})
                · {{ $utsConnection->last_verified_at ? 'son doğrulama: '.$utsConnection->last_verified_at->format('d.m.Y H:i') : 'henüz doğrulanmadı' }}
            </p>
        @endif

        @can('uts_connection.update')
            <form wire:submit="saveUts" class="space-y-3">
                <select wire:model="utsEnvironment" class="border border-line rounded-md px-3 py-2 text-[14px] bg-surface">
                    <option value="test">Test ortamı (utstest.saglik.gov.tr)</option>
                    <option value="production">Gerçek ortam (utsuygulama.saglik.gov.tr)</option>
                </select>
                @error('utsEnvironment') <span class="text-status-critical text-[12px] block">{{ $message }}</span> @enderror
                <input type="password" wire:model="utsToken" autocomplete="off" placeholder="ÜTS sistem token'ı" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                @error('utsToken') <span class="text-status-critical text-[12px] block">{{ $message }}</span> @enderror
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">Token'ı Kaydet</button>
                    @if ($utsConnection)
                        <button type="button" wire:click="testUts" class="border border-line rounded-md px-4 py-2 text-[14px] text-ink hover:bg-surface-muted">Bağlantıyı dene</button>
                        <button type="button" wire:click="removeUts" wire:confirm="ÜTS bağlantısı kaldırılsın mı?" class="border border-line rounded-md px-4 py-2 text-[14px] text-status-critical hover:bg-red-50">Kaldır</button>
                    @endif
                </div>
            </form>
        @endcan
    </section>
```

- [ ] **Step 4: Yeşil + gözle doğrula**

Run: `php artisan test tests/Feature/Uts tests/Feature/Organization/ExpiredLotPolicyTest.php`
Expected: PASS (mevcut ayar testi bozulmamalı)

Sonra `npm run build`, yerel sunucuda Ayarlar sayfasını **tarayıcıda aç**, kartı ekran görüntüsüyle kontrol et (`frontend-design` skill'i; sınıf adları projede yoksa — örn. `bg-surface-muted` — mevcut ayar sayfasındaki eşdeğerleriyle değiştir).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add resources tests
git commit -m "feat: Ayarlar ekranında ÜTS bağlantı kartı — token kaydet, bağlantıyı dene, kaldır — Aşama 33 (4/6)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Eşleştirme servisi ve Kabul Bekleyenler ekranı

**Files:**
- Create: `app/Domain/Uts/Services/UtsMatcher.php`, `resources/views/pages/uts/⚡pending.blade.php`
- Modify: `routes/web.php` (tenant grubuna), `resources/views/layouts/authenticated.blade.php` (menü)
- Test: `tests/Feature/Uts/UtsMatcherTest.php`, `tests/Feature/Uts/UtsPendingScreenTest.php`

**Interfaces:**
- Consumes: `UtsClient`, `PendingReceipt`, `UtsItem`, `Gs1::normalizeGtin`, `Product` (`gtin`, `barcode`), `StockLot` (`lot_no`, `product_id`, `expiry_date`), `StockSerial` (`serial_no`, `product_id`), `PurchaseReceipt` (`invoice_number`, `delivery_note_number`, `order`).
- Produces: `UtsMatcher::match(string $uno, ?string $lot, ?string $serial, ?string $documentNo = null): array{product: ?Product, lot: ?StockLot, serial: ?StockSerial, receipt: ?PurchaseReceipt}`; rota `uts.pending` (`/uts/kabul-bekleyenler`).

- [ ] **Step 1: Matcher için failing test**

```php
<?php

namespace Tests\Feature\Uts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Uts\Services\UtsMatcher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UtsMatcherTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Merkez', 'status' => 'active']);
        $this->warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $this->product = Product::create(['organization_id' => $org->id, 'name' => 'Implant', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '08699999999990', 'tracks_serials' => true]);
        $this->actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
    }

    public function test_matches_product_by_gtin_even_without_leading_zero(): void
    {
        $match = app(UtsMatcher::class)->match('8699999999990', null, null);

        $this->assertTrue($match['product']?->is($this->product));
    }

    public function test_unknown_product_returns_nulls(): void
    {
        $match = app(UtsMatcher::class)->match('99999999999999', 'L1', 'S1');

        $this->assertNull($match['product']);
        $this->assertNull($match['lot']);
        $this->assertNull($match['serial']);
    }

    public function test_finds_lot_and_serial_of_the_product(): void
    {
        app(StockMovementService::class)->in($this->product, $this->warehouse, 1, ['lot_no' => 'L1', 'expiry_date' => '2028-01-31', 'serial_numbers' => ['S1']]);

        $match = app(UtsMatcher::class)->match('08699999999990', 'L1', 'S1');

        $this->assertSame('L1', $match['lot']?->lot_no);
        $this->assertSame('S1', $match['serial']?->serial_no);
    }
}
```

> Not: `StockMovementService::in` seri parametresinin gerçek anahtar adını (`serial_numbers`/`serials`) `tests/Feature/Medical/SerialTrackingServiceTest.php`'ten kopyala; yoksa seriyi o testteki gibi oluştur.

- [ ] **Step 2: Düştüğünü gör**

Run: `php artisan test tests/Feature/Uts/UtsMatcherTest.php`
Expected: FAIL (`Class UtsMatcher not found`)

- [ ] **Step 3: Matcher'ı yaz**

```php
<?php

namespace App\Domain\Uts\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\Gs1;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Stock\Models\StockLot;
use App\Domain\Stock\Models\StockSerial;

/**
 * ÜTS kaydını yerel veriyle eşleştirir. Hiçbir şey yazmaz. Organizasyon
 * izolasyonu: Product ve StockSerial BelongsToOrganization kapsamındadır;
 * StockLot ürün üzerinden, PurchaseReceipt sipariş üzerinden kapsanır.
 */
class UtsMatcher
{
    /**
     * @return array{product: ?Product, lot: ?StockLot, serial: ?StockSerial, receipt: ?PurchaseReceipt}
     */
    public function match(string $uno, ?string $lot, ?string $serial, ?string $documentNo = null): array
    {
        $gtin = Gs1::normalizeGtin($uno);

        $product = Product::query()
            ->where(fn ($query) => $query
                ->where('barcode', $uno)
                ->when($gtin, fn ($query) => $query->orWhere('gtin', $gtin)))
            ->first();

        $lotModel = $product && $lot
            ? StockLot::where('product_id', $product->id)->where('lot_no', $lot)->first()
            : null;

        $serialModel = $product && $serial
            ? StockSerial::where('product_id', $product->id)->where('serial_no', $serial)->first()
            : null;

        $receipt = $documentNo
            ? PurchaseReceipt::whereHas('order')
                ->where(fn ($query) => $query->where('invoice_number', $documentNo)->orWhere('delivery_note_number', $documentNo))
                ->latest('id')
                ->first()
            : null;

        return ['product' => $product, 'lot' => $lotModel, 'serial' => $serialModel, 'receipt' => $receipt];
    }
}
```

- [ ] **Step 4: Matcher yeşil**

Run: `php artisan test tests/Feature/Uts/UtsMatcherTest.php`
Expected: PASS

- [ ] **Step 5: Ekran için failing test**

```php
<?php

namespace Tests\Feature\Uts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\PendingReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UtsPendingScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $this->admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        Product::create(['organization_id' => $org->id, 'name' => 'Implant X', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '08699999999990']);
        $this->actingAs($this->admin);
    }

    private function fake(array $pending = [], ?UtsException $failure = null): void
    {
        $this->app->bind(UtsClient::class, fn () => new FakeUtsClient($pending, failure: $failure));
    }

    public function test_page_requires_login_and_stock_permission(): void
    {
        auth()->logout();

        $this->get('/uts/kabul-bekleyenler')->assertRedirect('/login');
    }

    public function test_lists_pending_receipts_with_match_badges(): void
    {
        $this->fake([
            PendingReceipt::fromArray(['GKK' => 7, 'UNO' => '08699999999990', 'LNO' => 'L1', 'ADT' => 2, 'BNO' => 'IRS-1', 'GKU' => 'Tedarik A.Ş.', 'MME' => 'Marka X']),
            PendingReceipt::fromArray(['GKK' => 7, 'UNO' => '09999999999994', 'LNO' => 'L9', 'ADT' => 1, 'BNO' => 'IRS-2', 'GKU' => 'Tedarik A.Ş.']),
        ]);

        Livewire::test('pages::uts.pending')
            ->assertSee('Tedarik A.Ş.')
            ->assertSee('IRS-1')
            ->assertSee('Ürün bizde var')
            ->assertSee('Ürün bizde yok');
    }

    public function test_shows_empty_state(): void
    {
        $this->fake([]);

        Livewire::test('pages::uts.pending')->assertSee('Kabul bekleyen bildirim yok');
    }

    public function test_shows_uts_error_instead_of_crashing(): void
    {
        $this->fake(failure: new UtsException('ÜTS şu an yanıt vermiyor.'));

        Livewire::test('pages::uts.pending')->assertSee('ÜTS şu an yanıt vermiyor.');
    }

    public function test_unconfigured_organization_is_pointed_to_settings(): void
    {
        Livewire::test('pages::uts.pending')->assertSee('ÜTS bağlantısı kurulmamış');
    }

    public function test_paging_moves_to_the_next_page(): void
    {
        $rows = array_map(fn ($n) => PendingReceipt::fromArray(['GKK' => 7, 'UNO' => "U{$n}", 'BNO' => "B{$n}"]), range(1, 12));
        $this->fake($rows);

        Livewire::test('pages::uts.pending')->assertSee('B1')->assertDontSee('B11')->call('nextPage')->assertSee('B11')->call('previousPage')->assertSee('B1');
    }
}
```

- [ ] **Step 6: Düştüğünü gör**

Run: `php artisan test tests/Feature/Uts/UtsPendingScreenTest.php`
Expected: FAIL (route/component yok)

- [ ] **Step 7: Sayfa, rota ve menü**

`routes/web.php` — `stock.serials` rotasının altına:

```php
    Route::livewire('/uts/kabul-bekleyenler', 'pages::uts.pending')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('uts.pending');

    Route::livewire('/uts/dogrula', 'pages::uts.verify')
        ->middleware(['auth', 'can:stock_movement.viewAny'])
        ->name('uts.verify');
```

`resources/views/layouts/authenticated.blade.php` — "İadeler" menü öğesinin altına (aynı `@can('stock_movement.viewAny')` deseni):

```blade
                    @can('stock_movement.viewAny')
                        <x-nav-link :href="route('uts.pending')" :active="request()->routeIs('uts.*')">
                            <x-slot:icon><path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6l-8-3Z" stroke-linejoin="round"/><path d="M9 12l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/></x-slot:icon>
                            ÜTS
                        </x-nav-link>
                    @endcan
```

`resources/views/pages/uts/⚡pending.blade.php`:

```blade
<?php

use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Services\UtsMatcher;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * ÜTS'de kuruma yapılmış ama henüz kabul edilmemiş Verme bildirimleri
 * (Aşama 33, salt okunur). Her satır yerel ürün/lot/seri/teslim kaydıyla
 * eşleştirilir; kabul (Alma bildirimi) Aşama B'dir.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    #[Url(as: 'gonderen', except: '')]
    public string $sender = '';

    public int $page = 0;

    public function updatedSender(): void
    {
        $this->page = 0;
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function previousPage(): void
    {
        $this->page = max(0, $this->page - 1);
    }

    public function with(UtsClient $client, UtsMatcher $matcher): array
    {
        $senderCode = ctype_digit(trim($this->sender)) ? (int) trim($this->sender) : null;

        try {
            $result = $client->pendingReceipts($senderCode, $this->page);
        } catch (UtsException $e) {
            return ['error' => $e->getMessage(), 'rows' => [], 'hasMore' => false];
        }

        $rows = array_map(fn ($receipt) => [
            'receipt' => $receipt,
            'match' => $matcher->match($receipt->uno, $receipt->lot, $receipt->serial, $receipt->documentNo),
        ], $result->items);

        return ['error' => null, 'rows' => $rows, 'hasMore' => $result->hasMore];
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">ÜTS — Kabul Bekleyenler</h1>
        <p class="text-[14px] text-ink-muted mt-1">Tedarikçilerin kurumunuza ÜTS'de bildirdiği, henüz kabul edilmemiş ürünler. Bu ekran yalnızca görüntüler; ÜTS'ye bildirim göndermez.</p>
        <nav class="flex gap-4 mt-4 text-[14px]">
            <a href="{{ route('uts.pending') }}" class="font-medium text-ink border-b-2 border-brand-500 pb-1">Kabul Bekleyenler</a>
            <a href="{{ route('uts.verify') }}" class="text-ink-muted hover:text-ink pb-1">Lot/Seri Doğrula</a>
        </nav>
    </div>

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.500ms="sender" inputmode="numeric" placeholder="Gönderen kurum kodu (isteğe bağlı)" class="border border-line rounded-md px-3 py-2 text-[14px] w-full max-w-xs">
    </div>

    @if ($error)
        <div class="rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3">
            {{ $error }}
            @can('system_settings.viewAny')
                <a href="{{ route('settings.index') }}" class="underline ml-1">Ayarlar</a>
            @endcan
        </div>
    @elseif (count($rows) === 0)
        <div class="border border-line rounded-lg bg-surface px-5 py-8 text-center text-[14px] text-ink-muted">Kabul bekleyen bildirim yok.</div>
    @else
        <div class="overflow-x-auto border border-line rounded-lg bg-surface">
            <table class="w-full text-[13px]">
                <thead class="text-left text-ink-muted border-b border-line">
                    <tr>
                        <th class="px-4 py-3 font-medium">Ürün</th>
                        <th class="px-4 py-3 font-medium">Lot / Seri</th>
                        <th class="px-4 py-3 font-medium text-right">Adet</th>
                        <th class="px-4 py-3 font-medium">Belge no</th>
                        <th class="px-4 py-3 font-medium">Gönderen</th>
                        <th class="px-4 py-3 font-medium">Zaman</th>
                        <th class="px-4 py-3 font-medium">Eşleşme</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($r = $row['receipt'])
                        @php($m = $row['match'])
                        <tr class="border-b border-line last:border-0 align-top">
                            <td class="px-4 py-3">
                                <div class="text-ink">{{ $m['product']?->name ?? $r->brand ?? '—' }}</div>
                                <div class="text-ink-muted">{{ $r->uno }}</div>
                            </td>
                            <td class="px-4 py-3">{{ $r->lot ?? '—' }}@if ($r->serial) <div class="text-ink-muted">{{ $r->serial }}</div>@endif</td>
                            <td class="px-4 py-3 text-right">{{ $r->quantity ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $r->documentNo ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $r->senderName ?? '—' }}@if ($r->senderCode) <div class="text-ink-muted">{{ $r->senderCode }}</div>@endif</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->notifiedAt ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @if ($m['product'])
                                        <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Ürün bizde var</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 text-amber-700 px-2 py-0.5 text-[12px]">Ürün bizde yok</span>
                                    @endif
                                    @if ($m['lot']) <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Lot kayıtlı</span> @endif
                                    @if ($m['serial']) <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Seri kayıtlı</span> @endif
                                    @if ($m['receipt']) <a href="{{ route('purchasing.show', $m['receipt']->purchase_order_id) }}" class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px] underline">Teslim kaydı var</a> @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between mt-4 text-[13px]">
            <button wire:click="previousPage" @disabled($page === 0) class="border border-line rounded-md px-3 py-1.5 disabled:opacity-40">Önceki</button>
            <span class="text-ink-muted">Sayfa {{ $page + 1 }}</span>
            <button wire:click="nextPage" @disabled(! $hasMore) class="border border-line rounded-md px-3 py-1.5 disabled:opacity-40">Sonraki</button>
        </div>
    @endif
</div>
```

- [ ] **Step 8: Yeşil + gözle doğrula**

Run: `php artisan test tests/Feature/Uts`
Expected: PASS

`npm run build`; yerelde `FakeUtsClient` ile (geçici bir `tinker`/yerel binding değil, testler yeşilken) sayfayı tarayıcıda aç ve ekran görüntüsünü kontrol et: rozetler, tablo taşması (telefon genişliği), hata/boş durumlar. Projede olmayan Tailwind renk sınıfları (`bg-amber-100` vb.) varsa mevcut sayfalardaki durum renkleriyle (`status-*`) değiştir.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add app routes resources tests
git commit -m "feat: ÜTS Kabul Bekleyenler ekranı — bekleyen Verme bildirimleri ürün/lot/seri/teslim eşleştirmesiyle — Aşama 33 (5/6)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Lot/Seri Doğrula ekranı

**Files:**
- Create: `resources/views/pages/uts/⚡verify.blade.php`
- Test: `tests/Feature/Uts/UtsVerifyScreenTest.php`

**Interfaces:**
- Consumes: `UtsClient::lookup`, `UtsMatcher::match`, `Gs1::parse` (`gtin`, `lot_no`, `expiry_date` `Y-m-d`, `serial`), `UtsItem`, route `uts.verify`.
- Produces: Livewire eylemi `check()`; özellikler `scan`, `uno`, `lot`, `serial`.

- [ ] **Step 1: Failing test yaz**

```php
<?php

namespace Tests\Feature\Uts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Stock\Services\StockMovementService;
use App\Domain\Uts\Clients\FakeUtsClient;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\UtsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UtsVerifyScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Merkez', 'status' => 'active']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Depo', 'is_default' => true, 'status' => 'active']);
        $product = Product::create(['organization_id' => $org->id, 'name' => 'Dolgu', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '08699999999990']);
        $this->actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']));
        app(StockMovementService::class)->in($product, $warehouse, 5, ['lot_no' => 'L1', 'expiry_date' => '2028-01-31']);
    }

    private function fake(array $items = [], ?UtsException $failure = null): FakeUtsClient
    {
        $client = new FakeUtsClient(items: $items, failure: $failure);
        $this->app->bind(UtsClient::class, fn () => $client);

        return $client;
    }

    public function test_verifies_a_lot_and_shows_matching_expiry(): void
    {
        $this->fake([UtsItem::fromArray(['UTP' => 'TIBBI_CIHAZ', 'UNO' => '08699999999990', 'LNO' => 'L1', 'ADT' => 5, 'SKT' => '2028-01-31', 'UAK' => 'LOT', 'MME' => 'Marka'])]);

        Livewire::test('pages::uts.verify')
            ->set('uno', '08699999999990')
            ->set('lot', 'L1')
            ->call('check')
            ->assertSee('ÜTS\'de kayıtlı')
            ->assertSee('31.01.2028')
            ->assertSee('SKT uyuşuyor');
    }

    public function test_flags_expiry_mismatch(): void
    {
        $this->fake([UtsItem::fromArray(['UNO' => '08699999999990', 'LNO' => 'L1', 'SKT' => '2027-06-30'])]);

        Livewire::test('pages::uts.verify')->set('uno', '08699999999990')->set('lot', 'L1')->call('check')->assertSee('SKT uyuşmuyor');
    }

    public function test_reports_when_uts_has_no_record(): void
    {
        $this->fake([]);

        Livewire::test('pages::uts.verify')->set('uno', '08699999999990')->set('lot', 'YOK')->call('check')->assertSee('ÜTS\'de kayıt bulunamadı');
    }

    public function test_gs1_scan_fills_the_fields(): void
    {
        $client = $this->fake([]);

        Livewire::test('pages::uts.verify')
            ->set('scan', '(01)08699999999990(17)280131(10)L1(21)S9')
            ->assertSet('uno', '08699999999990')
            ->assertSet('lot', 'L1')
            ->assertSet('serial', 'S9')
            ->call('check');

        $this->assertSame(['lookup', ['08699999999990', 'L1', 'S9']], $client->calls[0]);
    }

    public function test_requires_a_product_number(): void
    {
        $this->fake();

        Livewire::test('pages::uts.verify')->set('uno', '')->call('check')->assertHasErrors(['uno']);
    }

    public function test_shows_uts_errors(): void
    {
        $this->fake(failure: new UtsException('ÜTS şu an yanıt vermiyor.'));

        Livewire::test('pages::uts.verify')->set('uno', '1')->call('check')->assertSee('ÜTS şu an yanıt vermiyor.');
    }
}
```

- [ ] **Step 2: Düştüğünü gör**

Run: `php artisan test tests/Feature/Uts/UtsVerifyScreenTest.php`
Expected: FAIL (component yok)

- [ ] **Step 3: Sayfayı yaz**

```blade
<?php

use App\Domain\Catalog\Support\Gs1;
use App\Domain\Uts\Contracts\UtsClient;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Services\UtsMatcher;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Bir lot/seri numarasını ÜTS'de sorgular ve yerel kayıtla karşılaştırır
 * (Aşama 33, salt okunur). GS1 DataMatrix okutulursa alanlar dolar.
 */
new #[Layout('layouts::authenticated')] class extends Component
{
    public string $scan = '';

    public string $uno = '';

    public string $lot = '';

    public string $serial = '';

    /** @var array<int, array<string, mixed>>|null */
    public ?array $results = null;

    public ?string $error = null;

    public function updatedScan(): void
    {
        if ($gs1 = Gs1::parse($this->scan)) {
            $this->uno = $gs1['gtin'];
            $this->lot = (string) ($gs1['lot_no'] ?? '');
            $this->serial = (string) ($gs1['serial'] ?? '');
            $this->scan = '';
            $this->check();
        }
    }

    public function check(): void
    {
        $this->validate(['uno' => ['required', 'string', 'max:23'], 'lot' => ['nullable', 'string', 'max:36'], 'serial' => ['nullable', 'string', 'max:36']]);

        $this->error = null;
        $this->results = null;

        try {
            $items = app(UtsClient::class)->lookup(trim($this->uno), trim($this->lot) ?: null, trim($this->serial) ?: null);
        } catch (UtsException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $matcher = app(UtsMatcher::class);

        $this->results = array_map(function ($item) use ($matcher) {
            $match = $matcher->match($item->uno, $item->lot, $item->serial);
            $localExpiry = $match['lot']?->expiry_date?->toDateString();
            $utsExpiry = $this->normalizeDate($item->expiryDate);

            return [
                'uno' => $item->uno,
                'lot' => $item->lot,
                'serial' => $item->serial,
                'quantity' => $item->quantity,
                'brand' => $item->brand,
                'type' => $item->productType,
                'tracking' => $item->tracking,
                'udi' => $item->udi,
                'expiry' => $utsExpiry ? Carbon::parse($utsExpiry)->format('d.m.Y') : null,
                'product' => $match['product']?->name,
                'lotKnown' => $match['lot'] !== null,
                'serialKnown' => $match['serial'] !== null,
                'expiryMatches' => $localExpiry && $utsExpiry ? $localExpiry === $utsExpiry : null,
            ];
        }, $items);
    }

    /**
     * ÜTS tarihleri "2028-01-31" ya da "31.01.2028" gelebilir; Y-m-d'ye çevrilir.
     */
    private function normalizeDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
};
?>

<div>
    <div class="mb-6">
        <h1 class="text-[22px] font-medium tracking-tight text-ink">ÜTS — Lot/Seri Doğrula</h1>
        <p class="text-[14px] text-ink-muted mt-1">Bir ürünün lot veya seri numarasını ÜTS'de sorgulayın ve kendi kayıtlarınızla karşılaştırın. Kutudaki kare kodu okutabilirsiniz.</p>
        <nav class="flex gap-4 mt-4 text-[14px]">
            <a href="{{ route('uts.pending') }}" class="text-ink-muted hover:text-ink pb-1">Kabul Bekleyenler</a>
            <a href="{{ route('uts.verify') }}" class="font-medium text-ink border-b-2 border-brand-500 pb-1">Lot/Seri Doğrula</a>
        </nav>
    </div>

    <form wire:submit="check" class="max-w-2xl space-y-3 mb-6">
        <input type="text" wire:model.live.debounce.300ms="scan" autofocus placeholder="Kare kodu okutun (GS1 DataMatrix)" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
        <div class="grid sm:grid-cols-3 gap-3">
            <div>
                <input type="text" wire:model="uno" placeholder="Ürün no / GTIN" class="w-full border border-line rounded-md px-3 py-2 text-[14px]">
                @error('uno') <span class="text-status-critical text-[12px]">{{ $message }}</span> @enderror
            </div>
            <input type="text" wire:model="lot" placeholder="Lot no" class="border border-line rounded-md px-3 py-2 text-[14px]">
            <input type="text" wire:model="serial" placeholder="Seri no" class="border border-line rounded-md px-3 py-2 text-[14px]">
        </div>
        <button type="submit" class="bg-panel-900 text-white rounded-md px-4 py-2 text-[14px] font-medium hover:bg-panel-800 transition-colors">ÜTS'de Sorgula</button>
    </form>

    @if ($error)
        <div class="rounded-md bg-red-50 border border-status-critical/30 text-status-critical text-[13px] px-4 py-3 max-w-2xl">{{ $error }}</div>
    @elseif ($results !== null)
        @forelse ($results as $result)
            <div class="border border-line rounded-lg bg-surface p-5 max-w-2xl mb-3">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">ÜTS'de kayıtlı</span>
                    @if ($result['product']) <span class="text-[14px] text-ink">{{ $result['product'] }}</span> @else <span class="rounded-full bg-amber-100 text-amber-700 px-2 py-0.5 text-[12px]">Ürün bizde yok</span> @endif
                </div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-[13px]">
                    <dt class="text-ink-muted">Marka/model</dt><dd>{{ $result['brand'] ?? '—' }}</dd>
                    <dt class="text-ink-muted">Ürün no</dt><dd>{{ $result['uno'] }}</dd>
                    <dt class="text-ink-muted">Lot / Seri</dt><dd>{{ $result['lot'] ?? '—' }} {{ $result['serial'] ? '/ '.$result['serial'] : '' }}</dd>
                    <dt class="text-ink-muted">Adet</dt><dd>{{ $result['quantity'] ?? '—' }}</dd>
                    <dt class="text-ink-muted">Takip tipi</dt><dd>{{ $result['tracking'] ?? '—' }}</dd>
                    <dt class="text-ink-muted">SKT (ÜTS)</dt><dd>{{ $result['expiry'] ?? '—' }}</dd>
                    <dt class="text-ink-muted">UDI</dt><dd class="break-all">{{ $result['udi'] ?? '—' }}</dd>
                </dl>
                <div class="flex flex-wrap gap-1 mt-3">
                    @if ($result['lotKnown']) <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Lot bizde kayıtlı</span> @endif
                    @if ($result['serialKnown']) <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">Seri bizde kayıtlı</span> @endif
                    @if ($result['expiryMatches'] === true) <span class="rounded-full bg-brand-100 text-brand-600 px-2 py-0.5 text-[12px]">SKT uyuşuyor</span> @endif
                    @if ($result['expiryMatches'] === false) <span class="rounded-full bg-red-100 text-status-critical px-2 py-0.5 text-[12px]">SKT uyuşmuyor</span> @endif
                </div>
            </div>
        @empty
            <div class="border border-line rounded-lg bg-surface px-5 py-8 text-center text-[14px] text-ink-muted max-w-2xl">ÜTS'de kayıt bulunamadı. Numaraları kontrol edin; ürün kurumunuza henüz verilmemiş olabilir.</div>
        @endforelse
    @endif
</div>
```

- [ ] **Step 4: Yeşil + gözle doğrula**

Run: `php artisan test tests/Feature/Uts`
Expected: PASS

`npm run build`, tarayıcıda sayfayı aç, telefon genişliğinde de kontrol et.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add resources tests
git commit -m "feat: ÜTS Lot/Seri Doğrula ekranı — GS1 okutma, ÜTS sorgusu ve yerel kayıtla SKT karşılaştırması — Aşama 33 (6/6)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Kabul testi, belge ve gerçek ÜTS test ortamında doğrulama

**Files:**
- Create: `tests/Feature/Acceptance/UtsInquiryAcceptanceTest.php`, `docs/uts.md`
- Modify: `README.md` (belgeler listesine bir satır)

**Interfaces:**
- Consumes: tüm önceki task'ların çıktıları.

- [ ] **Step 1: Uçtan uca kabul testi**

```php
<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Catalog\Models\Product;
use App\Domain\Organization\Models\Organization;
use App\Domain\Uts\Support\PendingReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aşama 33 kabul: token gir → bağlantıyı dene → bekleyenleri gör → lot doğrula.
 * Gerçek HttpUtsClient, ÜTS Http::fake ile taklit edilir.
 */
class UtsInquiryAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_connects_then_sees_pending_and_verifies_a_lot(): void
    {
        $org = Organization::create(['name' => 'Klinik', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_ADMIN, 'status' => 'active']);
        Product::create(['organization_id' => $org->id, 'name' => 'Implant X', 'base_unit' => 'Adet', 'status' => 'active', 'gtin' => '08699999999990']);
        $this->actingAs($admin);

        Http::fake([
            '*/bildirim/alma/bekleyenler/sorgula' => Http::response(['SNC' => [
                ['GKK' => 7, 'UNO' => '08699999999990', 'LNO' => 'L1', 'ADT' => 2, 'BNO' => 'IRS-1', 'GKU' => 'Tedarik A.Ş.'],
            ]]),
            '*/tekilUrun/sorgula' => Http::response(['SNC' => [
                ['UTP' => 'TIBBI_CIHAZ', 'UNO' => '08699999999990', 'LNO' => 'L1', 'ADT' => 2, 'SKT' => '2028-01-31'],
            ]]),
        ]);

        Livewire::test('pages::settings.index')->set('utsEnvironment', 'test')->set('utsToken', 'tok')->call('saveUts')->call('testUts')->assertSee('Bağlantı başarılı');

        Livewire::test('pages::uts.pending')->assertSee('IRS-1')->assertSee('Ürün bizde var');

        Livewire::test('pages::uts.verify')->set('uno', '08699999999990')->set('lot', 'L1')->call('check')->assertSee('ÜTS\'de kayıtlı');

        // Bu aşama ÜTS'ye hiçbir şey yazmaz: yalnızca iki sorgu servisi çağrıldı.
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/bildirim/alma/ekle'));
    }
}
```

> `Http::assertSentCount(4)`: bağlantı testi (1) + bekleyenler (1) + doğrula (1) + sayfa render'ında `with()` bir kez daha çalışıyorsa sayı farklı çıkabilir; ilk çalıştırmada gerçek sayıyı gözleyip testi o sayıya göre sabitle, ama "yazma uç noktası çağrılmadı" kontrolü kalsın.

Run: `php artisan test tests/Feature/Acceptance/UtsInquiryAcceptanceTest.php` → PASS.

- [ ] **Step 2: `docs/uts.md` yaz**

İçerik (Türkçe, kısa): ÜTS nedir; token nasıl üretilir (utsuygulama portalı → Kullanıcı → Sistem Kullanıcısı Tanımlama İşlemleri, e-imza; test için utstest); Ayarlar'da girme; iki ekranın ne yaptığı; bu aşamada ÜTS'ye **yazılmadığı**; sorun giderme (token geçersiz, ÜTS yanıt vermiyor); Aşama B'nin kapsamı. README belgeler listesine `docs/uts.md` satırı.

- [ ] **Step 3: Gerçek test ortamında elle doğrulama (kullanıcıyla)**

Kullanıcı ÜTS **test** ortamı token'ını üretip Ayarlar'a girer (token sohbette paylaşılmaz). Sonra, Task 2'deki varsayımları gerçek yanıtlarla karşılaştır:

1. `Bağlantıyı dene` başarılı mı? `GKK` olmadan `bekleyenler/sorgula` çalışıyor mu (spec açık nokta 1, 2)?
2. Gerçek yanıt `SNC` listesi mi, yoksa sarmalanmış bir nesne mi? Sayfalama için toplam/ileri bilgisi dönüyor mu (hasMore sezgisi `count >= 10`)?
3. `UNO` biçimi (14 haneli GTIN mi, başı sıfır dolgulu mu) — Matcher eşleşiyor mu (açık nokta 3)?
4. Hata/yetki yanıtları: yanlış token → HTTP kodu ve `MSJ` biçimi.

Farklı çıkan her şey için önce `HttpUtsClientTest`'e gerçek yanıtla başarısız test ekle, sonra `HttpUtsClient`'ı düzelt (ayrı commit: `fix: ÜTS gerçek yanıt biçimine uyum — ...`). Spec'teki açık noktaları sonuçlara göre güncelle.

- [ ] **Step 4: Tüm testler, Pint, frontend derlemesi**

Run: `php artisan test && vendor/bin/pint --test && npm run build`
Expected: hepsi yeşil (önceki 529 test + yeni testler).

- [ ] **Step 5: Aşama raporu ve commit**

```bash
git add tests docs README.md
git commit -m "feat: ÜTS sorgulama kabul testi ve belgesi — Aşama 33 tamamlandı

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

Ardından NotebookLM defterine (`0520342d-b8ff-4f44-937f-35bad9721566`) `asama-33-rapor.md — ÜTS Sorgulama (Aşama A)` adlı metin kaynağı ekle: ne yapıldı, tasarım kararları ve nedenleri, testler, bulunan hatalar, bilinen eksikler (Aşama B), sonraki aşama. `bilinen-eksikler.md` ve `karar-kaydi.md` içindeki "ÜTS web servisine bildirim yapılmıyor" maddesini "okuma yapıldı, yazma Aşama B" olarak güncelleyecek not düş. GitHub'a push **yalnızca kullanıcı onayıyla**.

---

## Self-Review

**Spec kapsamı:** İstemci arayüzü/uygulamaları → T1–T2; token saklama, yetki, şifreleme → T3; Ayarlar kartı → T4; Kabul Bekleyenler + eşleştirme rozetleri (ürün/lot/seri/teslim) + filtre + sayfalama → T5; Doğrula + GS1 + SKT karşılaştırma → T6; hata/boş durumlar → T4–T6 testleri; test stratejisi ve gerçek doğrulama + açık noktalar → T7. Spec'teki "Bekleyen siparişte" rozeti teslim kaydı (belge no) eşleşmesi olarak gerçeklendi (`Teslim kaydı var`).

**Yer tutucu taraması:** Kodu kullanıcıya bırakılmış adım yok. İki not (T3 rol/durum sabitleri, T5 seri parametresi anahtarı, T7 `assertSentCount`) gerçek kodla eşleştirme talimatıdır ve nereden bakılacağını söyler; ayrıca T4/T5'teki Tailwind sınıf uyarısı görsel doğrulamaya bağlıdır.

**Tip tutarlılığı:** `UtsClient::pendingReceipts(?int, int)`, `lookup(string, ?string, ?string)`; `PendingReceipt`/`UtsItem` alan adları T1'de tanımlı ve T2, T5, T6'da aynı kullanılıyor; `UtsMatcher::match` dönüş anahtarları (`product, lot, serial, receipt`) T5 ve T6'da aynı; `utsEnvironment/utsToken` T4 ve T7'de aynı.
