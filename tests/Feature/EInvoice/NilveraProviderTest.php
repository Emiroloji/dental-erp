<?php

namespace Tests\Feature\EInvoice;

use App\Domain\EInvoice\Clients\NilveraProvider;
use App\Domain\EInvoice\Exceptions\EInvoiceAuthException;
use App\Domain\EInvoice\Exceptions\EInvoiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NilveraProviderTest extends TestCase
{
    private function provider(): NilveraProvider
    {
        return new NilveraProvider('gizli-anahtar', 'https://apitest.nilvera.com', 10);
    }

    public function test_incoming_lists_purchase_invoices_since_a_date(): void
    {
        Http::fake(['apitest.nilvera.com/*' => Http::response([
            'Page' => 1, 'PageSize' => 50, 'TotalCount' => 51, 'TotalPages' => 2,
            'Content' => [['UUID' => 'u-1', 'InvoiceNumber' => 'ADM2026000451', 'SenderTaxNumber' => '1234567890', 'SenderName' => 'Anadolu Dental', 'IssueDate' => '2026-10-17T00:00:00']],
        ])]);

        $page = $this->provider()->incoming(Carbon::parse('2026-10-01 10:00'), 1);

        $this->assertSame('u-1', $page->items[0]->uuid);
        $this->assertSame('ADM2026000451', $page->items[0]->number);
        $this->assertTrue($page->hasMore);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://apitest.nilvera.com/einvoice/Purchase?')
            && $r->header('Authorization') === ['Bearer gizli-anahtar']
            && $r['Page'] == 1
            && $r['PageSize'] == 50
            && $r['DateFilterType'] === 'CreatedDate'
            && str_starts_with($r['StartDate'], '2026-10-01'));
    }

    public function test_last_page_has_no_more(): void
    {
        Http::fake(['*' => Http::response(['Page' => 2, 'PageSize' => 50, 'TotalPages' => 2, 'Content' => []])]);

        $this->assertFalse($this->provider()->incoming(now(), 2)->hasMore);
    }

    public function test_xml_accepts_raw_json_string_and_base64_bodies(): void
    {
        $xml = '<?xml version="1.0"?><Invoice/>';

        Http::fake(['*/u-raw/xml' => Http::response($xml), '*/u-json/xml' => Http::response(json_encode($xml)), '*/u-b64/xml' => Http::response(base64_encode($xml))]);

        $this->assertSame($xml, $this->provider()->xml('u-raw'));
        $this->assertSame($xml, $this->provider()->xml('u-json'));
        $this->assertSame($xml, $this->provider()->xml('u-b64'));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://apitest.nilvera.com/einvoice/Purchase/u-raw/xml');
    }

    public function test_pdf_accepts_raw_and_base64(): void
    {
        Http::fake(['*/u-raw/pdf' => Http::response('%PDF-1.4 x'), '*/u-b64/pdf' => Http::response(json_encode(base64_encode('%PDF-1.4 y')))]);

        $this->assertSame('%PDF-1.4 x', $this->provider()->pdf('u-raw'));
        $this->assertSame('%PDF-1.4 y', $this->provider()->pdf('u-b64'));
    }

    public function test_unrecognised_xml_body_is_an_error(): void
    {
        Http::fake(['*' => Http::response('bu bir fatura değil')]);

        $this->expectException(EInvoiceException::class);

        $this->provider()->xml('u-1');
    }

    public function test_unauthorized_key_is_reported_without_leaking_it(): void
    {
        Http::fake(['*' => Http::response(['Message' => 'Unauthorized'], 401)]);

        try {
            $this->provider()->incoming(now(), 1);
            $this->fail('Exception bekleniyordu.');
        } catch (EInvoiceAuthException $e) {
            $this->assertStringNotContainsString('gizli-anahtar', $e->getMessage());
            $this->assertStringContainsString('API anahtarı', $e->getMessage());
        }
    }

    public function test_error_detail_is_shown(): void
    {
        Http::fake(['*' => Http::response(['Message' => 'HATALI ISTEK', 'Errors' => [['Code' => 400, 'Description' => 'StartDate', 'Detail' => 'Tarih aralığı en fazla 1 yıl olabilir.']]], 400)]);

        $this->expectException(EInvoiceException::class);
        $this->expectExceptionMessage('Tarih aralığı en fazla 1 yıl olabilir.');

        $this->provider()->incoming(now()->subYears(2), 1);
    }

    public function test_connection_failure_is_reported_in_turkish(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(EInvoiceException::class);
        $this->expectExceptionMessage('e-Fatura servisi şu an yanıt vermiyor');

        $this->provider()->incoming(now(), 1);
    }
}
