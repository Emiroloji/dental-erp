<?php

namespace Tests\Feature\Uts;

use App\Domain\Uts\Clients\HttpUtsClient;
use App\Domain\Uts\Exceptions\UtsAuthException;
use App\Domain\Uts\Exceptions\UtsException;
use Illuminate\Http\Client\ConnectionException;
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
            ['GKK' => 7, 'UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 2, 'BNO' => 'IRS-1'],
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
            ['UTP' => 'TIBBI_CIHAZ', 'UNO' => '08699999999994', 'LNO' => 'L1', 'SKT' => '2028-01-31', 'UAK' => 'LOT'],
        ]])]);

        $items = $this->client()->lookup('08699999999994', 'L1');

        $this->assertCount(1, $items);
        $this->assertSame('2028-01-31', $items[0]->expiryDate);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/UTS/uh/rest/tekilUrun/sorgula')
            && $r['UNO'] === '08699999999994' && $r['LNO'] === 'L1' && ! isset($r['SNO']) && $r['SAN'] === 0);
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
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('ÜTS şu an yanıt vermiyor');

        $this->client()->pendingReceipts();
    }
}
