<?php

namespace Tests\Feature\Uts;

use App\Domain\Uts\Clients\HttpUtsClient;
use App\Domain\Uts\Exceptions\UtsAuthException;
use App\Domain\Uts\Exceptions\UtsException;
use App\Domain\Uts\Support\UsageReport;
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

    public function test_receive_posts_alma_with_notification_id_and_returns_new_code(): void
    {
        Http::fake(['utstest.saglik.gov.tr/*' => Http::response([
            'SNC' => '15713f21-f7d8-11e8-a3a9-f783d4ccac99',
            'MSJ' => [['TIP' => 'BILGI', 'KOD' => 'UTS-B006', 'MET' => 'Alma bildirimi yapıldı.']],
        ])]);

        $code = $this->client()->receive('96be8de1-f7d7-11e8-a3a9-f783d4ccac99', 5);

        $this->assertSame('15713f21-f7d8-11e8-a3a9-f783d4ccac99', $code);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://utstest.saglik.gov.tr/UTS/uh/rest/bildirim/alma/ekle'
            && $r->header('utsToken') === ['gizli-token']
            && $r['VBI'] === '96be8de1-f7d7-11e8-a3a9-f783d4ccac99'
            && $r['ADT'] === 5);
    }

    public function test_receive_omits_quantity_when_not_given(): void
    {
        Http::fake(['*' => Http::response(['SNC' => 'yeni-kod'])]);

        $this->client()->receive('vbi-1');

        Http::assertSent(fn (Request $r) => ! array_key_exists('ADT', $r->data()));
    }

    public function test_receive_without_result_code_is_an_error(): void
    {
        Http::fake(['*' => Http::response(['MSJ' => []])]);

        $this->expectException(UtsException::class);

        $this->client()->receive('vbi-1');
    }

    public function test_receive_shows_uts_error_message(): void
    {
        Http::fake(['*' => Http::response(['MSJ' => [['TIP' => 'HATA', 'KOD' => 'UTS-H171', 'MET' => 'Verilen Tekil Ürün bilgilerine uyan birden fazla bildirim bulundu!']]])]);

        $this->expectException(UtsException::class);
        $this->expectExceptionMessage('birden fazla bildirim');

        $this->client()->receive('vbi-1');
    }

    public function test_report_usage_posts_kullanim_with_patient_identity(): void
    {
        Http::fake(['utstest.saglik.gov.tr/*' => Http::response(['SNC' => 'kullanim-kodu-1'])]);

        $code = $this->client()->reportUsage(new UsageReport(
            uno: '08699999999994', lot: null, serial: 'SN-1', quantity: null, usedOn: '2026-10-18',
            firstName: 'Ayşe', lastName: 'Yılmaz', identityCode: 'TC_KIMLIK_NUMARASI_VAR', identityField: 'TKN', identityNo: '10000000146', personNo: 12,
        ));

        $this->assertSame('kullanim-kodu-1', $code);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://utstest.saglik.gov.tr/UTS/uh/rest/bildirim/kullanim/ekle'
            && $r->data() === ['UNO' => '08699999999994', 'SNO' => 'SN-1', 'GIT' => '2026-10-18', 'HAA' => 'Ayşe', 'HAS' => 'Yılmaz', 'TUR' => 'TC_KIMLIK_NUMARASI_VAR', 'TKN' => '10000000146']);
    }

    public function test_report_usage_for_lot_and_unknown_identity(): void
    {
        Http::fake(['*' => Http::response(['SNC' => 'kod'])]);

        $this->client()->reportUsage(new UsageReport(
            uno: '08699999999994', lot: 'L1', serial: null, quantity: 2, usedOn: '2026-10-18',
            firstName: 'Bilinmeyen', lastName: 'Hasta', identityCode: 'KIMLIGI_BELIRSIZ', identityField: null, identityNo: null, personNo: 12,
        ));

        Http::assertSent(fn (Request $r) => $r->data() === ['UNO' => '08699999999994', 'LNO' => 'L1', 'ADT' => 2, 'GIT' => '2026-10-18', 'HAA' => 'Bilinmeyen', 'HAS' => 'Hasta', 'TUR' => 'KIMLIGI_BELIRSIZ', 'KTN' => 12]);
    }

    public function test_cancel_usage_posts_bid(): void
    {
        Http::fake(['*' => Http::response(['SNC' => 'iptal-kodu'])]);

        $this->assertSame('iptal-kodu', $this->client()->cancelUsage('kullanim-kodu-1'));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://utstest.saglik.gov.tr/UTS/uh/rest/bildirim/kullanim/iptal'
            && $r->data() === ['BID' => 'kullanim-kodu-1']);
    }

    public function test_pending_receipts_can_filter_by_document_number(): void
    {
        Http::fake(['*' => Http::response(['SNC' => []])]);

        $this->client()->pendingReceipts(null, 0, 'ADM2026000451');

        Http::assertSent(fn (Request $r) => $r['BNO'] === 'ADM2026000451');
    }

    public function test_decline_marks_the_notification_as_not_wanted(): void
    {
        Http::fake(['*' => Http::response(['MSJ' => [['TIP' => 'BILGI', 'KOD' => 'UTS-B000', 'MET' => 'İşlem başarılı.']]])]);

        $this->client()->decline('vbi-1');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://utstest.saglik.gov.tr/UTS/uh/rest/almakIstemiyorum/almakIstemiyorumOlarakIsaretle'
            && $r->data() === ['BID' => 'vbi-1']);
    }
}
